<?php declare(strict_types=1);

namespace SanderMuller\Richter\Graph;

use Composer\InstalledVersions;
use Illuminate\Support\Facades\Date;
use OutOfBoundsException;
use SanderMuller\Richter\Support\RichterConfig;
use Symfony\Component\Finder\Finder;
use Throwable;

/**
 * Serves the {@see CodeGraph} from a fingerprinted on-disk cache, rebuilding through
 * {@see CodeGraphBuilder} only when an input changed. The fingerprint content-hashes everything the
 * build reads — `app/`, `routes/`, `resources/views`, the richter and laravel-brain config, the
 * package versions — so a hit can only serve the graph those exact inputs produce; staleness is
 * designed out rather than expired out. A corrupt or mismatched cache file reads as a miss, and a
 * failed write is ignored: the cache is an optimisation and must never break or pollute a report
 * (JSON mode owns stdout). Registered as a singleton so one MCP session also reuses the parsed
 * graph in memory across tool calls.
 *
 * @phpstan-import-type MetadataShape from NodeMetadata
 */
final class GraphCache
{
    /**
     * Bump on any change to the build pipeline that {@see fingerprint()}'s inputs cannot see.
     * 3 → 4 (plan 036): {@see CodeGraph::toArray()} gained the `hasUnparseableFiles` key. Adding a
     * key to the payload is invisible to the fingerprint's other inputs, so without this bump a
     * stale pre-split cache entry (written by the old combined-flag code) would be served to the
     * new code and revive with `hasUnparseableFiles` silently defaulted — under-selection.
     */
    private const int FORMAT_VERSION = 4;

    private ?CodeGraph $memoized = null;

    private ?string $memoizedFingerprint = null;

    /**
     * In-process (absolute path => [stat signature, content-hash]) cache so a repeated fingerprint —
     * the MCP singleton re-checking a mostly-unchanged tree between tool calls — skips re-reading
     * files it already hashed. The fingerprint VALUE stays byte-identical to hashing every file: a
     * stored hash is reused only when the full stat signature (inode, size, mtime, ctime) is
     * unchanged and the file is not racily-recent. A content write bumps POSIX ctime even when mtime
     * is preserved (`cp -p`/`touch -r`/archive-restore can't fake it), so on Linux/macOS staleness
     * stays designed out. On Windows ctime is creation-time, so there the mtime+size check carries it
     * and an mtime-preserving content swap is the one residual gap — `--fresh`/`--no-cache` escapes it.
     *
     * @var array<string, array{sig: string, hash: string}>
     */
    private array $fileHashCache = [];

    public function __construct(private readonly CodeGraphBuilder $builder) {}

    /**
     * The current graph — from memory, then disk, then a fresh build (which also warms the cache).
     * `$fresh` bypasses the cache entirely for one call: no read, no write, no memo — the escape
     * hatch for the one failure mode a content fingerprint cannot rule out (an input it doesn't cover).
     * `$onProgress`, when given, is forwarded to the builder on every build path; a cache HIT never
     * invokes it — nothing was built, so there is nothing to time.
     *
     * @param  (callable(string, array<string, mixed>): void)|null  $onProgress
     */
    public function graph(?string $projectRoot = null, bool $fresh = false, ?callable $onProgress = null): CodeGraph
    {
        $projectRoot ??= base_path();

        if ($fresh || ! RichterConfig::cacheEnabled()) {
            return $this->builder->build($projectRoot, $onProgress);
        }

        $fingerprint = $this->fingerprint($projectRoot);

        if ($this->memoized instanceof CodeGraph && $this->memoizedFingerprint === $fingerprint) {
            return $this->memoized;
        }

        $graph = $this->read($fingerprint);

        if (! $graph instanceof CodeGraph) {
            $graph = $this->builder->build($projectRoot, $onProgress);
            $this->write($fingerprint, $graph);
        }

        $this->memoized = $graph;
        $this->memoizedFingerprint = $fingerprint;

        return $graph;
    }

