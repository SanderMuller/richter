<?php declare(strict_types=1);

namespace SanderMuller\Richter\Graph;

use Closure;
use Throwable;

/**
 * Extracts the per-node annotation Richter keeps from a Brain node's data bag: the defining
 * `file`/`line`, a route's `uri`, and Brain's per-route `security` surface (exposure, risk level,
 * issues). Records are sparse arrays — only keys with a real value are present — so the cached
 * graph doesn't carry thousands of nulls. Everything else in the data bag is still discarded;
 * this is annotation for the reports, never input to the impact walk itself.
 *
 * @phpstan-type SecurityIssueShape array{type: string, severity: string, message: string, file?: string, line?: int}
 * @phpstan-type SecurityShape array{exposure: string, riskLevel: string, issues: list<SecurityIssueShape>}
 * @phpstan-type MetadataShape array{file?: string, line?: int, uri?: string, security?: SecurityShape, gates?: list<string>}
 */
final class NodeMetadata
{
    /**
     * @param  array<array-key, mixed>  $data  a Brain node's data bag
     * @return MetadataShape|null null when the bag carries nothing worth keeping
     */
    public static function fromBrainNodeData(array $data, string $projectRoot): ?array
    {
        $metadata = [];

        $file = self::relativeFile($data['file'] ?? null, $projectRoot);

        if ($file !== null) {
            $metadata['file'] = $file;
        }

        if (is_int($data['line'] ?? null) && $data['line'] > 0) {
            $metadata['line'] = $data['line'];
        }

        if (is_string($data['uri'] ?? null) && $data['uri'] !== '') {
            $metadata['uri'] = $data['uri'];
        }

        $security = self::security($data['security'] ?? null, $projectRoot);

        if ($security !== null) {
            $metadata['security'] = $security;
        }

        // Brain never supplies gates — this shapes them through the cache-revalidation round trip.
        $gates = self::stringList($data['gates'] ?? null);

        if ($gates !== []) {
            $metadata['gates'] = $gates;
        }

        return $metadata === [] ? null : $metadata;
    }

    /**
     * Pennant route gating, read off RAW route→middleware edges — the ones whose middleware id
     * still carries its parameters (`middleware::features:x`), before any id normalisation strips
     * them: an `EnsureFeaturesAreActive`-guarded route (string alias or FQCN-string form) records
     * its flag names as `gates` metadata. The `::using()` static-call form is invisible to static
     * route parsing upstream — an honest coverage limit, not a bug here.
     *
     * @param  list<array{source: string, target: string, type: string}>  $edges
     * @param  array<string, MetadataShape>  $metadata
     * @param  array<string, string>  $middlewareAliases
     * @return array<string, MetadataShape>
     */
    public static function withRouteGates(array $edges, array $metadata, array $middlewareAliases): array
    {
        foreach ($edges as $edge) {
            if (! str_starts_with($edge['source'], 'route::')) {
                continue;
            }

            if (! str_starts_with($edge['target'], 'middleware::')) {
                continue;
            }

            $rest = substr($edge['target'], strlen('middleware::'));
            $separator = strpos($rest, ':');

            if ($separator === false) {
                continue;
            }

            $reference = $middlewareAliases[substr($rest, 0, $separator)] ?? substr($rest, 0, $separator);

            if (! self::isPennantGateMiddleware(ltrim($reference, '\\'))) {
                continue;
            }

            $flags = array_values(array_filter(
                array_map(trim(...), explode(',', substr($rest, $separator + 1))),
                static fn (string $flag): bool => $flag !== '',
            ));

            if ($flags === []) {
                continue;
            }

            $existing = $metadata[$edge['source']]['gates'] ?? [];
            $metadata[$edge['source']]['gates'] = array_values(array_unique([...$existing, ...$flags]));
        }

        return $metadata;
    }

