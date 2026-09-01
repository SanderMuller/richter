<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Feature;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Tests\TestCase;

/**
 * `richter:locate` against the fixture project. Two contracts are under test that no other command
 * has: a miss is a SUCCESS (it answers the question that was asked), and `--json` is COMPLETE by
 * default — the cap is the MCP surface's, because a script has a disk, not a context window.
 */
final class LocateCommandTest extends TestCase
{
    #[Test]
    public function locate_finds_a_symbol_and_prints_where_it_is(): void
    {
        $this->useFixtureProject();

        $this->runArtisan('richter:locate', ['--symbol' => 'App\Models\Post'])
            ->expectsOutputToContain('app/Models/Post.php')
            ->assertSuccessful();
    }

    #[Test]
    public function locate_lists_what_a_file_defines(): void
    {
        $this->useFixtureProject();

        $this->runArtisan('richter:locate', ['--file' => 'app/Models/Post.php'])
            ->expectsOutputToContain('defined in "app/Models/Post.php"')
            ->assertSuccessful();
    }

    #[Test]
    public function a_miss_is_an_answer_and_exits_successfully(): void
    {
        // The opposite of `richter:trace`, which errors on an unresolvable symbol because an empty
        // trace would read as "no path". "Nothing named X, nearest are Y and Z" has no such reading.
        $this->useFixtureProject();
        $this->withoutMockingConsoleOutput();

        $exitCode = Artisan::call('richter:locate', ['--symbol' => 'App\Models\Pots']);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('No graph nodes matched "App\Models\Pots".', $output);
        // A miss without a lead is a dead end; the nearest ids are what make it a next step.
        $this->assertStringContainsString('Nearest graph nodes:', $output);
    }

    #[Test]
    public function json_returns_every_match_because_a_cli_document_is_complete(): void
    {
        // The contract this command must not break: BoundedPresenter caps the MCP surface only, and
        // a `--json` document truncated by default would be that break in effect.
        $this->useFixtureProject();
        $this->withoutMockingConsoleOutput();

        $exitCode = Artisan::call('richter:locate', ['--symbol' => 'Post', '--json' => true]);
        $document = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertIsArray($document);
        $this->assertArrayNotHasKey('limit', $document);
        $this->assertFalse($document['bounded']);

        $total = $document['total'];
        $matches = $document['matches'];
        $this->assertIsInt($total);
        $this->assertIsArray($matches);
        $this->assertGreaterThan(15, $total);
        $this->assertCount($total, $matches);
    }

    #[Test]
    public function a_limit_caps_the_document_and_says_so(): void
    {
        $this->useFixtureProject();
        $this->withoutMockingConsoleOutput();

        Artisan::call('richter:locate', ['--symbol' => 'Post', '--json' => true, '--limit' => '2']);
        $document = json_decode(Artisan::output(), true);

        $this->assertIsArray($document);
        $this->assertSame(2, $document['limit']);
        $this->assertTrue($document['bounded']);
        $this->assertGreaterThan(2, $document['total']);

        $matches = $document['matches'];
        $this->assertIsArray($matches);
        $this->assertCount(2, $matches);
    }

    #[Test]
    public function locate_renders_markdown_for_a_pull_request(): void
    {
        $this->useFixtureProject();
        $this->withoutMockingConsoleOutput();

        Artisan::call('richter:locate', ['--symbol' => 'App\Models\Post', '--markdown' => true]);

        $this->assertStringContainsString('## Richter locate:', Artisan::output());
    }

    #[Test]
    public function passing_neither_target_or_both_is_a_usage_error(): void
    {
        $this->useFixtureProject();

        foreach ([[], ['--symbol' => 'Post', '--file' => 'app/Models/Post.php'], ['--symbol' => '']] as $parameters) {
            $this->runArtisan('richter:locate', $parameters)
                ->expectsOutputToContain('Pass exactly one of --symbol and --file.')
                // Refused before the graph is built: being told you mistyped a flag after a
                // multi-second build is the one avoidable cost here.
                ->doesntExpectOutputToContain('Resolving code graph')
                ->assertFailed();
        }
    }

    #[Test]
    public function a_limit_that_is_not_a_positive_number_is_refused_before_the_graph_is_built(): void
    {
        $this->useFixtureProject();

        foreach (['0', '-1', 'many'] as $limit) {
            $this->runArtisan('richter:locate', ['--symbol' => 'Post', '--limit' => $limit])
                ->expectsOutputToContain('--limit option must be a whole number of 1 or more')
                ->doesntExpectOutputToContain('Resolving code graph')
                ->assertFailed();
        }
    }

    #[Test]
    public function a_usage_error_in_json_mode_is_still_a_json_document(): void
    {
        // JSON mode owns stdout even when the caller got the flags wrong: a script parsing this
        // must never receive plain text where a document was promised.
        $this->useFixtureProject();
        $this->withoutMockingConsoleOutput();

        $exitCode = Artisan::call('richter:locate', ['--json' => true]);

        $this->assertSame(1, $exitCode);
        $this->assertSame(['error' => 'Pass exactly one of --symbol and --file.'], json_decode(Artisan::output(), true));
    }

    #[Test]
    public function json_and_markdown_together_are_refused_as_a_json_document(): void
    {
        $this->useFixtureProject();
        $this->withoutMockingConsoleOutput();

        $exitCode = Artisan::call('richter:locate', ['--symbol' => 'Post', '--json' => true, '--markdown' => true]);

        $this->assertSame(1, $exitCode);
        $this->assertSame(['error' => 'The --json and --markdown options are mutually exclusive.'], json_decode(Artisan::output(), true));
    }

    #[Test]
    public function a_project_with_nothing_in_it_is_an_empty_answer_not_an_error(): void
    {
        // Traced, not assumed: a base path that does not exist yields an EMPTY graph here, it does
        // not throw. So this pins the honest-degradation half — "scanned 0 nodes" rather than a
        // failure — and says nothing about the Throwable catch, which has no forcing seam: both
        // GraphCache and CodeGraphBuilder are final, so no double can be injected to make one throw.
        // The catch stays as defence in depth for a build that does throw for another reason.
        $app = $this->app;
        $this->assertInstanceOf(Application::class, $app);
        $app->setBasePath('/definitely-not-a-project-zzz');
        $this->withoutMockingConsoleOutput();

        $exitCode = Artisan::call('richter:locate', ['--symbol' => 'Post', '--json' => true]);
        $document = json_decode(Artisan::output(), true);

        $this->assertSame(0, $exitCode);
        $this->assertIsArray($document);
        $this->assertArrayNotHasKey('error', $document);
        $this->assertSame(0, $document['total']);
        $this->assertSame(0, $document['graphNodeCount']);
    }

    private function useFixtureProject(): void
    {
        $app = $this->app;
        $this->assertInstanceOf(Application::class, $app);
        $app->setBasePath(self::fixtureProjectPath());
    }

    /** @param  array<string, string|bool>  $parameters */
    private function runArtisan(string $command, array $parameters = []): PendingCommand
    {
        $pending = $this->artisan($command, $parameters);

        $this->assertInstanceOf(PendingCommand::class, $pending);

        return $pending;
    }
}
