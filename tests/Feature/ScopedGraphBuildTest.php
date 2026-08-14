<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Graph\CodeGraphBuilder;
use SanderMuller\Richter\Graph\GraphCache;
use SanderMuller\Richter\Support\ScopedRebuildDecision;
use SanderMuller\Richter\Tests\TestCase;

/**
 * The correctness gate for incremental analysis: a scoped rebuild must produce the graph a full build
 * would have produced, byte for byte.
 *
 * Equivalent-but-reordered is a failure here, not a nitpick. Brain's `IncrementalMerge` carries
 * untouched nodes over in the previous build's order specifically so this holds, and richter's own
 * `--explain` output depends on it: a differently-ordered graph tie-breaks its walks differently and
 * shows a different (equal-length) chain for the same commit.
 */
final class ScopedGraphBuildTest extends TestCase
{
    private string $base;

    private string $projectRoot;

    /** @var array<string, mixed> */
    private array $phase = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->base = sys_get_temp_dir() . '/richter-scoped-' . bin2hex(random_bytes(8));
        $this->projectRoot = "{$this->base}/project";
        mkdir("{$this->projectRoot}/app/Services", recursive: true);
        mkdir("{$this->projectRoot}/app/Http/Controllers", recursive: true);
        mkdir("{$this->projectRoot}/routes", recursive: true);

        $this->writeService('run');
        file_put_contents(
            "{$this->projectRoot}/app/Http/Controllers/PostController.php",
            "<?php\n\nnamespace App\\Http\\Controllers;\n\nuse App\\Services\\Publisher;\n\nclass PostController\n{\n    public function store(Publisher \$publisher): void\n    {\n        \$publisher->run();\n    }\n}\n",
        );
        file_put_contents(
            "{$this->projectRoot}/routes/web.php",
            "<?php\n\nuse App\\Http\\Controllers\\PostController;\nuse Illuminate\\Support\\Facades\\Route;\n\nRoute::post('/posts', [PostController::class, 'store']);\n",
        );

