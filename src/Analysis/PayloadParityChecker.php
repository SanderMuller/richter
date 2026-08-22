<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

use SanderMuller\Richter\Graph\CodeGraph;
use SanderMuller\Richter\Tracers\EagerLoadStringChecker;
use SanderMuller\Richter\Tracers\ReferenceEdgeTracer;

/**
 * Advisory: a model field added to `$fillable`/`$casts`/`casts()` but never added to a resource that
 * otherwise mirrors the model's other fields — the exact shape behind a payload field silently going
 * missing (Brain has no notion of API resources; {@see ReferenceEdgeTracer}
 * maps a resource reference to a class-level edge and nothing more). Findings only — never `risk`,
 * `--fail-on`, or `affected-tests`. Deliberately a no-guess check: an unparseable resource, a dynamic
 * `toArray()` key, or a candidate below the mirror threshold is silently skipped rather than guessed at.
 *
 * Non-readonly like {@see EagerLoadStringChecker}: it memoizes each
 * resource file's parsed key set for the run's lifetime.
 */
final class PayloadParityChecker
{
    /** @var array<string, list<string>|null> path => resolved toArray() keys, null meaning "skip this resource" */
    private array $keysCache = [];

    /** Which resources belong to a model, and whether wiring or a name said so. */
    private readonly ModelResources $resources;

    /**
     * @param  float  $mirrorThreshold  fraction of a candidate's PRE-EXISTING fields it must mirror to count as a mirror
     * @param  list<string>  $ignore  `App\Models\X::field` or resource FQCN entries, from richter.payload_parity.ignore
     * @param  string|null  $projectRoot  overrides base_path() for tests; resource files are read relative to it
     */
    public function __construct(
        CodeGraph $graph,
        private readonly float $mirrorThreshold = 1.0,
        private readonly array $ignore = [],
        private readonly ?string $projectRoot = null,
    ) {
        $this->resources = new ModelResources($graph, $projectRoot);
    }

    /**
     * @param  list<string>  $fieldSet  the model's full head-side field union
     * @param  list<string>  $addedFields  the subset of `$fieldSet` this diff added
     * @return list<string> advisory findings, each already resource-path- and field-named — no model-file prefix
     */
    public function findingsFor(string $modelFqcn, array $fieldSet, array $addedFields): array
    {
        if ($addedFields === []) {
            return [];
        }

        $ignoredFields = $this->ignoredFieldsFor($modelFqcn);
        $addedFields = array_values(array_diff($addedFields, $ignoredFields));

        if ($addedFields === []) {
            return [];
        }

        // The mirror gate's denominator: everything the model exposed before this diff, minus
        // whatever the operator has opted the field out of the check entirely.
        $preExisting = array_values(array_diff($fieldSet, $addedFields, $ignoredFields));

        if ($preExisting === []) {
            return [];
        }

        ['candidates' => $candidates, 'viaGraph' => $viaGraph] = $this->resources->candidatesFor($modelFqcn);
        // Wiring is independent evidence the two belong together; a name match on an empty graph
        // result is not — hence the stricter shared-field minimum on that path.
        $minimumShared = $viaGraph ? 1 : 2;

        $findings = [];

        foreach ($candidates as $candidate) {
            if (in_array($candidate['fqcn'], $this->ignore, strict: true)) {
                continue;
            }

            $keys = $this->keysFor($candidate['path']);

            if ($keys === null) {
                continue;
            }

            $shared = array_values(array_intersect($preExisting, $keys));

            if (count($shared) < $minimumShared) {
                continue;
            }

            if (count($shared) / count($preExisting) < $this->mirrorThreshold) {
                continue;
            }

            $missing = array_values(array_diff($addedFields, $keys));

            if ($missing === []) {
                continue;
            }

            $findings[] = sprintf(
                '%s mirrors %s but does not expose %s added to %s',
                $candidate['path'],
                $modelFqcn,
                implode(', ', $missing),
                $modelFqcn,
            );
        }

        return $findings;
    }

    /** @return list<string> */
    private function ignoredFieldsFor(string $modelFqcn): array
    {
        $prefix = "{$modelFqcn}::";
        $fields = [];

        foreach ($this->ignore as $entry) {
            if (str_starts_with($entry, $prefix)) {
                $fields[] = substr($entry, strlen($prefix));
            }
        }

        return $fields;
    }

    /**
     * The resolved `toArray()` string keys for the resource at `$path`, or null when the resource is
     * unreadable, unparseable, or contains a construct that could inject keys this parser cannot
     * enumerate (a spread, `array_merge`, `mergeWhen`, `parent::toArray()`, `only()`) — skip the whole
     * resource rather than report a partial, possibly-wrong key set.
     *
     * @return list<string>|null
     */
    private function keysFor(string $path): ?array
    {
        if (array_key_exists($path, $this->keysCache)) {
            return $this->keysCache[$path];
        }

        return $this->keysCache[$path] = $this->parseKeys($path);
    }

    /**
     * File read here; the AST→keys core lives in {@see ResourceKeyParser::keysOf()}
     * (default mode — this lane's historical behaviour, byte-for-byte).
     *
     * @return list<string>|null
     */
    private function parseKeys(string $path): ?array
    {
        $absolute = $this->projectRoot !== null ? rtrim($this->projectRoot, '/') . '/' . ltrim($path, '/') : base_path($path);

        if (! is_file($absolute)) {
            return null;
        }

        $source = file_get_contents($absolute);

        if ($source === false) {
            return null;
        }

        return ResourceKeyParser::keysOf($source);
    }
}
