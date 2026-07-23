<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Feature;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Support\GitProjectPaths;
use SanderMuller\Richter\Tests\TestCase;

/**
 * The one place `richter:benchmark` replays a REAL git history end-to-end — no faked git. Every other
 * benchmark test fakes the four git calls; this builds a throwaway repo containing the fixture project
 * with a genuine "bug" baseline commit and a one-line "fix" commit on top, then runs the command
 * unfaked so the whole chain (commit check → historical `git diff`/`git show` → member resolution →
 * present-day graph walk → verdict) is exercised against real plumbing.
 *
 * It also pins the project-root/git-root decoupling: the same replay must pass whether the Laravel app
 * IS the repo root (the common case) or sits in a SUBDIRECTORY of a larger repo (a monorepo). The
 * subdirectory case fails without {@see GitProjectPaths} re-rooting the
 * bare `app/…` pathspecs git resolves against the repo root, not the process cwd.
 */
final class BenchmarkReplayTest extends TestCase
{
    /** @var list<string> throwaway repo roots to remove after each test */
    private array $tempRepos = [];

    protected function tearDown(): void
    {
        foreach ($this->tempRepos as $repo) {
            File::deleteDirectory($repo);
        }

        $this->tempRepos = [];
        parent::tearDown();
    }

    #[Test]
    public function it_replays_a_signal_case_against_a_real_repo_at_the_root(): void
    {
        // App IS the repo root — the common case, and the regression guard that `--relative` and the
        // (empty) prefix leave real replays byte-for-byte unchanged there.
        [$appRoot, $fixSha] = $this->buildReplayRepo();

        $this->pointAppAt($appRoot);
        $this->configureCase($fixSha, expectSignal: true);

        $this->runBenchmark()
            ->expectsOutputToContain('PASS')
            ->expectsOutputToContain('Score: 1 passed, 0 failed of 1 fixtures.')
            ->assertSuccessful();
    }

    #[Test]
    public function it_replays_a_signal_case_when_the_app_is_a_subdirectory_of_the_repo(): void
    {
        // App is nested under packages/app/ — a monorepo shape. This is the decoupling proof: without
        // GitProjectPaths re-rooting the `git show {ref}:app/…` pathspecs (and `--relative` scoping
        // the diff), the historical sources come back empty and the case FAILs as unresolved.
        [$appRoot, $fixSha] = $this->buildReplayRepo('packages/app');

        $this->pointAppAt($appRoot);
        $this->configureCase($fixSha, expectSignal: true);

        $this->runBenchmark()
            ->expectsOutputToContain('PASS')
            ->expectsOutputToContain('Score: 1 passed, 0 failed of 1 fixtures.')
            ->assertSuccessful();
    }

    #[Test]
    public function it_replays_a_control_case_when_the_app_is_a_subdirectory_of_the_repo(): void
    {
        // A control (expect_signal: false) capped at high tolerates the reached route entry points and
        // clears the fixture-drift guard (its seed resolves a real node) — proving the control path
        // survives the subdirectory re-rooting too, not just the signal path.
        [$appRoot, $fixSha] = $this->buildReplayRepo('packages/app');

        $this->pointAppAt($appRoot);
        $this->configureCase($fixSha, expectSignal: false);

        $this->runBenchmark()
            ->expectsOutputToContain('PASS')
            ->expectsOutputToContain('Score: 1 passed, 0 failed of 1 fixtures.')
            ->assertSuccessful();
    }

    #[Test]
    public function affected_tests_fails_closed_on_an_untracked_app_file_when_the_app_is_a_subdirectory(): void
    {
        // The direct monorepo guard for the cardinal rule: an untracked (never git-add-ed) file under
        // the NESTED app must still force affected-tests to exit 2 (undetermined). git status prints it
        // repo-root-relative (`packages/app/app/…`); it must be re-rooted to `app/…` and surfaced,
        // never silently dropped. A sibling package's untracked file must be ignored.
        [$appRoot] = $this->buildReplayRepo('packages/app');
        $repoRoot = dirname($appRoot, 2); // …/packages/app → repo root

        file_put_contents("{$appRoot}/app/Models/UntrackedReport.php", "<?php\n\nnamespace App\\Models;\n\nclass UntrackedReport {}\n");
        File::ensureDirectoryExists("{$repoRoot}/packages/other/app");
        file_put_contents("{$repoRoot}/packages/other/app/Sibling.php", "<?php\n");

        $this->pointAppAt($appRoot);

        $this->withoutMockingConsoleOutput();
        $exitCode = Artisan::call('richter:affected-tests', ['--base' => 'HEAD', '--plain' => true]);
        $output = Artisan::output();

        $this->assertSame(2, $exitCode);
        $this->assertStringContainsString('app/Models/UntrackedReport.php', $output);
        // Surfaced base-relative (re-rooted), never the raw repo-root path; the sibling is dropped.
        $this->assertStringNotContainsString('packages/', $output);
        $this->assertStringNotContainsString('Sibling.php', $output);
    }

