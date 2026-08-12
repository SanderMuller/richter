<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Feature;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Tests\TestCase;

/**
 * `richter:trace` against the fixture project. Strict-direction semantics are the
 * contract under test: a found chain reads from-first/to-last, a miss reports the
 * upstream extent of the TO side (never a pointer toward FROM), and an unresolvable
 * symbol errors instead of masquerading as "no path".
 */
final class TraceCommandTest extends TestCase
{
    #[Test]
    public function trace_finds_a_call_direction_path(): void
    {
        $this->useFixtureProject();

        $this->runArtisan('richter:trace', ['from' => 'ReviewController', 'to' => 'App\\Jobs\\ProcessPostJob'])
            ->expectsOutputToContain('Path from "ReviewController" to "App\\Jobs\\ProcessPostJob"')
            ->assertSuccessful();
    }

    #[Test]
    public function trace_depth_decides_whether_a_deeper_path_is_found_at_all(): void
    {
        // The point of the flag: a miss at one depth is not a miss at another. Without it, "no path"
        // and "path deeper than the limit" read identically, and the deepest-caller note that hints
        // at the difference has no follow-up question the tool can answer. This path is three hops.
        $this->useFixtureProject();

        $this->runArtisan('richter:trace', ['from' => 'route::GET::/posts/{post}/reviews', 'to' => 'App\Jobs\ProcessPostJob', '--depth' => '2'])
            ->doesntExpectOutputToContain('Path from')
            ->assertSuccessful();

        $this->runArtisan('richter:trace', ['from' => 'route::GET::/posts/{post}/reviews', 'to' => 'App\Jobs\ProcessPostJob', '--depth' => '3'])
            ->expectsOutputToContain('Path from')
            ->assertSuccessful();
    }

    #[Test]
    public function trace_rejects_a_depth_that_is_not_a_positive_number(): void
    {
        $this->useFixtureProject();

        // Zero would make the walk find nothing at all, and silently: a depth that answers "no path"
        // to every question is worse than being told the option was wrong. Reported before the graph
        // is built, so a mistyped flag does not cost a scan first.
        $this->runArtisan('richter:trace', ['from' => 'ReviewController', 'to' => 'App\Models\Post', '--depth' => '0'])
            ->expectsOutputToContain('--depth option must be a whole number of 1 or more')
            ->doesntExpectOutputToContain('Resolving code graph')
            ->assertFailed();
    }

    #[Test]
    public function trace_json_carries_the_declared_shape_on_a_found_path(): void
    {
        $this->useFixtureProject();

        $document = $this->traceJson('ReviewController', 'App\\Jobs\\ProcessPostJob');

        $this->assertTrue($document['found']);
        $this->assertSame(['from', 'to', 'resolvedFrom', 'resolvedTo', 'found', 'path'], array_keys($document));

        $path = $this->pathFrom($document);
        $this->assertNotSame([], $path);
        $this->assertStringContainsString('ReviewController', $this->stringAt($path[0], 'node'));
        // The chain ends on a to-side node — the class node or one of its members,
        // whichever the shortest chain reaches (the dispatch edge targets ::handle).
        $last = $this->lastHop($path);
        $this->assertContains($this->stringAt($last, 'node'), $this->listAt($document, 'resolvedTo'));
        $this->assertSame('', $this->stringAt($last, 'via'));
    }

    #[Test]
    public function trace_reports_a_reverse_only_path_as_not_found_with_the_upstream_extent(): void
    {
        // The controller reaches the job, never the other way round — strict direction
        // must NOT fall back, and the job's callers give the furthest-reached diagnostic.
        $this->useFixtureProject();

        $document = $this->traceJson('App\\Jobs\\ProcessPostJob', 'ReviewController');

        $this->assertFalse($document['found']);
        $this->assertSame([], $document['path']);
        $this->assertArrayHasKey('furthestReached', $document);

        $furthest = $document['furthestReached'];
        $this->assertIsArray($furthest);
        $this->assertArrayHasKey('node', $furthest);
        $this->assertArrayHasKey('depth', $furthest);
    }

    #[Test]
    public function trace_omits_the_upstream_extent_when_the_target_has_no_callers(): void
    {
        // Routes are graph roots: nothing calls a route, so the diagnostic must be
        // omitted rather than fabricated.
        $this->useFixtureProject();

        $document = $this->traceJson('App\\Models\\Post', 'dashboard/search');

        $this->assertFalse($document['found']);
        $this->assertArrayNotHasKey('furthestReached', $document);
    }

    #[Test]
    public function trace_text_output_hints_the_swap_on_a_miss(): void
    {
        $this->useFixtureProject();

        $this->runArtisan('richter:trace', ['from' => 'App\\Jobs\\ProcessPostJob', 'to' => 'ReviewController'])
            ->expectsOutputToContain('Swap the arguments to query the reverse direction.')
            ->assertSuccessful();
    }

    #[Test]
    public function trace_resolves_a_same_node_pair_to_a_single_hop_path(): void
    {
        $this->useFixtureProject();

        $document = $this->traceJson('App\\Models\\Post', 'App\\Models\\Post');

        $this->assertTrue($document['found']);

        $path = $this->pathFrom($document);
        $this->assertSame('', $this->stringAt($this->lastHop($path), 'via'));
    }

