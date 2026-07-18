<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Application;
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
            ->expectsOutputToContain('Resolving code graph…')
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
    public function detect_changes_accepts_the_explain_flag(): void
    {
        $this->runArtisan('richter:detect-changes', ['--base' => 'HEAD', '--explain' => true])
            ->expectsOutputToContain('No changed PHP files under app/ against HEAD.')
            ->assertSuccessful();
    }

    #[Test]
    public function detect_changes_json_carries_the_entry_point_paths_field(): void
    {
        $diff = "diff --git a/app/Models/User.php b/app/Models/User.php\n--- a/app/Models/User.php\n+++ b/app/Models/User.php\n@@ -0,0 +1,1 @@\n+    public function added(): void {}\n";

        Process::fake([
            '*merge-base*' => Process::result("abc123\n"),
            '*show*' => Process::result(errorOutput: 'bad object', exitCode: 128),
            '*diff*' => Process::result($diff),
        ]);

        $this->withoutMockingConsoleOutput();
        $exitCode = Artisan::call('richter:detect-changes', ['--base' => 'some-base', '--json' => true]);
        $decoded = json_decode(Artisan::output(), associative: true);

        $this->assertSame(0, $exitCode);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('entryPointPaths', $decoded);
    }

    #[Test]
    public function detect_changes_markdown_reports_a_real_diff_end_to_end(): void
    {
        $diff = "diff --git a/app/Models/User.php b/app/Models/User.php\n--- a/app/Models/User.php\n+++ b/app/Models/User.php\n@@ -0,0 +1,1 @@\n+    public function added(): void {}\n";

        Process::fake([
            '*merge-base*' => Process::result("abc123\n"),
            '*show*' => Process::result(errorOutput: 'bad object', exitCode: 128),
            '*diff*' => Process::result($diff),
        ]);

        $this->withoutMockingConsoleOutput();
        $exitCode = Artisan::call('richter:detect-changes', ['--base' => 'some-base', '--markdown' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('## Richter change impact', $output);
        $this->assertStringContainsString('**Risk:**', $output);
        $this->assertStringContainsString('| `app/Models/User.php` |', $output);
        $this->assertStringContainsString('UNRESOLVED', $output);
    }

    #[Test]
    public function detect_changes_markdown_renders_the_gate_verdict_as_markdown(): void
    {
        $diff = "diff --git a/app/Models/User.php b/app/Models/User.php\n--- a/app/Models/User.php\n+++ b/app/Models/User.php\n@@ -0,0 +1,1 @@\n+    public function added(): void {}\n";

        Process::fake([
            '*merge-base*' => Process::result("abc123\n"),
            '*show*' => Process::result(errorOutput: 'bad object', exitCode: 128),
            '*diff*' => Process::result($diff),
        ]);

        $this->withoutMockingConsoleOutput();
        $exitCode = Artisan::call('richter:detect-changes', ['--base' => 'some-base', '--markdown' => true, '--fail-on-unresolved' => true]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('**Gate:** FAIL', $output);
    }

    #[Test]
    public function detect_changes_rejects_json_combined_with_markdown_as_a_json_error(): void
    {
        // With --json present even the usage error must keep stdout a single parseable document.
        $this->withoutMockingConsoleOutput();
        $exitCode = Artisan::call('richter:detect-changes', ['--json' => true, '--markdown' => true]);
        $decoded = json_decode(Artisan::output(), associative: true);

        $this->assertSame(1, $exitCode);
        $this->assertIsArray($decoded);
        $this->assertIsString($decoded['error']);
        $this->assertStringContainsString('mutually exclusive', $decoded['error']);
    }

    #[Test]
    public function impact_markdown_emits_a_document_without_the_progress_line(): void
    {
        $this->withoutMockingConsoleOutput();
        $exitCode = Artisan::call('richter:impact', ['symbol' => 'Zzz\\Nonexistent\\Symbol', '--markdown' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('## Richter blast radius:', $output);
        $this->assertStringNotContainsString('Resolving code graph…', $output);
    }

    #[Test]
    public function impact_rejects_json_combined_with_markdown_as_a_json_error(): void
    {
        $this->withoutMockingConsoleOutput();
        $exitCode = Artisan::call('richter:impact', ['symbol' => 'X', '--json' => true, '--markdown' => true]);
        $decoded = json_decode(Artisan::output(), associative: true);

        $this->assertSame(1, $exitCode);
        $this->assertIsArray($decoded);
        $this->assertIsString($decoded['error']);
        $this->assertStringContainsString('mutually exclusive', $decoded['error']);
    }

    #[Test]
    public function impact_accepts_the_no_cache_flag(): void
    {
        $this->runArtisan('richter:impact', ['symbol' => 'Zzz\\Nonexistent\\Symbol', '--no-cache' => true])
            ->expectsOutputToContain('No graph nodes matched')
            ->assertSuccessful();
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

    #[Test]
    public function benchmark_passes_a_signal_case_end_to_end(): void
    {
        // The full pass chain — config → commit check → historical diff → member resolution →
        // graph walk → PASS — is exercised nowhere else: benchmark_cases ships empty and the other
        // benchmark tests cover only the warn/skip/fail branches.
        config()->set('richter.benchmark_cases', [self::benchmarkCase()]);

        $this->fakeBenchmarkReplayReachingRoutes();

        $this->runArtisan('richter:benchmark')
            ->expectsOutputToContain('PASS')
            ->expectsOutputToContain('Score: 1 passed, 0 failed of 1 fixtures.')
            ->assertSuccessful();
    }

    #[Test]
    public function benchmark_passes_a_control_case_within_its_risk_cap(): void
    {
        // The replayed change reaches route entry points (MEDIUM risk); a control capped at high
        // tolerates that, and its resolved seed also clears the fixture-drift guard.
        config()->set('richter.benchmark_cases', [self::benchmarkCase(expectSignal: false, maxRisk: 'high')]);

        $this->fakeBenchmarkReplayReachingRoutes();

        $this->runArtisan('richter:benchmark')
            ->expectsOutputToContain('PASS')
            ->expectsOutputToContain('Score: 1 passed, 0 failed of 1 fixtures.')
            ->assertSuccessful();
    }

    #[Test]
    public function benchmark_fails_a_control_case_that_exceeds_its_risk_cap(): void
    {
        // Same replay, cap lowered to low: the two reached route entry points rate MEDIUM, so the
        // over-reporting cap must trip — a control flipping green→red is detectable in CI.
        config()->set('richter.benchmark_cases', [self::benchmarkCase(expectSignal: false, maxRisk: 'low')]);

        $this->fakeBenchmarkReplayReachingRoutes();

        $this->runArtisan('richter:benchmark')
            ->expectsOutputToContain('FAIL — risk medium exceeds the expected maximum of low')
            ->expectsOutputToContain('Score: 0 passed, 1 failed of 1 fixtures.')
            ->assertFailed();
    }

    #[Test]
    public function impact_json_emits_parseable_json_with_empty_arrays_for_a_no_match_symbol(): void
    {
        // No progress line pollutes stdout in JSON mode, so the whole buffer must decode.
        $this->withoutMockingConsoleOutput();
        $exitCode = Artisan::call('richter:impact', ['symbol' => 'Zzz\\Nonexistent\\Symbol', '--json' => true]);
        $decoded = json_decode(Artisan::output(), associative: true);

        $this->assertSame(0, $exitCode);
        $this->assertIsArray($decoded);
        $this->assertSame('Zzz\\Nonexistent\\Symbol', $decoded['target']);
        $this->assertSame([], $decoded['callers']);
        $this->assertSame([], $decoded['dependencies']);
    }

    #[Test]
    public function detect_changes_json_emits_the_canonical_empty_object_on_an_empty_diff(): void
    {
        $this->withoutMockingConsoleOutput();
        $exitCode = Artisan::call('richter:detect-changes', ['--base' => 'HEAD', '--json' => true]);
        $decoded = json_decode(Artisan::output(), associative: true);

        $this->assertSame(0, $exitCode);
        $this->assertIsArray($decoded);
        $this->assertSame('HEAD', $decoded['base']);
        $this->assertSame('low', $decoded['risk']);
        $this->assertSame([], $decoded['changed']);
        $this->assertFalse($decoded['unresolved']);
        // The blast-radius summary only — never the raw walk internals.
        $this->assertArrayNotHasKey('callers', $decoded);
    }

    #[Test]
    public function detect_changes_json_reports_an_option_injection_ref_as_json_not_a_stack_trace(): void
    {
        // baseRef() throws before the command's happy path; JSON mode must still emit one parseable
        // document (advisory exit 0 with no gate flag), never a leaked framework exception.
        $this->withoutMockingConsoleOutput();
        $exitCode = Artisan::call('richter:detect-changes', ['--base' => '--upload-pack=evil', '--json' => true]);
        $output = Artisan::output();
        $decoded = json_decode($output, associative: true);

        $this->assertSame(0, $exitCode);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('error', $decoded);
        $this->assertStringNotContainsStringIgnoringCase('InvalidArgumentException', $output);
    }

    #[Test]
    public function detect_changes_fails_on_an_invalid_fail_on_value(): void
    {
        $this->runArtisan('richter:detect-changes', ['--fail-on' => 'bogus'])
            ->expectsOutputToContain('Invalid --fail-on value')
            ->assertFailed();
    }

    #[Test]
    public function detect_changes_json_reports_an_invalid_fail_on_value_as_error(): void
    {
        $this->withoutMockingConsoleOutput();
        $exitCode = Artisan::call('richter:detect-changes', ['--fail-on' => 'bogus', '--json' => true]);
        $decoded = json_decode(Artisan::output(), associative: true);

        $this->assertSame(1, $exitCode);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('error', $decoded);
    }

    #[Test]
    public function detect_changes_fails_on_an_explicitly_empty_fail_on_value(): void
    {
        // A gating flag fails closed: `--fail-on=` (e.g. an unset CI variable) is a usage error, not
        // a silently disabled gate.
        $this->runArtisan('richter:detect-changes', ['--fail-on' => ''])
            ->expectsOutputToContain('Invalid --fail-on value')
            ->assertFailed();
    }

    #[Test]
    public function detect_changes_fails_on_a_valueless_fail_on_flag(): void
    {
        // Bare `--fail-on` (present but no value) must fail closed, not silently run ungated.
        $this->runArtisan('richter:detect-changes', ['--fail-on' => null])
            ->expectsOutputToContain('requires a value')
            ->assertFailed();
    }

    #[Test]
    public function detect_changes_fails_on_a_broken_base_ref_under_a_gate(): void
    {
        // Advisory would exit 0 (see detect_changes_warns_on_a_broken_base_ref); a gate flips it: a
        // diff that can't be assessed must not read as "pass".
        $this->runArtisan('richter:detect-changes', ['--base' => 'this-ref-does-not-exist-zzz', '--fail-on' => 'low'])
            ->expectsOutputToContain("git diff against 'this-ref-does-not-exist-zzz' failed")
            ->assertFailed();
    }

    #[Test]
    public function detect_changes_passes_the_gate_on_an_empty_diff(): void
    {
        // An empty diff always passes: --fail-on=low must not trip on zero changes (low >= low).
        $this->runArtisan('richter:detect-changes', ['--base' => 'HEAD', '--fail-on' => 'low'])
            ->assertSuccessful();
    }

    #[Test]
    public function detect_changes_json_empty_diff_gate_reports_not_tripped(): void
    {
        $this->withoutMockingConsoleOutput();
        $exitCode = Artisan::call('richter:detect-changes', ['--base' => 'HEAD', '--json' => true, '--fail-on' => 'high']);
        $decoded = json_decode(Artisan::output(), associative: true);

        $this->assertSame(0, $exitCode);
        $this->assertIsArray($decoded);
        $gate = $decoded['gate'];
        $this->assertIsArray($gate);
        $this->assertFalse($gate['tripped']);
    }

    #[Test]
    public function detect_changes_fails_the_unresolved_gate_end_to_end(): void
    {
        // Faked plumbing where `git show` fails → the changed file reads UNRESOLVED. With
        // --fail-on-unresolved that trips the gate and fails the build.
        $diff = "diff --git a/app/Models/User.php b/app/Models/User.php\n--- a/app/Models/User.php\n+++ b/app/Models/User.php\n@@ -0,0 +1,1 @@\n+    public function added(): void {}\n";

        Process::fake([
            '*merge-base*' => Process::result("abc123\n"),
            '*show*' => Process::result(errorOutput: 'bad object', exitCode: 128),
            '*diff*' => Process::result($diff),
        ]);

        $this->withoutMockingConsoleOutput();
        $exitCode = Artisan::call('richter:detect-changes', ['--base' => 'some-base', '--fail-on-unresolved' => true]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Gate: FAIL', $output);
        $this->assertStringContainsString('UNRESOLVED', $output);
    }

    #[Test]
    public function detect_changes_json_gate_object_reports_a_trip(): void
    {
        $diff = "diff --git a/app/Models/User.php b/app/Models/User.php\n--- a/app/Models/User.php\n+++ b/app/Models/User.php\n@@ -0,0 +1,1 @@\n+    public function added(): void {}\n";

        Process::fake([
            '*merge-base*' => Process::result("abc123\n"),
            '*show*' => Process::result(errorOutput: 'bad object', exitCode: 128),
            '*diff*' => Process::result($diff),
        ]);

        $this->withoutMockingConsoleOutput();
        $exitCode = Artisan::call('richter:detect-changes', ['--base' => 'some-base', '--json' => true, '--fail-on-unresolved' => true]);
        $decoded = json_decode(Artisan::output(), associative: true);

        $this->assertSame(1, $exitCode);
        $this->assertIsArray($decoded);
        $gate = $decoded['gate'];
        $this->assertIsArray($gate);
        $this->assertTrue($gate['tripped']);
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
    private function benchmarkCase(bool $expectSignal = true, string $maxRisk = 'high'): array
    {
        return [
            'key' => 'CASE-1',
            'fix_commit' => 'abc1234',
            'bug_class' => 'background-job change',
            'expect_signal' => $expectSignal,
            'max_risk' => $maxRisk,
        ];
    }

    /**
     * Points the graph at the fixture project and fakes the four git calls a benchmark replay makes,
     * so the configured case replays a modification inside QuestionController::show() — a member the
     * fixture graph resolves and walks up to its two routes (see CodeGraphBuilderTest). The testbench
     * skeleton's app/ is empty, so its graph could never resolve a seed, let alone reach an entry
     * point. Both `git show` sides return the real fixture source; the diff's line number is derived
     * from that exact source so the change lands inside the method's span, not at class level.
     */
    private function fakeBenchmarkReplayReachingRoutes(): void
    {
        $app = $this->app;
        $this->assertInstanceOf(Application::class, $app);
        $app->setBasePath(self::fixtureProjectPath());

        $file = 'app/Http/Controllers/Video/QuestionController.php';
        $source = (string) file_get_contents(self::fixtureProjectPath() . '/' . $file);
        $changedLine = array_search('        return QuestionResource::make($video);', explode("\n", $source), true);
        $this->assertIsInt($changedLine);
        ++$changedLine; // explode() indexes from 0, diff hunk headers from 1.

        $diff = "diff --git a/{$file} b/{$file}\n"
            . "--- a/{$file}\n"
            . "+++ b/{$file}\n"
            . "@@ -{$changedLine},1 +{$changedLine},1 @@\n"
            . "-        return QuestionResource::make(\$video->withoutRelations());\n"
            . "+        return QuestionResource::make(\$video);\n";

        Process::fake([
            '*cat-file*' => Process::result(),
            '*merge-base*' => Process::result("base123\n"),
            '*diff*' => Process::result($diff),
            '*show*' => Process::result($source),
        ]);
    }
}
