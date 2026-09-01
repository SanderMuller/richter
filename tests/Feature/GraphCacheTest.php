<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Graph\CodeGraphBuilder;
use SanderMuller\Richter\Graph\GraphCache;
use SanderMuller\Richter\Tests\TestCase;

final class GraphCacheTest extends TestCase
{
    private string $base;

    private string $projectRoot;

    private string $cacheDirectory;

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

    protected function tearDown(): void
    {
        new Filesystem()->deleteDirectory($this->base);
        parent::tearDown();
    }

    private function cache(): GraphCache
    {
        // The container singleton — the app is rebuilt per test, so every test starts with an
        // empty in-memory memo and the disk path is what each assertion exercises.
        return resolve(GraphCache::class);
    }

    private function cacheFile(): string
    {
        return "{$this->cacheDirectory}/graph.json";
    }

    /** @return array<string, mixed> */
    private function storedEntry(): array
    {
        /** @var array<string, mixed> $entry */
        $entry = json_decode((string) file_get_contents($this->cacheFile()), associative: true, flags: JSON_THROW_ON_ERROR);

        return $entry;
    }

    /**
     * @param  list<array{source: string, target: string, type: string}>  $edges
     * @param  array<string, array{file?: string, line?: int, uri?: string, security?: array{exposure: string, riskLevel: string, issues: list<array{type: string, severity: string, message: string, file?: string, line?: int}>}}>|string  $nodeMetadata  a string writes a deliberately corrupt field
     */
    private function writeCacheFile(string $fingerprint, array $edges, array|string $nodeMetadata = []): void
    {
        mkdir($this->cacheDirectory, recursive: true);
        file_put_contents($this->cacheFile(), json_encode([
            'fingerprint' => $fingerprint,
            'edges' => $edges,
            'hasUnparseableFiles' => false,
            'unresolvedDispatchSites' => [],
            'nodeMetadata' => $nodeMetadata,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * The write-then-rename temp files left in the cache directory, if any.
     *
     * @return list<string>
     */
    private function tempFiles(): array
    {
        $found = glob("{$this->cacheDirectory}/*.tmp");

        return $found === false ? [] : $found;
    }

    /** @return list<array{source: string, target: string, type: string}> */
    private function markerEdges(): array
    {
        return [['source' => 'marker::A', 'target' => 'marker::B', 'type' => 'call']];
    }

    #[Test]
    public function warm_builds_stores_and_reports_what_landed(): void
    {
        $result = $this->cache()->warm($this->projectRoot);

        $this->assertTrue($result->built, 'Nothing was cached, so this call had to build it.');
        $this->assertTrue($result->written);
        $this->assertSame($this->cacheFile(), $result->file);
        $this->assertSame(filesize($this->cacheFile()), $result->bytes);
        $this->assertSame($this->cache()->fingerprint($this->projectRoot), $result->fingerprint);
        $this->assertGreaterThan(0, $result->seconds);
    }

    #[Test]
    public function warm_reports_an_already_current_entry_as_written_but_not_built(): void
    {
        // The distinction a deploy log needs: `written` says a usable entry exists, `built` says
        // this call produced it. A hit and a successful bake both leave `written` true.
        $this->cache()->warm($this->projectRoot);

        $second = new GraphCache(new CodeGraphBuilder())->warm($this->projectRoot);

        $this->assertTrue($second->written);
        $this->assertFalse($second->built);
    }

    #[Test]
    public function warm_rebuilds_an_entry_deleted_under_a_process_holding_the_memo(): void
    {
        // graph() serves the memo without touching disk — right for a report, and wrong for a warm,
        // which would then never notice that the entry it exists to guarantee had been deleted.
        // warm() sidesteps that by not calling graph() at all: it reads the DISK, misses, and
        // rebuilds. The memo below is what a report would have been served.
        $cache = $this->cache();
        $cache->graph($this->projectRoot);
        unlink($this->cacheFile());

        $result = $cache->warm($this->projectRoot);

        $this->assertTrue($result->written, 'The entry was gone; warm must put it back.');
        $this->assertTrue($result->built);
        $this->assertFileExists($this->cacheFile());
    }

    #[Test]
    public function warm_reports_failure_when_the_entry_cannot_be_written(): void
    {
        // The one mode where write()'s deliberate silence is wrong: writing IS the job.
        mkdir($this->cacheDirectory, recursive: true);
        mkdir($this->cacheFile());

        $result = $this->cache()->warm($this->projectRoot);

        $this->assertFalse($result->written, 'The rename cannot land on a directory.');
        $this->assertTrue($result->built, 'The build still ran; only storing it failed.');

        rmdir($this->cacheFile());
    }

    #[Test]
    public function inspect_reports_a_current_entry_as_a_match(): void
    {
        $this->cache()->warm($this->projectRoot);

        $status = new GraphCache(new CodeGraphBuilder())->inspect($this->projectRoot);

        $this->assertTrue($status->matches);
        $this->assertNull($status->reason);
        $this->assertFalse($status->corrupt);
        $this->assertSame($status->fingerprint, $status->storedFingerprint);
    }

    #[Test]
    public function inspect_names_the_differing_non_file_input_rather_than_reporting_that_one_differs(): void
    {
        // The whole point of the mode, and the answer to the deploy question the cache cannot
        // otherwise be asked: a build container and a runtime container on different PHP patch
        // releases miss forever, silently. `php (X → Y)` is that made visible.
        $this->cache()->warm($this->projectRoot);

        $entry = $this->storedEntry();
        $this->assertIsArray($entry['inputs']);
        $inputs = $entry['inputs'];
        $this->assertIsArray($inputs['nonFile']);
        $inputs['nonFile']['php'] = '0.0.0-not-this-runtime';
        $entry['inputs'] = $inputs;
        $entry['fingerprint'] = 'deliberately-not-the-live-one';
        file_put_contents($this->cacheFile(), json_encode($entry, JSON_THROW_ON_ERROR));

        $status = new GraphCache(new CodeGraphBuilder())->inspect($this->projectRoot);

        $this->assertFalse($status->matches);
        $this->assertSame('inputs-changed', $status->reason);
        $this->assertStringContainsString('php (0.0.0-not-this-runtime → ', (string) $status->detail);
        $this->assertSame('deliberately-not-the-live-one', $status->storedFingerprint);
    }

    #[Test]
    public function inspect_reports_an_absent_entry_as_absent_not_as_stale(): void
    {
        $status = $this->cache()->inspect($this->projectRoot);

        $this->assertFalse($status->matches);
        $this->assertSame('no-cache-entry', $status->reason);
        $this->assertFalse($status->corrupt, 'An absent entry fixes itself on the next warm.');
        $this->assertNull($status->storedFingerprint);
    }

    #[Test]
    public function inspect_separates_a_corrupt_entry_from_a_stale_one(): void
    {
        // The finding that forced ReadOutcome to carry a reason. mergeBase() validates `brainGraph`
        // and `inputs`; read() also validates the edges, metadata and dispatch sites. So an entry
        // with CURRENT inputs and a broken edge list compares equal on the record while failing the
        // read — and reporting "the fingerprint differs" would be a false statement about a file
        // whose fingerprint is fine, pointing at a rebuild that will not fix it.
        $cache = $this->cache();
        $cache->warm($this->projectRoot);

        $entry = $this->storedEntry();
        $entry['edges'] = 'not a list of edges at all';
        file_put_contents($this->cacheFile(), json_encode($entry, JSON_THROW_ON_ERROR));

        $status = new GraphCache(new CodeGraphBuilder())->inspect($this->projectRoot);

        $this->assertFalse($status->matches);
        $this->assertTrue($status->corrupt);
        $this->assertSame('edges-rejected', $status->reason);
    }

    #[Test]
    public function inspect_names_which_part_of_a_corrupt_payload_was_rejected(): void
    {
        // Three separate whole-entry conditions, and the third is the one that matters most: a
        // malformed dispatch-site list must never coalesce to "no sites", which reads as no
        // unfollowable dispatch and lets a selection report determinable when it is not. A reporter
        // that collapsed these into one reason would hide which of them the file actually failed.
        foreach (['nodeMetadata' => 'metadata-rejected', 'unresolvedDispatchSites' => 'dispatch-sites-rejected'] as $key => $expected) {
            $this->cache()->warm($this->projectRoot);

            $entry = $this->storedEntry();
            $entry[$key] = 'not a valid ' . $key;
            file_put_contents($this->cacheFile(), json_encode($entry, JSON_THROW_ON_ERROR));

            $status = new GraphCache(new CodeGraphBuilder())->inspect($this->projectRoot);

            $this->assertFalse($status->matches, "A rejected {$key} must not read as a hit.");
            $this->assertTrue($status->corrupt);
            $this->assertSame($expected, $status->reason);
        }
    }

    #[Test]
    public function neither_a_build_nor_a_hit_reports_itself_as_a_repair(): void
    {
        // `repaired` covers one narrow race: the entry reads fine, then is gone or unusable by the
        // read-back a moment later, and this call is holding the graph that fixes it. Every ordinary
        // path must leave it false, or a deploy log would claim a repair that never happened.
        //
        // The race itself is not reproducible without two processes interleaving between two reads
        // microseconds apart, so this pins the invariant around it rather than the race.
        $cache = $this->cache();

        $built = $cache->warm($this->projectRoot);
        $this->assertTrue($built->built);
        $this->assertFalse($built->repaired);

        $hit = new GraphCache(new CodeGraphBuilder())->warm($this->projectRoot);
        $this->assertFalse($hit->built);
        $this->assertFalse($hit->repaired);
        $this->assertTrue($hit->written);
    }

    #[Test]
    public function inspect_reports_an_undecodable_entry_as_unreadable(): void
    {
        mkdir($this->cacheDirectory, recursive: true);
        file_put_contents($this->cacheFile(), '{ this is not json');

        $status = $this->cache()->inspect($this->projectRoot);

        $this->assertFalse($status->matches);
        $this->assertTrue($status->corrupt, 'It is there and does not decode, so it repeats every run.');
        $this->assertSame('cache-unreadable', $status->reason);
    }

    #[Test]
    public function inspect_reports_a_changed_source_file_as_a_miss(): void
    {
        $this->cache()->warm($this->projectRoot);
        file_put_contents("{$this->projectRoot}/app/Services/Alpha.php", "<?php\n\nnamespace App\\Services;\n\nclass Alpha\n{\n    public function run(): void {}\n    public function added(): void {}\n}\n");

        $status = new GraphCache(new CodeGraphBuilder())->inspect($this->projectRoot);

        $this->assertFalse($status->matches);
        $this->assertFalse($status->corrupt);
        // `not-in-provenance`, not a generic mismatch: this fixture's changed file is one the
        // previous Brain graph attributes nothing to, which is a different fact from "it changed"
        // and the one a reader needs. The detail names the file either way.
        $this->assertSame('not-in-provenance', $status->reason);
        $this->assertStringContainsString('Alpha.php', (string) $status->detail);
    }

    #[Test]
    public function inspect_builds_nothing_and_writes_nothing(): void
    {
        // A deploy step runs this to decide whether the bake worked. If it could write, it would be
        // warming the cache it is supposed to be reporting on — and a green check would prove
        // nothing about the entry the deploy actually produced.
        $this->cache()->warm($this->projectRoot);
        $before = (string) file_get_contents($this->cacheFile());
        $mtime = filemtime($this->cacheFile());

        new GraphCache(new CodeGraphBuilder())->inspect($this->projectRoot);

        $this->assertSame($before, (string) file_get_contents($this->cacheFile()));
        $this->assertSame($mtime, filemtime($this->cacheFile()));
    }

    #[Test]
    public function inspect_leaves_no_entry_behind_when_there_was_none(): void
    {
        $this->cache()->inspect($this->projectRoot);

        $this->assertFileDoesNotExist($this->cacheFile(), 'A check on a cold cache must not warm it.');
    }

    #[Test]
    public function a_write_that_cannot_be_renamed_leaves_the_previous_entry_intact(): void
    {
        // The cache is an optimisation everywhere except a deliberate bake, so a failed write has
        // always been swallowed. What must NOT happen is the swallow taking the good entry with it:
        // a run that fails to write is a run that costs a rebuild, not one that destroys the entry
        // the last run produced.
        $cache = $this->cache();
        $this->writeCacheFile($cache->fingerprint($this->projectRoot), $this->markerEdges());
        $intact = (string) file_get_contents($this->cacheFile());

        // A directory where the entry belongs: the temp file writes fine, the rename cannot land.
        unlink($this->cacheFile());
        mkdir($this->cacheFile());

        // A changed input forces a miss, so the build runs and the write is attempted.
        file_put_contents("{$this->projectRoot}/app/Services/Beta.php", "<?php\n\nnamespace App\\Services;\n\nclass Beta {}\n");
        new GraphCache(new CodeGraphBuilder())->graph($this->projectRoot);

        $this->assertDirectoryExists($this->cacheFile());
        $this->assertSame([], $this->tempFiles(), 'A failed write must not leave a .tmp behind — nothing reads it and nothing else cleans it up.');

        rmdir($this->cacheFile());
        file_put_contents($this->cacheFile(), $intact);
        $this->assertSame($intact, (string) file_get_contents($this->cacheFile()));
    }

    #[Test]
    public function a_partially_written_entry_is_never_renamed_over_a_good_one(): void
    {
        // The guard this pins: file_put_contents() returns the byte COUNT it managed, not a success
        // flag, so a full disk or a killed process returns a short count. Renaming that truncated
        // JSON over the previous entry trades a rebuild for a permanent `cache-unreadable` — it
        // decodes to nothing on every subsequent run until someone deletes the file by hand.
        //
        // A short write cannot be forced through the real filesystem, so this asserts the invariant
        // the guard exists to hold: whatever lands at the cache path decodes, and it decodes to an
        // entry whose fingerprint matches what a read will look for.
        $cache = $this->cache();
        $cache->graph($this->projectRoot);

        $raw = (string) file_get_contents($this->cacheFile());
        $decoded = json_decode($raw, associative: true);

        $this->assertIsArray($decoded, 'A written entry must always decode; a truncated one would not.');
        $this->assertSame($cache->fingerprint($this->projectRoot), $decoded['fingerprint'] ?? null);
        $this->assertSame([], $this->tempFiles(), 'A successful write must not leave its temp file behind either.');
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
    public function the_fingerprint_changes_when_bootstrap_app_changes(): void
    {
        // bootstrap/app.php feeds middleware-alias resolution (and thus route gates) — editing it
        // must invalidate the cache like any other build input.
        mkdir("{$this->projectRoot}/bootstrap", recursive: true);
        file_put_contents("{$this->projectRoot}/bootstrap/app.php", "<?php\n// v1\n");
        $before = $this->cache()->fingerprint($this->projectRoot);

        file_put_contents("{$this->projectRoot}/bootstrap/app.php", "<?php\n// v2\n");

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
    public function the_fingerprint_changes_when_the_second_hop_walk_is_switched_off(): void
    {
        // The walk decides which bodies are read, so on and off are two different graphs; sharing a
        // cache entry between them would serve one where the config asks for the other.
        $before = $this->cache()->fingerprint($this->projectRoot);

        config()->set('richter.second_hop', false);

        $this->assertNotSame($before, $this->cache()->fingerprint($this->projectRoot));
    }

    #[Test]
    public function the_fingerprint_changes_between_the_two_walk_scopes(): void
    {
        // `true` and `'class'` read different amounts of each target class, so an entry built at one
        // must never be served at the other — it would under-report reach with no sign of it.
        $before = $this->cache()->fingerprint($this->projectRoot);

        config()->set('richter.second_hop', 'class');

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

        $this->assertSame([
            'edges' => $this->markerEdges(),
            'hasUnparseableFiles' => false,
            'unresolvedDispatchSites' => [],
            'nodeMetadata' => [],
        ], $graph->toArray());
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
    public function a_malformed_dispatch_site_list_fails_the_whole_read(): void
    {
        // The dangerous coalesce: treating a broken list as "no sites" clears the unfollowable-
        // dispatch taint, and a selection that should have been undeterminable reports determinable
        // and runs fewer tests. The fingerprint is no defence — it lives in this same entry and can
        // match while a later key is corrupt, which is exactly what this fixture is.
        $cache = $this->cache();
        $cache->graph($this->projectRoot);

        $stored = $this->storedEntry();
        $stored['unresolvedDispatchSites'] = [['file' => 'app/Services/Importer.php', 'line' => 'twelve']];
        file_put_contents($this->cacheFile(), json_encode($stored, JSON_THROW_ON_ERROR));

        $rebuilt = new GraphCache(new CodeGraphBuilder())->graph($this->projectRoot);

        // Rebuilt from source, and the rewritten entry is well-formed again.
        $this->assertSame([], $rebuilt->unresolvedDispatchSites());
        $this->assertSame([], $this->storedEntry()['unresolvedDispatchSites']);
    }

    #[Test]
    public function a_pre_split_format_cache_entry_is_a_miss_not_a_wrong_flag_hit(): void
    {
        // Simulates a cache entry written by the pre-036 combined-flag code: no `hasUnparseableFiles`
        // key at all, and `hasUnresolvedDispatches` folding S1||S2 together (here `true`, as either an
        // unparseable file or a variable dispatch would have set it). Its fingerprint is necessarily
        // stale after the FORMAT_VERSION 3 → 4 bump, so this must read as a MISS and rebuild — never
        // revive with `hasUnparseableFiles` silently defaulted `false` while the stale combined `true`
        // rides along as `hasUnresolvedDispatches` (which would under-select: a change reaching no
        // dispatchable would wrongly narrow even though the entry might really be S1-tainted).
        mkdir($this->cacheDirectory, recursive: true);
        file_put_contents($this->cacheFile(), json_encode([
            'fingerprint' => 'pre-split-format-fingerprint',
            'edges' => $this->markerEdges(),
            'hasUnresolvedDispatches' => true,
            'nodeMetadata' => [],
        ], JSON_THROW_ON_ERROR));

        $cache = $this->cache();
        $graph = $cache->graph($this->projectRoot);

        // Rebuilt from source — the stale marker graph is not served.
        $this->assertNotContains($this->markerEdges()[0], $graph->toArray()['edges']);
        // The fresh build's correctly-split flags for this dispatch/parse-free fixture — not a
        // revival of the stale combined `true`.
        $this->assertFalse($graph->hasUnparseableFiles());
        $this->assertFalse($graph->hasUnresolvedDispatches());
        // The rewritten entry now carries both split flags and the current fingerprint.
        $stored = json_decode((string) file_get_contents($this->cacheFile()), associative: true);
        $this->assertIsArray($stored);
        $this->assertSame($cache->fingerprint($this->projectRoot), $stored['fingerprint']);
        $this->assertArrayHasKey('hasUnparseableFiles', $stored);
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
    public function node_metadata_round_trips_through_the_cache(): void
    {
        $cache = $this->cache();
        $security = ['exposure' => 'public', 'riskLevel' => 'high', 'issues' => [
            ['type' => 'PUBLIC_WRITE', 'severity' => 'high', 'message' => 'POST route with no auth middleware'],
        ]];
        $this->writeCacheFile($cache->fingerprint($this->projectRoot), $this->markerEdges(), [
            'marker::A' => ['file' => 'app/A.php', 'line' => 3, 'security' => $security],
        ]);

        $graph = $cache->graph($this->projectRoot);

        $this->assertSame(['file' => 'app/A.php', 'line' => 3], $graph->locationOf('marker::A'));
        $this->assertSame($security, $graph->securityOf('marker::A'));
    }

    #[Test]
    public function a_cache_entry_with_a_non_map_metadata_field_reads_as_a_miss(): void
    {
        $cache = $this->cache();
        $this->writeCacheFile($cache->fingerprint($this->projectRoot), $this->markerEdges(), 'corrupt');

        $graph = $cache->graph($this->projectRoot);

        // The marker edges must not be served — the whole entry is a miss, rebuilt from source.
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
            'hasUnparseableFiles' => false,
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
    public function an_unparseable_app_file_sets_only_has_unparseable_files_not_has_unresolved_dispatches(): void
    {
        // A syntax error the pinned parser cannot read at all (S1) — no dispatch verb anywhere in
        // the fixture, so the dispatch-only flag must stay false; conflating the two would make an
        // unrelated unparseable file masquerade as a scopeable dispatch signal (plan 036).
        file_put_contents("{$this->projectRoot}/app/Services/Broken.php", "<?php\n\nnamespace App\\Services;\n\nclass Broken {\n");

        $graph = $this->cache()->graph($this->projectRoot);

        $this->assertTrue($graph->hasUnparseableFiles());
        $this->assertFalse($graph->hasUnresolvedDispatches());

        // Round-trips through a fresh process reading the just-written cache from disk.
        $reread = new GraphCache(new CodeGraphBuilder())->graph($this->projectRoot);
        $this->assertTrue($reread->hasUnparseableFiles());
        $this->assertFalse($reread->hasUnresolvedDispatches());
    }

    #[Test]
    public function a_variable_dispatch_with_every_file_parseable_sets_only_has_unresolved_dispatches(): void
    {
        // A dispatch verb whose argument is a variable (S2) — its target is unresolvable but bounded
        // to "a dispatchable"; every file in the fixture parses fine, so the global S1 flag must
        // stay false.
        file_put_contents(
            "{$this->projectRoot}/app/Services/Dispatcher.php",
            "<?php\n\nnamespace App\\Services;\n\nclass Dispatcher\n{\n    public function run(\$job): void\n    {\n        dispatch(\$job);\n    }\n}\n",
        );

        $graph = $this->cache()->graph($this->projectRoot);

        $this->assertFalse($graph->hasUnparseableFiles());
        $this->assertTrue($graph->hasUnresolvedDispatches());
        // The site itself, not just the flag: this is what a report sends a reader to, so a cache
        // that revived the flag while losing the location would leave the reason unactionable again.
        $this->assertSame(
            [['file' => 'app/Services/Dispatcher.php', 'line' => 9, 'dispatcher' => 'App\Services\Dispatcher::run']],
            $graph->unresolvedDispatchSites(),
        );

        // Round-trips through a fresh process reading the just-written cache from disk.
        $reread = new GraphCache(new CodeGraphBuilder())->graph($this->projectRoot);
        $this->assertFalse($reread->hasUnparseableFiles());
        $this->assertTrue($reread->hasUnresolvedDispatches());
        $this->assertSame($graph->unresolvedDispatchSites(), $reread->unresolvedDispatchSites());
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
