<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Application;
use Illuminate\Process\PendingProcess;
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
    public function detect_changes_profile_prints_the_build_phase_split(): void
    {
        $diff = "diff --git a/app/Models/User.php b/app/Models/User.php\n--- a/app/Models/User.php\n+++ b/app/Models/User.php\n@@ -0,0 +1,1 @@\n+    public function added(): void {}\n";

        Process::fake([
            '*merge-base*' => Process::result("abc123\n"),
            '*show*' => Process::result(errorOutput: 'bad object', exitCode: 128),
            '*diff*' => Process::result($diff),
        ]);

        $this->withoutMockingConsoleOutput();
        $exitCode = Artisan::call('richter:detect-changes', ['--base' => 'some-base', '--profile' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Build profile', $output);
        $this->assertStringContainsString('brain-analyze', $output);
        $this->assertStringContainsString('total', $output);
        // The normal text report must still be there — --profile adds to the output, it never replaces it.
        $this->assertStringContainsString('Changed files:', $output);
    }

    #[Test]
    public function detect_changes_profile_json_still_emits_a_single_document(): void
    {
        $diff = "diff --git a/app/Models/User.php b/app/Models/User.php\n--- a/app/Models/User.php\n+++ b/app/Models/User.php\n@@ -0,0 +1,1 @@\n+    public function added(): void {}\n";

        Process::fake([
            '*merge-base*' => Process::result("abc123\n"),
            '*show*' => Process::result(errorOutput: 'bad object', exitCode: 128),
            '*diff*' => Process::result($diff),
        ]);

        $this->withoutMockingConsoleOutput();
        $exitCode = Artisan::call('richter:detect-changes', ['--base' => 'some-base', '--json' => true, '--profile' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Build profile', $output);

        // --profile writes to stderr, --json to stdout; the test harness captures both into one
        // buffer (profile lines first), so decode the report from the first '{' onward.
        $decoded = json_decode(substr($output, (int) strpos($output, '{')), associative: true);

        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('entryPointPaths', $decoded);
    }

    #[Test]
    public function detect_changes_json_warns_about_untracked_files_on_stderr_only(): void
    {
        // `git diff` never shows an untracked file — HEAD mode or not — so an untracked file under
        // app/ gets an honest stderr note. --json must still be a single parseable document on stdout.
        $diff = "diff --git a/app/Models/User.php b/app/Models/User.php\n--- a/app/Models/User.php\n+++ b/app/Models/User.php\n@@ -0,0 +1,1 @@\n+    public function added(): void {}\n";

        Process::fake([
            '*merge-base*' => Process::result("abc123\n"),
            // base_path() is modelled as the git repo root here, so `git rev-parse --show-prefix`
            // returns an empty prefix and the `app/`-relative status paths below need no re-rooting.
            '*rev-parse*' => Process::result(),
            '*show*' => Process::result(errorOutput: 'bad object', exitCode: 128),
            '*status*' => Process::result("?? app/Models/Report.php\n"),
            '*diff*' => Process::result($diff),
        ]);

        $this->withoutMockingConsoleOutput();
        $exitCode = Artisan::call('richter:detect-changes', ['--base' => 'some-base', '--json' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('untracked file(s)', $output);
        $this->assertStringContainsString('app/Models/Report.php', $output);

        // The warning writes to stderr, --json to stdout; the harness captures both into one buffer
        // (warning first), so decode the report from the first '{' onward, same as --profile above.
        $decoded = json_decode(substr($output, (int) strpos($output, '{')), associative: true);

        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('entryPointPaths', $decoded);
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

    /**
     * The faked diff every --html case below runs against: one changed, ungraphed model file.
     *
     * The trailing '*' catch-all matters: a command matching no pattern is NOT faked, it is really
     * executed — which for --open would launch a browser during the suite.
     */
    private function fakeDiff(): void
    {
        $diff = "diff --git a/app/Models/User.php b/app/Models/User.php\n--- a/app/Models/User.php\n+++ b/app/Models/User.php\n@@ -0,0 +1,1 @@\n+    public function added(): void {}\n";

        Process::fake([
            '*merge-base*' => Process::result("abc123\n"),
            '*show*' => Process::result(errorOutput: 'bad object', exitCode: 128),
            '*diff*' => Process::result($diff),
            '*' => Process::result(),
        ]);
    }

    private function reportPath(): string
    {
        return sys_get_temp_dir() . '/richter-report-' . getmypid() . '.html';
    }

    #[Test]
    public function detect_changes_rejects_html_combined_with_json_as_a_json_error(): void
    {
        $this->withoutMockingConsoleOutput();
        $exitCode = Artisan::call('richter:detect-changes', ['--html' => 'x.html', '--json' => true]);
        $decoded = json_decode(Artisan::output(), associative: true);

        $this->assertSame(1, $exitCode);
        $this->assertIsArray($decoded);
        $this->assertIsString($decoded['error']);
        $this->assertStringContainsString('cannot be combined', $decoded['error']);
    }

    #[Test]
    public function detect_changes_rejects_html_combined_with_markdown(): void
    {
        $this->withoutMockingConsoleOutput();
        $exitCode = Artisan::call('richter:detect-changes', ['--html' => 'x.html', '--markdown' => true]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('cannot be combined', Artisan::output());
    }

    #[Test]
    public function detect_changes_open_without_html_is_a_usage_error(): void
    {
        $this->withoutMockingConsoleOutput();
        $exitCode = Artisan::call('richter:detect-changes', ['--open' => true]);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('requires --html', Artisan::output());
    }

    #[Test]
    public function detect_changes_html_writes_a_self_contained_file(): void
    {
        $this->fakeDiff();
        $path = $this->reportPath();

        $this->withoutMockingConsoleOutput();
        $exitCode = Artisan::call('richter:detect-changes', ['--base' => 'some-base', '--html' => $path]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertFileExists($path);

        $html = (string) file_get_contents($path);
        unlink($path);

        $this->assertStringContainsString('<!DOCTYPE html>', $html);
        $this->assertStringNotContainsString('https://', $html);

        foreach (['Overview', 'Graph', 'Paths', 'Changes', 'Advisory'] as $tab) {
            $this->assertStringContainsString(">{$tab}</button>", $html);
        }

        // stdout carries the confirmation, not a second copy of the report.
        $this->assertStringContainsString('Report written to', $output);
        $this->assertStringNotContainsString('Richter change impact', $output);
    }

    #[Test]
    public function detect_changes_html_writes_a_report_even_for_an_empty_diff(): void
    {
        // Asking for a file and getting none reads as a failure, and CI would link a missing artifact.
        $path = $this->reportPath();

        $this->withoutMockingConsoleOutput();
        $exitCode = Artisan::call('richter:detect-changes', ['--base' => 'HEAD', '--html' => $path]);

        $this->assertSame(0, $exitCode);
        $this->assertFileExists($path);

        $html = (string) file_get_contents($path);
        unlink($path);

        $this->assertStringContainsString('>LOW</strong>', $html);
        $this->assertStringContainsString('Nothing reached — no graph to draw.', $html);
    }

    #[Test]
    public function detect_changes_html_on_an_empty_diff_still_reflects_a_gated_run(): void
    {
        // An empty diff always passes, but a report from a gated run must not claim it was advisory.
        $path = $this->reportPath();

        $this->withoutMockingConsoleOutput();
        $exitCode = Artisan::call('richter:detect-changes', ['--base' => 'HEAD', '--html' => $path, '--fail-on' => 'low']);

        $this->assertSame(0, $exitCode);

        $html = (string) file_get_contents($path);
        unlink($path);

        $this->assertStringNotContainsString('advisory — not a gate', $html);
        $this->assertStringContainsString('not tripped', $html);
    }

    #[Test]
    public function detect_changes_html_fails_loudly_when_the_report_cannot_be_written(): void
    {
        // Announcing a report that was never written is the artifact-shaped version of a false
        // "no impact" — CI would publish a link to a file that does not exist.
        $this->fakeDiff();

        $this->withoutMockingConsoleOutput();
        $exitCode = Artisan::call('richter:detect-changes', [
            '--base' => 'some-base',
            '--html' => sys_get_temp_dir() . '/richter-no-such-dir-' . getmypid() . '/report.html',
        ]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Could not write the report', $output);
        $this->assertStringNotContainsString('Report written to', $output);
    }

    #[Test]
    public function detect_changes_rejects_an_empty_html_path(): void
    {
        // `--html=` parses to "" rather than null, which would otherwise reach file_put_contents('').
        $this->withoutMockingConsoleOutput();
        $exitCode = Artisan::call('richter:detect-changes', ['--html' => '']);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('requires a path', Artisan::output());
    }

    #[Test]
    public function detect_changes_html_renders_editor_links_when_configured(): void
    {
        // The editor config drives clickable file references end to end.
        config()->set('richter.editor', 'phpstorm');
        $this->fakeDiff();
        $path = $this->reportPath();

        $this->withoutMockingConsoleOutput();
        Artisan::call('richter:detect-changes', ['--base' => 'some-base', '--html' => $path]);

        $html = (string) file_get_contents($path);
        unlink($path);

        $this->assertStringContainsString('<a class="ref" href="phpstorm://open?file=', $html);
    }

    #[Test]
    public function detect_changes_html_still_evaluates_the_gate(): void
    {
        // --html is a rendering choice; it must never turn a failing gate green.
        $this->fakeDiff();
        $path = $this->reportPath();

        $this->withoutMockingConsoleOutput();
        $exitCode = Artisan::call('richter:detect-changes', ['--base' => 'some-base', '--html' => $path, '--fail-on-unresolved' => true]);
        $output = Artisan::output();

        @unlink($path);

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('Gate: FAIL', $output);
    }

    #[Test]
    public function detect_changes_open_invokes_the_platform_opener(): void
    {
        $this->fakeDiff();
        $path = $this->reportPath();

        $this->withoutMockingConsoleOutput();
        Artisan::call('richter:detect-changes', ['--base' => 'some-base', '--html' => $path, '--open' => true]);

        @unlink($path);

        Process::assertRan(fn (PendingProcess $process): bool => is_array($process->command)
            && in_array($path, $process->command, strict: true));
    }

    #[Test]
    public function detect_changes_warns_but_still_succeeds_when_the_opener_fails(): void
    {
        $diff = "diff --git a/app/Models/User.php b/app/Models/User.php\n--- a/app/Models/User.php\n+++ b/app/Models/User.php\n@@ -0,0 +1,1 @@\n+    public function added(): void {}\n";
        $path = $this->reportPath();

        Process::fake([
            '*merge-base*' => Process::result("abc123\n"),
            '*show*' => Process::result(errorOutput: 'bad object', exitCode: 128),
            '*diff*' => Process::result($diff),
            // The report is already on disk, so a dead opener is a warning, never a failed run.
            '*' => Process::result(errorOutput: 'no opener', exitCode: 1),
        ]);

        $this->withoutMockingConsoleOutput();
        $exitCode = Artisan::call('richter:detect-changes', ['--base' => 'some-base', '--html' => $path, '--open' => true]);
        $output = Artisan::output();

        @unlink($path);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('Could not open', $output);
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
    public function affected_tests_reports_an_empty_diff_as_a_determinable_empty_selection(): void
    {
        $this->withoutMockingConsoleOutput();
        $exitCode = Artisan::call('richter:affected-tests', ['--base' => 'HEAD', '--plain' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertSame('', trim(Artisan::output()));
    }

    #[Test]
    public function affected_tests_text_mode_reports_a_determinable_empty_selection(): void
    {
        $this->runArtisan('richter:affected-tests', ['--base' => 'HEAD'])
            ->expectsOutputToContain('Affected tests: 0')
            ->assertSuccessful();
    }

    #[Test]
    public function affected_tests_accepts_the_no_cache_flag(): void
    {
        $this->withoutMockingConsoleOutput();
        $exitCode = Artisan::call('richter:affected-tests', ['--base' => 'HEAD', '--plain' => true, '--no-cache' => true]);

        $this->assertSame(0, $exitCode);
    }

    #[Test]
    public function affected_tests_json_empty_diff_is_determinable(): void
    {
        $this->withoutMockingConsoleOutput();
        $exitCode = Artisan::call('richter:affected-tests', ['--base' => 'HEAD', '--json' => true]);
        $decoded = json_decode(Artisan::output(), associative: true);

        $this->assertSame(0, $exitCode);
        $this->assertIsArray($decoded);
        $this->assertSame('HEAD', $decoded['base']);
        $this->assertTrue($decoded['determinable']);
        $this->assertSame([], $decoded['tests']);
    }

    #[Test]
    public function affected_tests_plain_exits_undetermined_with_an_untracked_file_present(): void
    {
        // An untracked file is invisible to every diff form, so the selection can never vouch for
        // completeness — this is the blocker finding: exit 2 (undetermined), not a silently narrowed
        // determinable selection. --plain stdout must still carry nothing (no test paths at all).
        Process::fake([
            '*merge-base*' => Process::result("abc123\n"),
            // base_path() modelled as the repo root: empty prefix, so the `app/`-relative status path
            // below is matched as-is (no monorepo re-rooting). See GitProjectPaths.
            '*rev-parse*' => Process::result(),
            '*diff*' => Process::result(''),
            '*status*' => Process::result("?? app/Models/Report.php\n"),
        ]);

        $this->withoutMockingConsoleOutput();
        $exitCode = Artisan::call('richter:affected-tests', ['--base' => 'some-base', '--plain' => true]);
        $output = Artisan::output();

        $this->assertSame(2, $exitCode);
        $this->assertStringContainsString('untracked file(s)', $output);
        $this->assertStringContainsString('app/Models/Report.php', $output);
        // Nothing but the stderr warning landed in the combined buffer — an undetermined selection
        // prints no test path, so --plain's own stdout contract stays exactly empty.
        $this->assertSame(
            'Note: 1 untracked file(s) under app/, resources/views/, or a configured frontend root are invisible to `git diff` and were not analysed: app/Models/Report.php',
            trim($output),
        );
    }

    #[Test]
    public function affected_tests_plain_exits_undetermined_when_a_tracked_change_has_an_untracked_sibling(): void
    {
        // The exact regression this fixes: a tracked change under app/ ALONGSIDE a brand-new,
        // un-`git add`-ed file must not silently narrow the selection to just the tracked change —
        // the untracked file's own surface is invisible to `git diff`, so the whole selection is
        // undetermined, not "determinable but partial".
        $diff = "diff --git a/app/Models/User.php b/app/Models/User.php\n--- a/app/Models/User.php\n+++ b/app/Models/User.php\n@@ -0,0 +1,1 @@\n+    public function added(): void {}\n";

        Process::fake([
            '*merge-base*' => Process::result("abc123\n"),
            // base_path() modelled as the repo root: empty prefix, so the `app/`-relative status path
            // below is matched as-is (no monorepo re-rooting). See GitProjectPaths.
            '*rev-parse*' => Process::result(),
            '*show*' => Process::result(errorOutput: 'bad object', exitCode: 128),
            '*diff*' => Process::result($diff),
            '*status*' => Process::result("?? app/Jobs/Foo.php\n"),
        ]);

        $this->withoutMockingConsoleOutput();
        $exitCode = Artisan::call('richter:affected-tests', ['--base' => 'some-base', '--plain' => true]);
        $output = Artisan::output();

        $this->assertSame(2, $exitCode);
        $this->assertSame(
            'Note: 1 untracked file(s) under app/, resources/views/, or a configured frontend root are invisible to `git diff` and were not analysed: app/Jobs/Foo.php',
            trim($output),
        );
    }

    #[Test]
    public function affected_tests_json_reports_determinable_false_when_an_untracked_relevant_file_is_present(): void
    {
        $diff = "diff --git a/app/Models/User.php b/app/Models/User.php\n--- a/app/Models/User.php\n+++ b/app/Models/User.php\n@@ -0,0 +1,1 @@\n+    public function added(): void {}\n";

        Process::fake([
            '*merge-base*' => Process::result("abc123\n"),
            // base_path() modelled as the repo root: empty prefix, so the `app/`-relative status path
            // below is matched as-is (no monorepo re-rooting). See GitProjectPaths.
            '*rev-parse*' => Process::result(),
            '*show*' => Process::result(errorOutput: 'bad object', exitCode: 128),
            '*diff*' => Process::result($diff),
            '*status*' => Process::result("?? app/Jobs/Foo.php\n"),
        ]);

        $this->withoutMockingConsoleOutput();
        $exitCode = Artisan::call('richter:affected-tests', ['--base' => 'some-base', '--json' => true]);
        $output = Artisan::output();

        // The stderr warning writes first, --json to stdout; decode the report from the first '{'
        // onward, same pattern as the detect-changes untracked-file JSON test above.
        $decoded = json_decode(substr($output, (int) strpos($output, '{')), associative: true);

        $this->assertSame(2, $exitCode);
        $this->assertIsArray($decoded);
        $this->assertFalse($decoded['determinable']);
        $this->assertSame([], $decoded['tests']);
        $this->assertIsArray($decoded['reasons']);
        $reason = $decoded['reasons'][0];
        $this->assertIsString($reason);
        $this->assertStringContainsString('app/Jobs/Foo.php', $reason);
        $this->assertStringContainsString('git add', $reason);
    }

    #[Test]
    public function affected_tests_with_no_untracked_files_still_returns_its_normal_determinable_selection(): void
    {
        // Regression guard: a diff with no untracked relevant files must be unaffected by the new
        // undetermined path — the pre-existing determinable-empty-diff contract still holds.
        Process::fake([
            '*merge-base*' => Process::result("abc123\n"),
            // base_path() modelled as the repo root: empty prefix. README.notes is filtered by the
            // root allowlist (not under app/), not by re-rooting. See GitProjectPaths.
            '*rev-parse*' => Process::result(),
            '*diff*' => Process::result(''),
            '*status*' => Process::result("?? README.notes\n"),
        ]);

        $this->withoutMockingConsoleOutput();
        $exitCode = Artisan::call('richter:affected-tests', ['--base' => 'some-base', '--json' => true]);
        $decoded = json_decode(Artisan::output(), associative: true);

        $this->assertSame(0, $exitCode);
        $this->assertIsArray($decoded);
        $this->assertTrue($decoded['determinable']);
        $this->assertSame([], $decoded['tests']);
    }

    #[Test]
    public function detect_changes_json_still_warns_and_continues_with_an_untracked_file_present(): void
    {
        // Regression guard: detect-changes is advisory and must keep its warn-and-continue
        // behaviour unchanged — only affected-tests forces undetermined on an untracked file.
        $diff = "diff --git a/app/Models/User.php b/app/Models/User.php\n--- a/app/Models/User.php\n+++ b/app/Models/User.php\n@@ -0,0 +1,1 @@\n+    public function added(): void {}\n";

        Process::fake([
            '*merge-base*' => Process::result("abc123\n"),
            // base_path() modelled as the repo root: empty prefix, so the `app/`-relative status path
            // below is matched as-is (no monorepo re-rooting). See GitProjectPaths.
            '*rev-parse*' => Process::result(),
            '*show*' => Process::result(errorOutput: 'bad object', exitCode: 128),
            '*diff*' => Process::result($diff),
            '*status*' => Process::result("?? app/Models/Report.php\n"),
        ]);

        $this->withoutMockingConsoleOutput();
        $exitCode = Artisan::call('richter:detect-changes', ['--base' => 'some-base']);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('untracked file(s)', $output);
        $this->assertStringContainsString('app/Models/Report.php', $output);
        $this->assertStringContainsString('Changed files:', $output);
        $this->assertStringContainsString('Risk:', $output);
    }

    #[Test]
    public function affected_tests_plain_prints_nothing_and_exits_2_when_undeterminable(): void
    {
        // Faked plumbing where `git show` fails → the changed file reads UNRESOLVED → the selection
        // cannot be determined. Plain mode must keep stdout empty so command substitution degrades
        // to the full suite, and the exit code must say why nothing was printed.
        $diff = "diff --git a/app/Models/User.php b/app/Models/User.php\n--- a/app/Models/User.php\n+++ b/app/Models/User.php\n@@ -0,0 +1,1 @@\n+    public function added(): void {}\n";

        Process::fake([
            '*merge-base*' => Process::result("abc123\n"),
            '*show*' => Process::result(errorOutput: 'bad object', exitCode: 128),
            '*diff*' => Process::result($diff),
        ]);

        $this->withoutMockingConsoleOutput();
        $exitCode = Artisan::call('richter:affected-tests', ['--base' => 'some-base', '--plain' => true]);

        $this->assertSame(2, $exitCode);
        $this->assertSame('', trim(Artisan::output()));
    }

    #[Test]
    public function affected_tests_json_reports_the_undeterminable_reasons(): void
    {
        $diff = "diff --git a/app/Models/User.php b/app/Models/User.php\n--- a/app/Models/User.php\n+++ b/app/Models/User.php\n@@ -0,0 +1,1 @@\n+    public function added(): void {}\n";

        Process::fake([
            '*merge-base*' => Process::result("abc123\n"),
            '*show*' => Process::result(errorOutput: 'bad object', exitCode: 128),
            '*diff*' => Process::result($diff),
        ]);

        $this->withoutMockingConsoleOutput();
        $exitCode = Artisan::call('richter:affected-tests', ['--base' => 'some-base', '--json' => true]);
        $decoded = json_decode(Artisan::output(), associative: true);

        $this->assertSame(2, $exitCode);
        $this->assertIsArray($decoded);
        $this->assertFalse($decoded['determinable']);
        $this->assertIsArray($decoded['reasons']);
        $this->assertStringContainsString('UNRESOLVED', json_encode($decoded['reasons'], JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function affected_tests_text_mode_names_the_reasons_and_exits_2(): void
    {
        $this->withoutMockingConsoleOutput();
        $exitCode = Artisan::call('richter:affected-tests', ['--base' => 'this-ref-does-not-exist-zzz']);
        $output = Artisan::output();

        $this->assertSame(2, $exitCode);
        $this->assertStringContainsString('run the full suite', $output);
        $this->assertStringContainsString('this-ref-does-not-exist-zzz', $output);
    }

    #[Test]
    public function affected_tests_rejects_json_combined_with_plain_as_a_json_error(): void
    {
        $this->withoutMockingConsoleOutput();
        $exitCode = Artisan::call('richter:affected-tests', ['--json' => true, '--plain' => true]);
        $decoded = json_decode(Artisan::output(), associative: true);

        $this->assertSame(1, $exitCode);
        $this->assertIsArray($decoded);
        $this->assertIsString($decoded['error']);
        $this->assertStringContainsString('mutually exclusive', $decoded['error']);
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
    public function benchmark_add_rejects_an_option_shaped_commit(): void
    {
        Process::fake();

        $this->runArtisan('richter:benchmark:add', ['fix-commit' => '--upload-pack=x'])
            ->expectsOutputToContain('may not start with')
            ->assertFailed();
    }

    #[Test]
    public function benchmark_add_reports_an_unavailable_commit(): void
    {
        Process::fake(['*cat-file*' => Process::result(exitCode: 1)]);

        $this->runArtisan('richter:benchmark:add', ['fix-commit' => 'abc1234'])
            ->expectsOutputToContain('is not available')
            ->assertFailed();
    }

    #[Test]
    public function benchmark_add_scaffolds_a_signal_case_from_a_replayed_fix(): void
    {
        $this->fakeBenchmarkReplayReachingRoutes(['*log*' => Process::result("PROJ-42 Fix duplicated post reviews\n")]);

        $this->runArtisan('richter:benchmark:add', ['fix-commit' => 'abc1234'])
            ->expectsOutputToContain("'key' => 'PROJ-42'")
            ->expectsOutputToContain("'fix_commit' => 'abc1234'")
            ->expectsOutputToContain("'bug_class' => 'PROJ-42 Fix duplicated post reviews'")
            ->expectsOutputToContain("'expect_signal' => true")
            ->expectsOutputToContain("'max_risk' => 'high'")
            ->expectsOutputToContain('Would currently PASS')
            ->assertSuccessful();
    }

    #[Test]
    public function benchmark_add_scaffolds_a_control_case_capped_at_the_replayed_risk(): void
    {
        $this->fakeBenchmarkReplayReachingRoutes(['*log*' => Process::result("PROJ-42 Fix duplicated post reviews\n")]);

        $this->runArtisan('richter:benchmark:add', ['fix-commit' => 'abc1234', '--control' => true])
            ->expectsOutputToContain("'expect_signal' => false")
            ->expectsOutputToContain("'max_risk' => 'medium'")
            ->assertSuccessful();
    }

    #[Test]
    public function benchmark_add_falls_back_to_the_short_sha_key_when_the_subject_has_no_ticket(): void
    {
        $this->fakeBenchmarkReplayReachingRoutes([
            '*log*' => Process::result("Fix duplicated post reviews\n"),
            '*rev-parse*' => Process::result("abc1234\n"),
        ]);

        $this->runArtisan('richter:benchmark:add', ['fix-commit' => 'deadbee'])
            ->expectsOutputToContain("'key' => 'abc1234'")
            ->assertSuccessful();
    }

    #[Test]
    public function benchmark_add_honors_an_explicit_key_option(): void
    {
        // The subject carries a derivable ticket id, so this proves --key wins over derivation.
        $this->fakeBenchmarkReplayReachingRoutes(['*log*' => Process::result("PROJ-42 Fix duplicated post reviews\n")]);

        $this->runArtisan('richter:benchmark:add', ['fix-commit' => 'abc1234', '--key' => 'CUSTOM-7'])
            ->expectsOutputToContain("'key' => 'CUSTOM-7'")
            ->assertSuccessful();
    }

    #[Test]
    public function benchmark_add_escapes_quotes_and_backslashes_in_the_stanza(): void
    {
        // An apostrophe in a commit subject is completely ordinary ("Fix user's dashboard");
        // this pins the one code path that keeps the printed stanza syntactically valid PHP.
        $this->fakeBenchmarkReplayReachingRoutes(['*log*' => Process::result("Fix user's dash\\board rendering\n")]);

        $this->runArtisan('richter:benchmark:add', ['fix-commit' => 'abc1234', '--key' => "O'Brien-7"])
            ->expectsOutputToContain('\'key\' => \'O\\\'Brien-7\'')
            ->expectsOutputToContain('\'bug_class\' => \'Fix user\\\'s dash\\\\board rendering\'')
            ->assertSuccessful();
    }

    #[Test]
    public function benchmark_add_stanza_is_valid_php_for_awkward_subjects(): void
    {
        // Same fixture as the test above, but pins the exact printed lines — indentation and
        // trailing comma included — rather than round-tripping the stanza through eval(), which
        // spaze/phpstan-disallowed-calls forbids in this codebase.
        $this->fakeBenchmarkReplayReachingRoutes(['*log*' => Process::result("Fix user's dash\\board rendering\n")]);

        $this->withoutMockingConsoleOutput();
        $exitCode = Artisan::call('richter:benchmark:add', ['fix-commit' => 'abc1234', '--key' => "O'Brien-7"]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('        \'key\' => \'O\\\'Brien-7\',', $output);
        $this->assertStringContainsString("        'fix_commit' => 'abc1234',", $output);
        $this->assertStringContainsString('        \'bug_class\' => \'Fix user\\\'s dash\\\\board rendering\',', $output);
    }

    #[Test]
    public function benchmark_add_prints_the_expect_finding_line_when_supplied(): void
    {
        $this->fakeBenchmarkReplayReachingRoutes(['*log*' => Process::result("PROJ-42 Fix duplicated post reviews\n")]);

        // The replay's findings never actually contain "layout", so the case would currently FAIL —
        // the stanza is still printed for the operator to paste in, unconditionally.
        $this->runArtisan('richter:benchmark:add', ['fix-commit' => 'abc1234', '--expect-finding' => 'layout'])
            ->expectsOutputToContain("'expect_finding' => 'layout'")
            ->assertFailed();
    }

    #[Test]
    public function benchmark_add_omits_the_expect_finding_line_when_not_supplied(): void
    {
        $this->fakeBenchmarkReplayReachingRoutes(['*log*' => Process::result("PROJ-42 Fix duplicated post reviews\n")]);

        $this->withoutMockingConsoleOutput();
        $exitCode = Artisan::call('richter:benchmark:add', ['fix-commit' => 'abc1234']);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringNotContainsString("'expect_finding'", $output);
    }

    #[Test]
    public function benchmark_add_fails_when_the_commit_changes_no_app_php(): void
    {
        Process::fake([
            '*cat-file*' => Process::result(),
            '*log*' => Process::result("subject\n"),
            '*merge-base*' => Process::result("base123\n"),
            '*diff*' => Process::result(''),
        ]);

        $this->runArtisan('richter:benchmark:add', ['fix-commit' => 'abc1234'])
            ->expectsOutputToContain('would never exercise')
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
     * so the configured case replays a modification inside ReviewController::show() — a member the
     * fixture graph resolves and walks up to its two routes (see CodeGraphBuilderTest). The testbench
     * skeleton's app/ is empty, so its graph could never resolve a seed, let alone reach an entry
     * point. Both `git show` sides return the real fixture source; the diff's line number is derived
     * from that exact source so the change lands inside the method's span, not at class level.
     *
     * @param  array<string, mixed>  $extraFakes  additional `Process::fake` patterns layered in ahead
     *   of the four replay patterns — kept in ONE `Process::fake` call, since repeated calls are an
     *   ordering trap.
     */
    private function fakeBenchmarkReplayReachingRoutes(array $extraFakes = []): void
    {
        $app = $this->app;
        $this->assertInstanceOf(Application::class, $app);
        $app->setBasePath(self::fixtureProjectPath());

        $file = 'app/Http/Controllers/Post/ReviewController.php';
        $source = (string) file_get_contents(self::fixtureProjectPath() . '/' . $file);
        $changedLine = array_search('        return ReviewResource::make($post);', explode("\n", $source), true);
        $this->assertIsInt($changedLine);
        ++$changedLine; // explode() indexes from 0, diff hunk headers from 1.

        $diff = "diff --git a/{$file} b/{$file}\n"
            . "--- a/{$file}\n"
            . "+++ b/{$file}\n"
            . "@@ -{$changedLine},1 +{$changedLine},1 @@\n"
            . "-        return ReviewResource::make(\$post->withoutRelations());\n"
            . "+        return ReviewResource::make(\$post);\n";

        Process::fake(array_merge($extraFakes, [
            '*cat-file*' => Process::result(),
            '*merge-base*' => Process::result("base123\n"),
            '*diff*' => Process::result($diff),
            '*show*' => Process::result($source),
        ]));
    }
}