    /**
     * Content hash over every input the graph build reads. Conservative by construction: any changed,
     * added, or removed file under the traced roots changes the fingerprint, as does any relevant
     * config value or package version — a false miss costs one rebuild, a false hit would be the
     * falsely-reassuring stale report this package exists to prevent.
     */
    public function fingerprint(string $projectRoot): string
    {
        $context = hash_init('xxh128');

        hash_update($context, 'format:' . self::FORMAT_VERSION);
        hash_update($context, '|php:' . PHP_VERSION);
        hash_update($context, '|richter:' . $this->packageVersion('sandermuller/richter'));
        hash_update($context, '|brain:' . $this->packageVersion('laramint/laravel-brain'));
        hash_update($context, '|config:' . json_encode([
            'entry_point_roots' => RichterConfig::entryPointRoots(),
            'dispatch_helpers' => RichterConfig::dispatchHelpers(),
            'laravel-brain' => $this->brainConfigInput(),
        ], JSON_THROW_ON_ERROR));

        // Fresh stat metadata: in a long-lived process (the MCP singleton) PHP's per-request stat
        // cache would otherwise report a file changed since the previous call as unchanged.
        clearstatcache();
        $now = Date::now()->getTimestamp();

        foreach ($this->inputFiles($projectRoot) as $path) {
            hash_update($context, "|{$path}:" . $this->fileHash("{$projectRoot}/{$path}", $now));
        }

        return hash_final($context);
    }

    /**
     * The file's content hash — reused from {@see $fileHashCache} when its stat signature is
     * unchanged and it is not racily-recent, otherwise (re)hashed. A file racing away reads as ''
     * (a deterministic miss, as before). This never changes the fingerprint versus hashing every
     * time; it only skips the content read when stat proves the bytes are unchanged. `$now` is the
     * one-per-fingerprint wall-clock second, passed in so the racy check costs nothing per file.
     */
    private function fileHash(string $absolutePath, int $now): string
    {
        $stat = @stat($absolutePath);

        if ($stat === false) {
            unset($this->fileHashCache[$absolutePath]);

            return '';
        }

        $signature = "{$stat['ino']}:{$stat['size']}:{$stat['mtime']}:{$stat['ctime']}";
        // Racily-recent: a change in this same second could keep an identical signature, so never
        // trust the cache for it — re-hash and don't store (git's racy-clean discipline).
        $racy = $stat['mtime'] >= $now || $stat['ctime'] >= $now;

        if (! $racy && ($this->fileHashCache[$absolutePath]['sig'] ?? null) === $signature) {
            return $this->fileHashCache[$absolutePath]['hash'];
        }

        $computed = hash_file('xxh128', $absolutePath);
        $hash = $computed === false ? '' : $computed;

        if ($racy) {
            unset($this->fileHashCache[$absolutePath]);
        } else {
            $this->fileHashCache[$absolutePath] = ['sig' => $signature, 'hash' => $hash];
        }

        return $hash;
    }

    /**
     * The laravel-brain config that actually feeds the analysis. {@see CodeGraphBuilder::build()}
     * force-overrides the four path keys for the duration of every build (restoring them after), so
     * their host values never influence the produced graph — hashing them would only turn a change
     * the build ignores into a spurious rebuild.
     */
    private function brainConfigInput(): mixed
    {
        $config = config('laravel-brain');

        if (! is_array($config)) {
            return $config;
        }

        unset($config['route_paths'], $config['channel_paths']);

        if (is_array($config['commands'] ?? null)) {
            unset($config['commands']['console_route_paths'], $config['commands']['class_paths']);
        }

        // Builder-forced keys may be all there was — normalise the empty leftovers away so
        // "config the builder set" hashes the same as "no brain config at all".
        if (($config['commands'] ?? null) === []) {
            unset($config['commands']);
        }

        return $config === [] ? null : $config;
    }