    /**
     * Exactly Pennant's gate middleware or an app subclass of it — never an unrelated class that
     * happens to share the basename, which would annotate non-Pennant parameters as feature flags.
     * The subclass check degrades to exact-only when the class can't load (e.g. Pennant absent).
     */
    private static function isPennantGateMiddleware(string $reference): bool
    {
        if ($reference === 'Laravel\Pennant\Middleware\EnsureFeaturesAreActive') {
            return true;
        }

        try {
            return class_exists($reference) && is_subclass_of($reference, 'Laravel\Pennant\Middleware\EnsureFeaturesAreActive');
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, static fn (mixed $entry): bool => is_string($entry) && $entry !== ''));
    }

    /**
     * Field-wise merge for two Brain nodes normalising onto one canonical id (e.g. a model entity
     * node and a deep-call class node): the first value per field wins, absent fields fill in.
     *
     * @param  MetadataShape  $existing
     * @param  MetadataShape  $incoming
     * @return MetadataShape
     */
    public static function merge(array $existing, array $incoming): array
    {
        return $existing + $incoming;
    }

    /**
     * Re-key the metadata map through a node-id resolver (the same closures that rewrite edge ids),
     * merging field-wise when two ids collapse onto one — otherwise the annotation would dangle on
     * ids the graph no longer contains.
     *
     * @param  array<string, MetadataShape>  $metadata
     * @param Closure(string):string $resolve
     * @return array<string, MetadataShape>
     */
    public static function remapKeys(array $metadata, Closure $resolve): array
    {
        $remapped = [];

        foreach ($metadata as $node => $nodeMetadata) {
            $key = $resolve($node);
            $remapped[$key] = isset($remapped[$key]) ? self::merge($remapped[$key], $nodeMetadata) : $nodeMetadata;
        }

        return $remapped;
    }

    /**
     * A tracer-only node (an FQCN Brain never saw, e.g. a job only reached through a dispatch edge)
     * has no data bag to read a location from — but its defining file follows from the `App\`
     * path convention, so derive it when that file actually exists. Existence-checked: a wrong
     * guess would send a reviewer to a file that isn't there.
     *
     * @param  list<array{source: string, target: string, type: string}>  $edges
     * @param  array<string, MetadataShape>  $metadata
     * @return array<string, MetadataShape>
     */
    public static function withFallbackFiles(array $edges, array $metadata, string $projectRoot): array
    {
        $fileByClass = [];

        foreach ($edges as $edge) {
            foreach ([$edge['source'], $edge['target']] as $node) {
                if (isset($metadata[$node]['file'])) {
                    continue;
                }

                $class = explode('::', $node, 2)[0];

                if (preg_match('/^App\\\\[\w\\\\]+$/', $class) !== 1) {
                    continue;
                }

                if (! isset($fileByClass[$class])) {
                    $relative = 'app/' . str_replace('\\', '/', substr($class, strlen('App\\'))) . '.php';
                    $fileByClass[$class] = is_file("{$projectRoot}/{$relative}") ? $relative : '';
                }

                if ($fileByClass[$class] !== '') {
                    $metadata[$node] = self::merge($metadata[$node] ?? [], ['file' => $fileByClass[$class]]);
                }
            }
        }

        return $metadata;
    }

    /**
     * Brain's per-route security surface, shape-checked field by field: a malformed entry drops
     * to null rather than propagating a loose shape into reports and the cache.
     *
     * @return SecurityShape|null
     */
    private static function security(mixed $security, string $projectRoot): ?array
    {
        if (! is_array($security)) {
            return null;
        }

        $exposure = $security['exposure'] ?? null;
        $riskLevel = $security['riskLevel'] ?? null;

        if (! is_string($exposure) || $exposure === '' || ! is_string($riskLevel) || $riskLevel === '') {
            return null;
        }

        $issues = [];

        foreach (is_array($security['issues'] ?? null) ? $security['issues'] : [] as $issue) {
            if (! is_array($issue)) {
                continue;
            }

            $type = $issue['type'] ?? null;
            $severity = $issue['severity'] ?? null;
            $message = $issue['message'] ?? null;
            if (! is_string($type)) {
                continue;
            }

            if (! is_string($severity)) {
                continue;
            }

            if (! is_string($message)) {
                continue;
            }

            $shaped = ['type' => $type, 'severity' => $severity, 'message' => $message];
            $issueFile = self::relativeFile($issue['file'] ?? null, $projectRoot);

            if ($issueFile !== null) {
                $shaped['file'] = $issueFile;
            }

            if (is_int($issue['line'] ?? null) && $issue['line'] > 0) {
                $shaped['line'] = $issue['line'];
            }

            $issues[] = $shaped;
        }

        return ['exposure' => $exposure, 'riskLevel' => $riskLevel, 'issues' => $issues];
    }

    /**
     * Brain records absolute paths; reports and the cache carry project-relative ones so they stay
     * portable. An empty root keeps every path verbatim — the cache-revalidation contract — instead
     * of degenerating into "strip the leading slash from any absolute path".
     */
    private static function relativeFile(mixed $file, string $projectRoot): ?string
    {
        if (! is_string($file) || $file === '') {
            return null;
        }

        if ($projectRoot === '') {
            return $file;
        }

        return str_starts_with($file, $projectRoot . '/') ? substr($file, strlen($projectRoot) + 1) : $file;
    }
}
