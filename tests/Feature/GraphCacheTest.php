<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use Override;
use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Graph\GraphCache;
use SanderMuller\Richter\Tests\TestCase;

final class GraphCacheTest extends TestCase
{
    private string $base;

    private string $projectRoot;

    private string $cacheDirectory;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        // A tiny disposable project tree — fingerprints and cache round-trips are exercised against
        // it, so mutations never touch the repo's own fixtures.
        $this->base = sys_get_temp_dir() . '/richter-graph-cache-' . bin2hex(random_bytes(8));
        $this->projectRoot = "{$this->base}/project";
        $this->cacheDirectory = "{$this->base}/cache";

        mkdir("{$this->projectRoot}/app/Services", recursive: true);
        mkdir("{$this->projectRoot}/routes", recursive: true);
        file_put_contents("{$this->projectRoot}/app/Services/Alpha.php", "<?php\n\nnamespace App\\Services;\n\nclass Alpha\n{\n    public function run(): void {}\n}\n");
        file_put_contents("{$this->projectRoot}/routes/web.php", "<?php\n");

        config()->set('richter.cache.enabled', true);
        config()->set('richter.cache.directory', $this->cacheDirectory);
    }

    #[Override]
    protected function tearDown(): void
    {
        new Filesystem()->deleteDirectory($this->base);

        parent::tearDown();
    }

    private function cache(): GraphCache
    {
        // The container singleton — the app is rebuilt per test, so every test starts with an
        // empty in-memory memo and the disk path is what each assertion exercises.
        return app(GraphCache::class);
    }

    private function cacheFile(): string
    {
        return "{$this->cacheDirectory}/graph.json";
    }

    /** @param  list<array{source: string, target: string, type: string}>  $edges */
    private function writeCacheFile(string $fingerprint, array $edges): void
    {
        mkdir($this->cacheDirectory, recursive: true);
        file_put_contents($this->cacheFile(), json_encode([
            'fingerprint' => $fingerprint,
            'edges' => $edges,
            'hasUnresolvedDispatches' => false,
        ], JSON_THROW_ON_ERROR));
    }

    /** @return list<array{source: string, target: string, type: string}> */
    private function markerEdges(): array
    {
        return [['source' => 'marker::A', 'target' => 'marker::B', 'type' => 'call']];
    }

    #[Test]
    public function the_fingerprint_is_stable_for_unchanged_inputs(): void
    {
        $cache = $this->cache();

        $this->assertSame($cache->fingerprint($this->projectRoot), $cache->fingerprint($this->projectRoot));
    }

    #[Test]
    public function the_fingerprint_survives_a_build_in_the_same_process(): void
    {
        // CodeGraphBuilder::build() force-overrides the laravel-brain path config globally; the
        // fingerprint must not read that as an input change, or every call after the first in one
        // process (the MCP session) would miss and rebuild.
        $cache = $this->cache();
        $before = $cache->fingerprint($this->projectRoot);

        $cache->graph($this->projectRoot);

        $this->assertSame($before, $cache->fingerprint($this->projectRoot));
    }

    #[Test]
    public function the_fingerprint_changes_when_a_traced_file_changes(): void
    {
        $before = $this->cache()->fingerprint($this->projectRoot);

        file_put_contents("{$this->projectRoot}/app/Services/Alpha.php", "<?php\n\nnamespace App\\Services;\n\nclass Alpha\n{\n    public function run(): int { return 1; }\n}\n");

        $this->assertNotSame($before, $this->cache()->fingerprint($this->projectRoot));
    }

    #[Test]
    public function the_fingerprint_changes_when_a_traced_file_is_added(): void
    {
        $before = $this->cache()->fingerprint($this->projectRoot);

        file_put_contents("{$this->projectRoot}/app/Services/Beta.php", "<?php\n\nnamespace App\\Services;\n\nclass Beta {}\n");

        $this->assertNotSame($before, $this->cache()->fingerprint($this->projectRoot));
    }

    #[Test]
    public function the_fingerprint_changes_when_build_relevant_config_changes(): void
    {
        $before = $this->cache()->fingerprint($this->projectRoot);

        config()->set('richter.entry_point_roots', ['Jobs']);

        $this->assertNotSame($before, $this->cache()->fingerprint($this->projectRoot));
    }

    #[Test]
    public function a_matching_cache_entry_is_served_without_rebuilding(): void
    {
        // The stored marker edges cannot come from a real build of the tiny project — getting them
        // back proves the graph was read from disk, not rebuilt.
        $cache = $this->cache();
        $this->writeCacheFile($cache->fingerprint($this->projectRoot), $this->markerEdges());

        $graph = $cache->graph($this->projectRoot);

        $this->assertSame(['edges' => $this->markerEdges(), 'hasUnresolvedDispatches' => false], $graph->toArray());
    }

    #[Test]
    public function a_fingerprint_mismatch_rebuilds_and_rewrites_the_cache(): void
    {
        $this->writeCacheFile('stale-fingerprint', $this->markerEdges());

        $cache = $this->cache();
        $graph = $cache->graph($this->projectRoot);

        // The stale marker graph must not be served…
        $this->assertNotContains($this->markerEdges()[0], $graph->toArray()['edges']);
        // …and the rewritten entry now carries the current fingerprint.
        $stored = json_decode((string) file_get_contents($this->cacheFile()), associative: true);
        $this->assertIsArray($stored);
        $this->assertSame($cache->fingerprint($this->projectRoot), $stored['fingerprint']);
    }

    #[Test]
    public function a_corrupt_cache_file_reads_as_a_miss_not_an_error(): void
    {
        mkdir($this->cacheDirectory, recursive: true);
        file_put_contents($this->cacheFile(), 'not json {{{');

        $graph = $this->cache()->graph($this->projectRoot);

        $this->assertNotContains($this->markerEdges()[0], $graph->toArray()['edges']);
    }

    #[Test]
    public function a_cache_entry_with_malformed_edges_reads_as_a_miss(): void
    {
        // A shape-invalid edge poisons the whole entry — a partially-loaded graph would report
        // falsely-small impact, so the read is all-or-nothing.
        $cache = $this->cache();
        mkdir($this->cacheDirectory, recursive: true);
        file_put_contents($this->cacheFile(), json_encode([
            'fingerprint' => $cache->fingerprint($this->projectRoot),
            'edges' => [['source' => 'marker::A', 'target' => 42, 'type' => 'call']],
            'hasUnresolvedDispatches' => false,
        ], JSON_THROW_ON_ERROR));

        $graph = $cache->graph($this->projectRoot);

        $this->assertNotContains('marker::A', array_column($graph->toArray()['edges'], 'source'));
    }

    #[Test]
    public function fresh_bypasses_both_the_read_and_the_write(): void
    {
        $cache = $this->cache();
        $this->writeCacheFile($cache->fingerprint($this->projectRoot), $this->markerEdges());
        $storedBefore = file_get_contents($this->cacheFile());

        $graph = $cache->graph($this->projectRoot, fresh: true);

        // The matching cache entry is ignored — a real build served instead…
        $this->assertNotContains($this->markerEdges()[0], $graph->toArray()['edges']);
        // …and the entry on disk is left untouched (fresh writes nothing either).
        $this->assertSame($storedBefore, file_get_contents($this->cacheFile()));
    }

    #[Test]
    public function a_disabled_cache_builds_without_writing(): void
    {
        config()->set('richter.cache.enabled', false);

        $this->cache()->graph($this->projectRoot);

        $this->assertFileDoesNotExist($this->cacheFile());
    }

    #[Test]
    public function a_build_on_a_cache_miss_warms_the_cache(): void
    {
        $cache = $this->cache();

        $graph = $cache->graph($this->projectRoot);

        $this->assertFileExists($this->cacheFile());
        $stored = json_decode((string) file_get_contents($this->cacheFile()), associative: true);
        $this->assertIsArray($stored);
        $this->assertSame($cache->fingerprint($this->projectRoot), $stored['fingerprint']);
        $this->assertSame($graph->toArray()['edges'], $stored['edges']);
    }

    #[Test]
    public function the_singleton_memo_serves_the_same_graph_instance_within_a_process(): void
    {
        $cache = $this->cache();

        $this->assertSame($cache->graph($this->projectRoot), $cache->graph($this->projectRoot));
    }

    #[Test]
    public function the_memo_does_not_survive_an_input_change(): void
    {
        $cache = $this->cache();
        $first = $cache->graph($this->projectRoot);

        file_put_contents("{$this->projectRoot}/app/Services/Beta.php", "<?php\n\nnamespace App\\Services;\n\nclass Beta {}\n");

        $this->assertNotSame($first, $cache->graph($this->projectRoot));
    }
}
