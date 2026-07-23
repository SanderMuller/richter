<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;
use SanderMuller\Richter\Graph\CodeGraph;
use SanderMuller\Richter\Support\AppFiles;
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

    /**
     * @param  float  $mirrorThreshold  fraction of a candidate's PRE-EXISTING fields it must mirror to count as a mirror
     * @param  list<string>  $ignore  `App\Models\X::field` or resource FQCN entries, from richter.payload_parity.ignore
     * @param  string|null  $projectRoot  overrides base_path() for tests; resource files are read relative to it
     */
    public function __construct(
        private readonly CodeGraph $graph,
        private readonly float $mirrorThreshold = 1.0,
        private readonly array $ignore = [],
        private readonly ?string $projectRoot = null,
    ) {}

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

        $graphCandidates = $this->graphCandidates($modelFqcn);
        // Wiring is independent evidence the two belong together; a name match on an empty graph
        // result is not — hence the stricter shared-field minimum for the fallback path below.
        $usingFallback = $graphCandidates === [];
        $candidates = $usingFallback ? $this->nameFallbackCandidates($modelFqcn) : $graphCandidates;
        $minimumShared = $usingFallback ? 2 : 1;

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
     * Resources reached from the model's own nodes via {@see CodeGraph::callersOf()} at depth 2, then
     * those callers' own outgoing `resource`-typed edges. Depth 2, not the analyzer's default 6 — the
     * point of preferring wiring over names is locality; a hub model at depth 6 would pull in
     * unrelated features' resources.
     *
     * @return list<array{fqcn: string, path: string}>
     */
    private function graphCandidates(string $modelFqcn): array
    {
        $seeds = $this->graph->nodesContaining(ltrim($modelFqcn, '\\'));

        if ($seeds === []) {
            return [];
        }

        $callerNodes = array_values(array_unique(array_map(
            static fn (array $hop): string => $hop['node'],
            $this->graph->callersOf($seeds, maxDepth: 2),
        )));

        if ($callerNodes === []) {
            return [];
        }

        $resourceFqcns = [];

        foreach ($callerNodes as $node) {
            foreach ($this->graph->dependencyEdgesOf([$node], maxDepth: 1) as $edge) {
                if ($edge['via'] === 'resource') {
                    $resourceFqcns[$edge['target']] = true;
                }
            }
        }

        $candidates = [];

        foreach (array_keys($resourceFqcns) as $fqcn) {
            $location = $this->graph->locationOf($fqcn);

            // No known location means no readable source — silently uncheckable, not a guess.
            if ($location !== null) {
                $candidates[] = ['fqcn' => $fqcn, 'path' => $location['file']];
            }
        }

        return $candidates;
    }

    /**
     * Only reached when the graph gave nothing: resources whose FQCN carries the model's short name
     * as a class-name or namespace segment, under the two namespaces
     * {@see ReferenceEdgeTracer} already treats as resources.
     *
     * @return list<array{fqcn: string, path: string}>
     */
    private function nameFallbackCandidates(string $modelFqcn): array
    {
        $lastSeparator = strrchr($modelFqcn, '\\');
        $shortName = substr($lastSeparator !== false ? $lastSeparator : "\\{$modelFqcn}", 1);
        $projectRoot = rtrim($this->projectRoot ?? base_path(), '/');

        $candidates = [];

        foreach (['app/Http/Resources', 'app/Transformers'] as $relativeDir) {
            foreach (AppFiles::phpClasses("{$projectRoot}/{$relativeDir}", $projectRoot) as $class) {
                if (in_array($shortName, explode('\\', $class['fqcn']), strict: true)) {
                    $candidates[] = ['fqcn' => $class['fqcn'], 'path' => "{$relativeDir}/" . substr($class['path'], strlen("{$projectRoot}/{$relativeDir}/"))];
                }
            }
        }

        return $candidates;
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

    /** @return list<string>|null */
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

        $ast = AppFiles::parseResolved($source);

        if ($ast === null) {
            return null;
        }

        $finder = new NodeFinder();
        /** @var list<ClassMethod> $toArrayMethods */
        $toArrayMethods = array_values(array_filter(
            $finder->findInstanceOf($ast, ClassMethod::class),
            static fn (ClassMethod $method): bool => $method->name->toString() === 'toArray',
        ));

        if (count($toArrayMethods) !== 1) {
            return null;
        }

        $method = $toArrayMethods[0];

        // A plain `return [...];` body only — anything richer (logic before the return, multiple
        // returns) can't be statically enumerated with confidence, so the resource is skipped rather
        // than guessed at.
        if (count($method->stmts ?? []) !== 1 || ! $method->stmts[0] instanceof Return_) {
            return null;
        }

        $return = $method->stmts[0]->expr;

        if (! $return instanceof Array_) {
            return null;
        }

        return $this->keysOfArray($return, $this->classFqcn($ast));
    }

    /** @return list<string>|null */
    private function keysOfArray(Array_ $array, ?string $selfFqcn): ?array
    {
        $keys = [];

        foreach ($array->items as $item) {
            if ($item === null) {
                continue;
            }

            // A spread (`...`) can inject any number of keys this parser cannot enumerate —
            // `array_merge`/`mergeWhen`/nested spreads all take this shape. Abort the whole resource.
            if ($item->unpack) {
                return null;
            }

            if ($item->key === null) {
                continue;
            }

            $resolved = $this->resolveKey($item->key, $selfFqcn);

            // A dynamic key ($variable, concatenation, a function call) is exactly as unenumerable as
            // a spread — the value can be anything (`when()` as a VALUE is fine and counted normally
            // below; it is only the KEY that must be statically known).
            if ($resolved === null) {
                return null;
            }

            $keys[] = $resolved;
        }

        return array_values(array_unique($keys));
    }

    /** A literal string key, or a class-constant key resolved by reflection; null for anything else. */
    private function resolveKey(Node $node, ?string $selfFqcn): ?string
    {
        if ($node instanceof String_) {
            return $node->value;
        }

        if (! $node instanceof ClassConstFetch || ! $node->class instanceof Name || ! $node->name instanceof Identifier) {
            return null;
        }

        $written = $node->class->toString();
        $class = in_array(strtolower($written), ['self', 'static'], true)
            ? $selfFqcn
            : AppFiles::resolveName($node->class);

        return $class === null ? null : AppFiles::stringConstantValue($class, $node->name->toString());
    }

    /** @param  list<Node\Stmt>  $ast */
    private function classFqcn(array $ast): ?string
    {
        /** @var list<ClassLike> $classes */
        $classes = array_values(new NodeFinder()->findInstanceOf($ast, ClassLike::class));

        return count($classes) === 1 ? $classes[0]->namespacedName?->toString() : null;
    }
}
