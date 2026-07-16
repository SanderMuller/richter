<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Feature;

use App\Models\User;
use Illuminate\Support\Facades\Process;
use Illuminate\Testing\PendingCommand;
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
        // Builds the real graph of the testbench skeleton — a smoke test that the whole
        // build → analyze → format chain is wired into the command. Both formatter branches
        // (matched and unmatched) quote the symbol, so the assertion holds regardless of what
        // the skeleton's graph happens to contain.
        $this->runArtisan('richter:impact', ['symbol' => User::class])
            ->expectsOutputToContain('Building code graph…')
            ->expectsOutputToContain(User::class)
            ->assertSuccessful();
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