        config()->set('richter.cache.enabled', true);
        config()->set('richter.cache.directory', "{$this->base}/cache");
    }

    protected function tearDown(): void
    {
        new Filesystem()->deleteDirectory($this->base);
        parent::tearDown();
    }

    private function writeService(string $method): void
    {
        file_put_contents(
            "{$this->projectRoot}/app/Services/Publisher.php",
            "<?php\n\nnamespace App\\Services;\n\nclass Publisher\n{\n    public function {$method}(): void\n    {\n        \$this->log();\n    }\n\n    private function log(): void {}\n}\n",
        );
    }

    #[Test]
    public function a_scoped_build_produces_the_same_graph_as_a_full_build(): void
    {
        $builder = new CodeGraphBuilder();

        $first = $builder->buildDetailed($this->projectRoot);
        $this->assertSame('full', $first->path, 'the first build has no merge base');

        // A body change in a file that DECLARES A CONTROLLER, leaving its own call shape intact —
        // the only shape `scopedTo()` can serve, since it re-traces the controllers a changed file
        // declares and nothing else.
        file_put_contents(
            "{$this->projectRoot}/app/Http/Controllers/PostController.php",
            "<?php\n\nnamespace App\\Http\\Controllers;\n\nuse App\\Services\\Publisher;\n\nclass PostController\n{\n    public function store(Publisher \$publisher): void\n    {\n        \$n = 1;\n        \$publisher->run();\n    }\n}\n",
        );

        $scoped = $builder->buildDetailed(
            $this->projectRoot,
            null,
            $first->brainGraph,
            ScopedRebuildDecision::scoped(["{$this->projectRoot}/app/Http/Controllers/PostController.php"]),
        );

        $full = $builder->buildDetailed($this->projectRoot);

        $this->assertSame('scoped', $scoped->path, 'Brain accepted the scope, so this is the path under test');
        $this->assertSame(1, $scoped->scopedFileCount);
        // Nodes and edges, not the whole JSON: Brain's `meta` carries an `analyzedAt` timestamp, so
        // two analyses can never be byte-equal there. These are what the merge substitutes and what
        // richter reads.
        $this->assertEquals($full->brainGraph->nodes(), $scoped->brainGraph->nodes());
        $this->assertEquals($full->brainGraph->edges(), $scoped->brainGraph->edges());
        $this->assertSame($full->graph->toArray(), $scoped->graph->toArray());
    }

    #[Test]
    public function a_rejected_scope_falls_back_and_still_produces_the_full_graph(): void
    {
        $builder = new CodeGraphBuilder();
        $first = $builder->buildDetailed($this->projectRoot);

        // Moving the call out of `run()` changes the file's own owned edges, which is exactly what
        // Brain refuses to merge. The retry must produce the same graph a full build would.
        file_put_contents(
            "{$this->projectRoot}/app/Services/Publisher.php",
            "<?php\n\nnamespace App\\Services;\n\nclass Publisher\n{\n    public function run(): void {}\n\n    private function log(): void\n    {\n        \$this->run();\n    }\n}\n",
        );

        $scoped = $builder->buildDetailed(
            $this->projectRoot,
            null,
            $first->brainGraph,
            ScopedRebuildDecision::scoped(["{$this->projectRoot}/app/Services/Publisher.php"]),
        );

        $full = $builder->buildDetailed($this->projectRoot);

        $this->assertSame('scoped-rejected', $scoped->path, 'the moved call must be refused, not merged');
        $this->assertEquals($full->brainGraph->nodes(), $scoped->brainGraph->nodes());
        $this->assertSame($full->graph->toArray(), $scoped->graph->toArray());
    }

    #[Test]
    public function the_cache_round_trip_serves_the_same_graph_a_scoped_rebuild_produced(): void
    {
        // End to end through `GraphCache`: warm the entry, edit one app file, and let the cache pick
        // the merge base and the scope itself. What comes out must match a from-scratch build.
        $cache = resolve(GraphCache::class);
        $cache->graph($this->projectRoot);

        file_put_contents(
            "{$this->projectRoot}/app/Http/Controllers/PostController.php",
            "<?php\n\nnamespace App\\Http\\Controllers;\n\nuse App\\Services\\Publisher;\n\nclass PostController\n{\n    public function store(Publisher \$publisher): void\n    {\n        \$y = 2;\n        \$publisher->run();\n    }\n}\n",
        );

        $paths = [];
        $collect = static function (string $event, array $data) use (&$paths): void {
            if ($event === 'richter:phase' && ($data['phase'] ?? null) === 'brain-analyze') {
                $paths[] = $data['path'] ?? null;
            }
        };

        $viaCache = new GraphCache(new CodeGraphBuilder())->graph($this->projectRoot, onProgress: $collect);
        $fromScratch = new CodeGraphBuilder()->build($this->projectRoot);

        // Asserted, not assumed: without this the equality below would also hold if the cache had
        // quietly taken the full path, and the test would prove nothing about the feature.
        $this->assertSame(['scoped'], $paths);
        $this->assertSame($fromScratch->toArray(), $viaCache->toArray());
    }

    #[Test]
    public function a_forced_rebuild_still_reuses_the_merge_base(): void
    {
        // What `--profile` relies on. It has to refuse the cache HIT, or there is no build to time —
        // but refusing the merge base too would make it profile a full analysis for a project whose
        // every run is scoped, and would pin the path label to `full` by construction, which is
        // exactly how the label shipped unobservable.
        $cache = resolve(GraphCache::class);
        $cache->graph($this->projectRoot);

        file_put_contents(
            "{$this->projectRoot}/app/Http/Controllers/PostController.php",
            "<?php\n\nnamespace App\\Http\\Controllers;\n\nuse App\\Services\\Publisher;\n\nclass PostController\n{\n    public function store(Publisher \$publisher): void\n    {\n        \$w = 4;\n        \$publisher->run();\n    }\n}\n",
        );

        $paths = [];
        $collect = static function (string $event, array $data) use (&$paths): void {
            if ($event === 'richter:phase' && ($data['phase'] ?? null) === 'brain-analyze') {
                $paths[] = $data['path'] ?? null;
            }
        };

        new GraphCache(new CodeGraphBuilder())->graph($this->projectRoot, onProgress: $collect, rebuild: true);

        $this->assertSame(['scoped'], $paths);
    }

    #[Test]
    public function a_fresh_build_takes_no_merge_base(): void
    {
        // The counterpart, and the reason `fresh` and `rebuild` are two requests rather than one:
        // `--no-cache` must still measure a build with nothing reused.
        $cache = resolve(GraphCache::class);
        $cache->graph($this->projectRoot);

        file_put_contents(
            "{$this->projectRoot}/app/Http/Controllers/PostController.php",
            "<?php\n\nnamespace App\\Http\\Controllers;\n\nuse App\\Services\\Publisher;\n\nclass PostController\n{\n    public function store(Publisher \$publisher): void\n    {\n        \$v = 5;\n        \$publisher->run();\n    }\n}\n",
        );

        $paths = [];
        $collect = static function (string $event, array $data) use (&$paths): void {
            if ($event === 'richter:phase' && ($data['phase'] ?? null) === 'brain-analyze') {
                $paths[] = $data['path'] ?? null;
            }
        };

        new GraphCache(new CodeGraphBuilder())->graph($this->projectRoot, fresh: true, onProgress: $collect);

        $this->assertSame(['full'], $paths);
    }

    /**
     * The `brain-analyze` phase payload from one cached run over the current tree.
     *
     * @return array<string, mixed>
     */
    private function analysePhase(bool $rebuild = false): array
    {
        $this->phase = [];

        new GraphCache(new CodeGraphBuilder())->graph($this->projectRoot, onProgress: $this->capturePhase(...), rebuild: $rebuild);

        return $this->phase;
    }

    /** @param  array<string, mixed>  $data */
    private function capturePhase(string $event, array $data): void
    {
        if ($event === 'richter:phase' && ($data['phase'] ?? null) === 'brain-analyze') {
            $this->phase = $data;
        }
    }

    /**
     * The cached entry as stored, for tests that damage one key of it.
     *
     * @return array<string, mixed>
     */
    private function cacheEntry(): array
    {
        $decoded = json_decode((string) file_get_contents($this->cacheFile()), associative: true);
        $this->assertIsArray($decoded);

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /** @param  array<string, mixed>  $entry */
    private function writeCacheEntry(array $entry): void
    {
        file_put_contents($this->cacheFile(), json_encode($entry));
    }

    private function cacheFile(): string
    {
        return "{$this->base}/cache/graph.json";
    }

    #[Test]
    public function a_first_run_reports_that_there_is_nothing_cached_to_build_onto(): void
    {
        // The benign reason, and the one that must not look like the others: it fixes itself on the
        // next run. Reported all the same, so a reader who sees it stops looking for a bug.
        $phase = $this->analysePhase();

        $this->assertSame('full', $phase['path']);
        $this->assertSame('no-cache-entry', $phase['reason']);
    }

    #[Test]
    public function a_stored_graph_this_version_cannot_revive_is_reported_as_such(): void
    {
        // The reason worth building all of this for. A merge base the codec refuses is permanent —
        // every run rebuilds in full, forever, and the label said `full` exactly as it does on a
        // first run. These two being one word apart was the whole diagnostic gap.
        $cache = resolve(GraphCache::class);
        $cache->graph($this->projectRoot);

        // A stored graph declaring more nodes than it carries, standing in for every payload
        // `BrainGraphCodec` refuses whole rather than reviving short.
        $entry = $this->cacheEntry();
        $entry['brainGraph'] = ['meta' => ['nodeCount' => 5, 'edgeCount' => 5], 'nodes' => [], 'edges' => []];
        $this->writeCacheEntry($entry);

        $this->assertSame('brain-graph-rejected', $this->analysePhase(rebuild: true)['reason']);
    }

    #[Test]
    public function an_entry_predating_the_feature_is_reported_apart_from_a_corrupt_one(): void
    {
        $cache = resolve(GraphCache::class);
        $cache->graph($this->projectRoot);

        $entry = $this->cacheEntry();
        unset($entry['brainGraph'], $entry['inputs']);
        $this->writeCacheEntry($entry);

        $this->assertSame('no-merge-base-stored', $this->analysePhase(rebuild: true)['reason']);
    }

    #[Test]
    public function a_damaged_entry_and_an_unrevivable_input_record_are_reported_apart(): void
    {
        $cache = resolve(GraphCache::class);
        $cache->graph($this->projectRoot);

        file_put_contents($this->cacheFile(), '{"fingerprint": "abc", "inputs"');
        $this->assertSame('cache-unreadable', $this->analysePhase(rebuild: true)['reason']);

        $cache->graph($this->projectRoot, fresh: false);
        $entry = $this->cacheEntry();
        $entry['inputs'] = 'not a record';
        $this->writeCacheEntry($entry);

        $this->assertSame('inputs-rejected', $this->analysePhase(rebuild: true)['reason']);
    }

    #[Test]
    public function profiling_the_same_tree_the_cache_was_warmed_on_reports_that_nothing_differs(): void
    {
        // Warm, then profile without touching anything — the protocol anyone reaches for, and one that
        // cannot show `scoped` no matter what the project looks like. `--profile` refuses the cache HIT
        // so there is a build to time, but the stored record still matches the tree byte for byte, and
        // a zero-file scope re-emits the previous graph unchanged, so it is refused by design.
        //
        // Which means the `scoped` label needs an edit made AFTER the warming run, and until this
        // reason existed, that requirement was invisible: the label read `full` and looked like a
        // feature that never engages.
        $cache = resolve(GraphCache::class);
        $cache->graph($this->projectRoot);

        $phase = $this->analysePhase(rebuild: true);

        $this->assertSame('full', $phase['path']);
        $this->assertSame('no-change', $phase['reason']);
        $this->assertSame('every hashed input matches the cached graph — the comparison is against the last build, not against a git ref', $phase['reasonDetail']);
    }

    #[Test]
    public function an_edit_reproducing_content_the_cache_already_built_is_no_change(): void
    {
        // The subtler shape of the same refusal, and the one that actually misled a reader: the tree DID
        // change since the last commit, so `git diff` shows an edit and the warning about "profiling the
        // same tree" reads as inapplicable. But the comparison is against the stored graph's inputs, and
        // an earlier run had already built these exact bytes — so there is nothing left to scope, which
        // is correct: the graph for this content is what the cache is holding.
        $edited = "<?php\n\nnamespace App\\Http\\Controllers;\n\nuse App\\Services\\Publisher;\n\nclass PostController\n{\n    public function store(Publisher \$publisher): void\n    {\n        \$probe = 1;\n        \$publisher->run();\n    }\n}\n";
        $controller = "{$this->projectRoot}/app/Http/Controllers/PostController.php";

        $cache = resolve(GraphCache::class);
        $cache->graph($this->projectRoot);

        // First probe: a real edit, built and stored.
        file_put_contents($controller, $edited);
        $this->assertSame('scoped', $this->analysePhase()['path']);

        // Second probe: reset and re-apply the same marker, which is what a repeated experiment does.
        // The mtime is pushed forward explicitly — within one test run the clock would not move enough
        // to prove anything, and what this pins is that the record hashes CONTENT, so a file rewritten
        // with the same bytes is the same input however recently it was touched.
        $this->writeService('run');
        file_put_contents($controller, $edited);
        touch($controller, (int) filemtime($controller) + 60);

        $phase = $this->analysePhase(rebuild: true);

        $this->assertSame('full', $phase['path']);
        $this->assertSame('no-change', $phase['reason']);
    }

    #[Test]
    public function a_change_outside_app_is_reported_with_the_path_that_caused_it(): void
    {
        $cache = resolve(GraphCache::class);
        $cache->graph($this->projectRoot);

        file_put_contents(
            "{$this->projectRoot}/routes/web.php",
            "<?php\n\nuse App\\Http\\Controllers\\PostController;\nuse Illuminate\\Support\\Facades\\Route;\n\nRoute::post('/posts', [PostController::class, 'store']);\nRoute::get('/posts', [PostController::class, 'store']);\n",
        );

        $phase = $this->analysePhase();

        $this->assertSame('non-app-change', $phase['reason']);
        $this->assertSame('routes/web.php differs from the cached graph and sits outside app/', $phase['reasonDetail']);
    }

    #[Test]
    public function a_scoped_run_reports_no_reason(): void
    {
        // The counterweight to every assertion above: an engaged scoped rebuild must carry no excuse,
        // or a reader cannot tell a working run from a refused one by reading the reason at all.
        $cache = resolve(GraphCache::class);
        $cache->graph($this->projectRoot);

        file_put_contents(
            "{$this->projectRoot}/app/Http/Controllers/PostController.php",
            "<?php\n\nnamespace App\\Http\\Controllers;\n\nuse App\\Services\\Publisher;\n\nclass PostController\n{\n    public function store(Publisher \$publisher): void\n    {\n        \$q = 6;\n        \$publisher->run();\n    }\n}\n",
        );

        $phase = $this->analysePhase();

        $this->assertSame('scoped', $phase['path']);
        $this->assertArrayNotHasKey('reason', $phase);
    }

    #[Test]
    public function the_profile_extra_names_the_path_taken(): void
    {
        // Without this a scoped build that silently fell back looks identical to one that never
        // tried, and "did it engage?" is the first question anyone asks of this feature.
        $builder = new CodeGraphBuilder();
        $first = $builder->buildDetailed($this->projectRoot);

        $events = [];
        $collect = static function (string $event, array $data) use (&$events): void {
            if ($event === 'richter:phase' && ($data['phase'] ?? null) === 'brain-analyze') {
                $events[] = $data;
            }
        };

        file_put_contents(
            "{$this->projectRoot}/app/Http/Controllers/PostController.php",
            "<?php\n\nnamespace App\\Http\\Controllers;\n\nuse App\\Services\\Publisher;\n\nclass PostController\n{\n    public function store(Publisher \$publisher): void\n    {\n        \$z = 3;\n        \$publisher->run();\n    }\n}\n",
        );

        $builder->buildDetailed(
            $this->projectRoot,
            $collect,
            $first->brainGraph,
            ScopedRebuildDecision::scoped(["{$this->projectRoot}/app/Http/Controllers/PostController.php"]),
        );

        $this->assertCount(1, $events);
        $this->assertSame('scoped', $events[0]['path']);
        $this->assertSame(1, $events[0]['scopedFiles']);
    }
}