    private function read(string $fingerprint): ?CodeGraph
    {
        $file = $this->cacheFile();

        if (! is_file($file)) {
            return null;
        }

        try {
            $data = json_decode((string) file_get_contents($file), associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($data) || ($data['fingerprint'] ?? null) !== $fingerprint) {
            return null;
        }

        $edges = $this->validEdges($data['edges'] ?? null);
        $metadata = $this->validNodeMetadata($data['nodeMetadata'] ?? null);

        if ($edges === null || $metadata === null) {
            return null;
        }

        return new CodeGraph(
            $edges,
            ($data['hasUnparseableFiles'] ?? false) === true,
            ($data['hasUnresolvedDispatches'] ?? false) === true,
            $metadata,
        );
    }

    private function write(string $fingerprint, CodeGraph $graph): void
    {
        try {
            $directory = RichterConfig::cacheDirectory();

            if (! is_dir($directory)) {
                mkdir($directory, recursive: true);
            }

            // Write-then-rename so a concurrent reader never sees a torn file.
            $tmp = $this->cacheFile() . '.' . getmypid() . '.tmp';
            $payload = json_encode(['fingerprint' => $fingerprint] + $graph->toArray(), JSON_THROW_ON_ERROR);

            if (file_put_contents($tmp, $payload) !== false) {
                rename($tmp, $this->cacheFile());
            }
        } catch (Throwable) {
            // Failing to warm the cache only costs the next run a rebuild.
        }
    }

    private function cacheFile(): string
    {
        return RichterConfig::cacheDirectory() . '/graph.json';
    }

    /**
     * A cache entry whose edges don't parse as edge shapes is corrupt — the whole read is a miss,
     * never a partially-loaded graph (which would report falsely-small impact).
     *
     * @return list<array{source: string, target: string, type: string}>|null
     */
    private function validEdges(mixed $edges): ?array
    {
        if (! is_array($edges)) {
            return null;
        }

        $valid = [];

        foreach ($edges as $edge) {
            if (! is_array($edge) || ! is_string($edge['source'] ?? null) || ! is_string($edge['target'] ?? null) || ! is_string($edge['type'] ?? null)) {
                return null;
            }

            $valid[] = ['source' => $edge['source'], 'target' => $edge['target'], 'type' => $edge['type']];
        }

        return $valid;
    }

    /**
     * The node-metadata map from a cache entry, re-shaped through {@see NodeMetadata} so a tampered
     * or drifted entry degrades to the same conservative shapes a fresh build would produce. Only a
     * non-map value is corrupt (→ miss, like {@see validEdges}); an individual record that doesn't
     * shape-check simply loses its unusable fields — metadata annotates reports, it never feeds the
     * impact walk, so partial loss here cannot under-report impact.
     *
     * @return array<string, MetadataShape>|null
     */
    private function validNodeMetadata(mixed $metadata): ?array
    {
        if ($metadata === null) {
            // Pre-metadata entries can't reach here (FORMAT_VERSION is fingerprinted), but an
            // absent map is still a valid empty annotation set, not corruption.
            return [];
        }

        if (! is_array($metadata)) {
            return null;
        }

        $valid = [];

        foreach ($metadata as $node => $record) {
            if (! is_string($node) || ! is_array($record)) {
                return null;
            }

            // Re-extract through the same shape gate the builder uses: '' as root means "keep
            // stored paths verbatim" — they were made project-relative at build time.
            $shaped = NodeMetadata::fromBrainNodeData($record, '');

            if ($shaped !== null) {
                $valid[$node] = $shaped;
            }
        }

        return $valid;
    }

    /**
     * Every file the build pipeline reads, project-relative and in one deterministic order:
     * Brain and the tracers scan `app/` and `routes/` PHP plus the Blade views, and
     * `bootstrap/app.php` feeds middleware-alias resolution (Brain's registry and
     * {@see MiddlewareAliases}) — never the whole `bootstrap/` dir, whose `cache/` churns.
     *
     * @return list<string>
     */
    private function inputFiles(string $projectRoot): array
    {
        $directories = array_values(array_filter(
            ["{$projectRoot}/app", "{$projectRoot}/routes", "{$projectRoot}/resources/views"],
            is_dir(...),
        ));

        $paths = is_file("{$projectRoot}/bootstrap/app.php") ? ['bootstrap/app.php'] : [];

        if ($directories === [] && $paths === []) {
            return [];
        }

        foreach ($directories === [] ? [] : Finder::create()->files()->in($directories)->name(['*.php', '*.blade.php']) as $file) {
            $paths[] = substr($file->getPathname(), strlen($projectRoot) + 1);
        }

        sort($paths);

        return $paths;
    }

    /** The installed version, or a stable placeholder when Composer can't resolve the package (e.g. richter developed as the root package). */
    private function packageVersion(string $package): string
    {
        try {
            return InstalledVersions::getVersion($package) ?? 'unknown';
        } catch (OutOfBoundsException) {
            return 'unknown';
        }
    }
}