    #[Test]
    public function trace_picks_the_shortest_chain_across_multi_candidate_resolution(): void
    {
        // "Post" resolves to many nodes (Post, PostPolicy, PublishPostJob, …); the
        // result must still be one chain, ending on a to-side node.
        $this->useFixtureProject();

        $document = $this->traceJson('ReviewController', 'Post');

        $this->assertTrue($document['found']);
        $this->assertNotSame([], $this->listAt($document, 'resolvedTo'));

        $path = $this->pathFrom($document);
        $this->assertStringContainsString('Post', $this->stringAt($this->lastHop($path), 'node'));
    }

    #[Test]
    public function trace_errors_on_an_unresolvable_symbol(): void
    {
        $this->useFixtureProject();

        $this->runArtisan('richter:trace', ['from' => 'Zzz\\Nonexistent\\Symbol', 'to' => 'App\\Models\\Post'])
            ->assertFailed();
    }

    #[Test]
    public function trace_error_names_the_nearest_nodes_on_a_typo(): void
    {
        // A trace needs BOTH symbols to resolve, so a typo is the likeliest miss here —
        // the error has to be a lead, the same one `richter:impact` renders. Asserted on
        // the JSON error: the console block hard-wraps at terminal width, so a node id
        // can be split across lines there.
        $this->useFixtureProject();

        $this->withoutMockingConsoleOutput();
        $exitCode = Artisan::call('richter:trace', ['from' => 'App\\Http\\Controllers\\ReviewControler', 'to' => 'App\\Models\\Post', '--json' => true]);

        $document = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exitCode);
        $this->assertIsArray($document);
        $error = $document['error'];
        $this->assertIsString($error);
        $this->assertStringContainsString('Nearest graph nodes:', $error);
        $this->assertStringContainsString('ReviewController', $error);
    }

    #[Test]
    public function trace_error_reports_the_scanned_node_count_when_nothing_resembles_the_symbol(): void
    {
        // Distinguishes "wrong name" from "the graph is empty" — without it, a
        // misconfigured project reads identically to a typo.
        $this->useFixtureProject();

        $this->withoutMockingConsoleOutput();
        Artisan::call('richter:trace', ['from' => 'Zzz\\Nonexistent\\Symbol', 'to' => 'App\\Models\\Post', '--json' => true]);

        $document = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertIsArray($document);
        $error = $document['error'];
        $this->assertIsString($error);
        $this->assertStringContainsString('none share an identifier with it', $error);
        $this->assertDoesNotMatchRegularExpression('/Scanned 0 graph nodes/', $error);
    }

    #[Test]
    public function trace_json_error_is_a_single_parseable_document(): void
    {
        $this->useFixtureProject();

        $this->withoutMockingConsoleOutput();
        $exitCode = Artisan::call('richter:trace', ['from' => 'Zzz\\Nonexistent\\Symbol', 'to' => 'App\\Models\\Post', '--json' => true]);

        $document = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exitCode);
        $this->assertIsArray($document);
        $this->assertArrayHasKey('error', $document);
        $error = $document['error'];
        $this->assertIsString($error);
        $this->assertStringContainsString('Zzz\\Nonexistent\\Symbol', $error);
    }

    #[Test]
    public function trace_rejects_json_combined_with_markdown_as_a_json_error(): void
    {
        $this->withoutMockingConsoleOutput();
        $exitCode = Artisan::call('richter:trace', ['from' => 'A', 'to' => 'B', '--json' => true, '--markdown' => true]);

        $document = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exitCode);
        $this->assertIsArray($document);
        $this->assertArrayHasKey('error', $document);
    }

    #[Test]
    public function trace_markdown_renders_the_chain_inline(): void
    {
        $this->useFixtureProject();

        $this->withoutMockingConsoleOutput();
        $exitCode = Artisan::call('richter:trace', ['from' => 'ReviewController', 'to' => 'App\\Jobs\\ProcessPostJob', '--markdown' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('## Richter trace:', $output);
        $this->assertStringContainsString('→(', $output);
    }

    private function useFixtureProject(): void
    {
        $app = $this->app;
        $this->assertInstanceOf(Application::class, $app);
        $app->setBasePath(self::fixtureProjectPath());
    }

    /**
     * The narrowed `path` list — each hop asserted to be an array before use.
     *
     * @param  array<mixed>  $document
     * @return list<array<mixed>>
     */
    private function pathFrom(array $document): array
    {
        $path = $document['path'] ?? null;
        $this->assertIsArray($path);

        $hops = [];

        foreach ($path as $hop) {
            $this->assertIsArray($hop);
            $hops[] = $hop;
        }

        return $hops;
    }

    /**
     * @param  array<mixed>  $document
     * @return list<mixed>
     */
    private function listAt(array $document, string $key): array
    {
        $value = $document[$key] ?? null;
        $this->assertIsArray($value);

        return array_values($value);
    }

    /**
     * @param  list<array<mixed>>  $path
     * @return array<mixed>
     */
    private function lastHop(array $path): array
    {
        $this->assertNotSame([], $path);

        return $path[array_key_last($path)];
    }

    /** @param  array<mixed>  $hop */
    private function stringAt(array $hop, string $key): string
    {
        $value = $hop[$key] ?? null;
        $this->assertIsString($value);

        return $value;
    }

    /** @return array<mixed> */
    private function traceJson(string $from, string $to): array
    {
        $this->withoutMockingConsoleOutput();
        Artisan::call('richter:trace', ['from' => $from, 'to' => $to, '--json' => true]);

        $document = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($document);

        return $document;
    }

    /** @param  array<string, mixed>  $parameters */
    private function runArtisan(string $command, array $parameters = []): PendingCommand
    {
        $pending = $this->artisan($command, $parameters);

        $this->assertInstanceOf(PendingCommand::class, $pending);

        return $pending;
    }
}