    #[Test]
    public function detect_changes_sees_a_working_tree_edit_when_the_app_is_a_subdirectory(): void
    {
        // HEAD/working-tree mode in a nested app — the local pre-commit loop in a monorepo. resolve()
        // must re-root the historical `git show` (baseSource) and read the working-tree file
        // (headSource, off disk) so an uncommitted edit under the nested app is detected.
        [$appRoot] = $this->buildReplayRepo('packages/app');

        $controller = "{$appRoot}/app/Http/Controllers/Post/ReviewController.php";
        file_put_contents($controller, str_replace(
            '$post->load([Post::REVIEWS]);',
            '$post->load([Post::REVIEWS, Post::COMMENTS]);',
            (string) file_get_contents($controller),
        ));

        $this->pointAppAt($appRoot);

        $this->withoutMockingConsoleOutput();
        $exitCode = Artisan::call('richter:detect-changes', ['--base' => 'HEAD', '--json' => true]);
        $decoded = json_decode(Artisan::output(), associative: true);

        $this->assertSame(0, $exitCode);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('changed', $decoded);
        $this->assertIsArray($decoded['changed']);
        $this->assertArrayHasKey('app/Http/Controllers/Post/ReviewController.php', $decoded['changed']);
    }

    /**
     * Build a throwaway git repo holding the fixture project (optionally nested under $appSubdir), with
     * a "bug" baseline commit and a one-line "fix" commit on top. The fix reverts a
     * `->withoutRelations()` regression inside `ReviewController::show()` — a member the fixture graph
     * resolves and walks up to its two routes (see CodeGraphBuilderTest), the same change the faked
     * benchmark tests replay.
     *
     * @return array{0: string, 1: string} [app root to point base_path() at, fix commit SHA]
     */
    private function buildReplayRepo(string $appSubdir = ''): array
    {
        $repoRoot = sys_get_temp_dir() . '/richter-replay-' . bin2hex(random_bytes(8));
        $appRoot = $appSubdir === '' ? $repoRoot : "{$repoRoot}/{$appSubdir}";
        $this->tempRepos[] = $repoRoot;

        File::copyDirectory(self::fixtureProjectPath(), $appRoot);

        $controller = "{$appRoot}/app/Http/Controllers/Post/ReviewController.php";
        $fixed = (string) file_get_contents($controller);
        $buggy = str_replace(
            'return ReviewResource::make($post);',
            'return ReviewResource::make($post->withoutRelations());',
            $fixed,
        );
        $this->assertNotSame($fixed, $buggy, 'the buggy edit did not apply — the fixture source changed');

        // Commit the buggy baseline (fix^), then the fix (HEAD) so `git diff fix^...fix` is the revert.
        $this->git($repoRoot, ['init', '-q']);
        $this->git($repoRoot, ['config', 'user.email', 'benchmark@example.test']);
        $this->git($repoRoot, ['config', 'user.name', 'Richter Benchmark']);
        $this->git($repoRoot, ['config', 'commit.gpgsign', 'false']);

        file_put_contents($controller, $buggy);
        $this->git($repoRoot, ['add', '-A']);
        $this->git($repoRoot, ['commit', '-q', '-m', 'Introduce the review relations regression']);

        file_put_contents($controller, $fixed);
        $this->git($repoRoot, ['add', '-A']);
        $this->git($repoRoot, ['commit', '-q', '-m', 'PROJ-1 Fix the review relations regression']);

        $head = Process::path($repoRoot)->run(['git', 'rev-parse', 'HEAD']);
        $this->assertTrue($head->successful(), 'could not resolve the fix commit SHA');

        return [$appRoot, trim($head->output())];
    }

    /** @param  list<string>  $args */
    private function git(string $dir, array $args): void
    {
        $result = Process::path($dir)->run(['git', ...$args]);

        $this->assertTrue(
            $result->successful(),
            'git ' . implode(' ', $args) . ' failed: ' . $result->errorOutput(),
        );
    }

    private function pointAppAt(string $appRoot): void
    {
        $app = $this->app;
        $this->assertInstanceOf(Application::class, $app);
        $app->setBasePath($appRoot);
    }

    private function configureCase(string $fixSha, bool $expectSignal): void
    {
        config()->set('richter.benchmark_cases', [[
            'key' => 'REPLAY-1',
            'fix_commit' => $fixSha,
            'bug_class' => 'review relations regression',
            'expect_signal' => $expectSignal,
            'max_risk' => 'high',
        ]]);
    }

    private function runBenchmark(): PendingCommand
    {
        $pending = $this->artisan('richter:benchmark');
        $this->assertInstanceOf(PendingCommand::class, $pending);

        return $pending;
    }
}
