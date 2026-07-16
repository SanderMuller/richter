<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Illuminate\Testing\PendingCommand;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Tests\TestCase;

final class CommandsTest extends TestCase
{
    #[Test]
    public function benchmark_warns_when_no_cases_are_configured(): void
    {
        $this->runArtisan('richter:benchmark')
            ->expectsOutputToContain('No benchmark cases configured')
            ->assertSuccessful();
    }

    #[Test]
    public function benchmark_warns_when_no_fixture_matches_the_case_filter(): void
    {
        config()->set('richter.benchmark_cases', [$this->benchmarkCase()]);

        $this->runArtisan('richter:benchmark', ['--case' => 'nope'])
            ->expectsOutputToContain('No benchmark fixture matches')
            ->assertFailed();
    }

    #[Test]
    public function benchmark_skips_a_configured_case_whose_commit_is_unavailable(): void
    {
        config()->set('richter.benchmark_cases', [$this->benchmarkCase()]);

        Process::fake([
            '*cat-file*' => Process::result(errorOutput: 'missing', exitCode: 1),
        ]);

        $this->runArtisan('richter:benchmark')
            ->expectsOutputToContain('SKIP')
            ->expectsOutputToContain('0 passed, 0 failed, 1 skipped (not evaluated) of 1 fixtures.')
            ->assertSuccessful();
    }

    #[Test]
    public function detect_changes_reports_an_empty_diff(): void
    {
        // HEAD...HEAD is a valid, always-empty diff — the command reports it without building the graph.
        $this->runArtisan('richter:detect-changes', ['--base' => 'HEAD'])
            ->expectsOutputToContain('No changed PHP files under app/ against HEAD.')
            ->assertSuccessful();
    }

    #[Test]
    public function detect_changes_falls_back_to_the_configured_base_ref(): void
    {
        config()->set('richter.default_base', 'HEAD');

        $this->runArtisan('richter:detect-changes')
            ->expectsOutputToContain('No changed PHP files under app/ against HEAD.')
            ->assertSuccessful();
    }

    #[Test]
    public function impact_reports_the_blast_radius_of_a_symbol(): void
    {
        // Builds the real graph of the testbench skeleton. Both formatter branches (matched and
        // unmatched) quote the symbol, so the assertion holds regardless of what that graph contains.
        $this->runArtisan('richter:impact', ['symbol' => User::class])
            ->expectsOutputToContain('Building code graph…')
            ->expectsOutputToContain(User::class)
            ->assertSuccessful();
    }

    #[Test]
    public function detect_changes_warns_on_a_broken_base_ref(): void
    {
        $this->runArtisan('richter:detect-changes', ['--base' => 'this-ref-does-not-exist-zzz'])
            ->expectsOutputToContain("git diff against 'this-ref-does-not-exist-zzz' failed")
            ->assertSuccessful();
    }

    #[Test]
    public function detect_changes_rejects_an_option_injection_shaped_base_ref(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->runArtisan('richter:detect-changes', ['--base' => '--upload-pack=evil'])->run();
    }

    #[Test]
    public function detect_changes_reports_a_real_diff_end_to_end(): void
    {
        // Faked git plumbing; the graph is built for real, so this covers the full
        // resolve → analyze → format chain. The changed file does not exist in the skeleton
        // working tree, which also exercises the unreadable-head-source honesty path: the file
        // must read UNRESOLVED, never as a falsely-empty "no impact".
        $diff = "diff --git a/app/Models/User.php b/app/Models/User.php\n--- a/app/Models/User.php\n+++ b/app/Models/User.php\n@@ -0,0 +1,1 @@\n+    public function added(): void {}\n";

        Process::fake([
            '*merge-base*' => Process::result("abc123\n"),
            '*show*' => Process::result(errorOutput: 'bad object', exitCode: 128),
            '*diff*' => Process::result($diff),
        ]);

        // The report is one multi-line write; PendingCommand consumes only a single
        // expectsOutputToContain() per write, so assert on the raw output instead.
        $this->withoutMockingConsoleOutput();
        $exitCode = Artisan::call('richter:detect-changes', ['--base' => 'some-base']);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Changed files:', $output);
        $this->assertStringContainsString('app/Models/User.php', $output);
        $this->assertStringContainsString('UNRESOLVED', $output);
        $this->assertStringContainsString('Risk:', $output);
    }

    #[Test]
    public function benchmark_fails_a_case_whose_diff_cannot_be_resolved(): void
    {
        config()->set('richter.benchmark_cases', [self::benchmarkCase()]);

        Process::fake([
            '*cat-file*' => Process::result(),
            '*diff*' => Process::result(errorOutput: 'boom', exitCode: 1),
        ]);

        $this->runArtisan('richter:benchmark')
            ->expectsOutputToContain('FAIL — git diff against')
            ->expectsOutputToContain('Score: 0 passed, 1 failed of 1 fixtures.')
            ->assertFailed();
    }

    /**
     * Narrows testbench's `artisan()` union return — a string command always yields a PendingCommand.
     *
     * @param  array<string, mixed>  $parameters
     */
    private function runArtisan(string $command, array $parameters = []): PendingCommand
    {
        $pending = $this->artisan($command, $parameters);

        $this->assertInstanceOf(PendingCommand::class, $pending);

        return $pending;
    }

    /** @return array{key: string, fix_commit: string, bug_class: string, expect_signal: bool, max_risk: string} */
    private function benchmarkCase(): array
    {
        return [
            'key' => 'CASE-1',
            'fix_commit' => 'abc1234',
            'bug_class' => 'background-job change',
            'expect_signal' => true,
            'max_risk' => 'high',
        ];
    }
}
