<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Tests\Support\RebindsConsoleOutput;
use SanderMuller\Richter\Tests\TestCase;

/**
 * A host package may rebind `Illuminate\Console\OutputStyle` and rewrite everything an Artisan command
 * prints. `laravel/pao` does exactly that when an agent is driving, to save the agent tokens.
 *
 * On richter's prose report that trade is right and this suite asserts it still happens. On a document
 * whose whitespace and symbols carry meaning it is destructive, and these tests pin that those documents
 * are written where the rebound writer cannot reach them.
 *
 * The stand-in below is `Laravel\Pao\OutputCleaner::clean()` copied verbatim, all seven transformations.
 * A shorter stand-in was tried first and was worse than useless: it reproduced only the whitespace half
 * and missed the glyph stripping, which is the destructive part.
 */
final class DocumentOutputIsByteSafeTest extends TestCase
{
    use RebindsConsoleOutput;

    #[Test]
    public function the_cleaner_stand_in_really_does_damage(): void
    {
        // Guard against the whole suite passing because the stand-in is inert. If this ever stops
        // failing to preserve the input, every assertion below is vacuous.
        $doc = "alpha\n\n  - two-space\n→ arrow ⚠ warn";

        $this->assertNotSame($doc, $this->cleanLikePao($doc), 'the stand-in cleaner must actually alter this document');
        $this->assertStringNotContainsString('→', $this->cleanLikePao($doc), 'the stand-in must strip the arrow glyph');
        $this->assertStringNotContainsString('  - ', $this->cleanLikePao($doc), 'the stand-in must collapse the indent');
        $this->assertStringNotContainsString("\n\n", $this->cleanLikePao($doc), 'the stand-in must drop the blank line');
    }

    #[Test]
    public function a_markdown_report_survives_a_rebound_writer_and_the_prose_report_does_not(): void
    {
        $this->fakeDiffReachingRoutes();
        $this->installPaoLikeCleaner();

        Artisan::call('richter:detect-changes', ['--base' => 'origin/main', '--markdown' => true]);
        $markdown = Artisan::output();

        // Both halves of what the cleaner would take. The blank line is what GitHub needs to parse a
        // fold at all; the glyphs are content — a deleted arrow is deleted meaning, and both are in the
        // cleaner's strip set.
        $this->assertStringContainsString("\n\n", $markdown, 'markdown lost its blank lines');
        $this->assertStringContainsString('→', $markdown, 'markdown lost the arrow glyph the cleaner strips');
        $this->assertStringContainsString('⚠', $markdown, 'markdown lost the warning glyph the cleaner strips');

        Artisan::call('richter:detect-changes', ['--base' => 'origin/main']);
        $prose = Artisan::output();

        // The other half of the contract, and the half a future change is most likely to break by
        // "consistently" protecting everything: the prose report is meant to be compacted.
        $this->assertStringNotContainsString("\n\n", $prose, 'the prose report must stay compacted — it is not a byte-sensitive document');
        $this->assertStringNotContainsString('→', $prose, 'the prose report must stay compacted, glyphs included');
    }

    #[Test]
    public function a_json_document_survives_a_rebound_writer(): void
    {
        $this->fakeDiffReachingRoutes();
        $this->installPaoLikeCleaner();

        Artisan::call('richter:detect-changes', ['--base' => 'origin/main', '--json' => true]);
        $json = Artisan::output();

        $decoded = json_decode($json, true);
        $this->assertIsArray($decoded, 'the JSON document must still parse');
        // Pretty-print survives, which is the visible proof the cleaner never touched it.
        $this->assertStringContainsString('    "', $json, 'the JSON document lost its indentation');
    }

    #[Test]
    public function a_json_error_document_keeps_a_message_the_cleaner_would_rewrite(): void
    {
        // The case that decided `--json`: error documents embed text richter does not control. A double
        // space in the message is the cheapest stand-in for the exception text that reaches these paths.
        $this->installPaoLikeCleaner();

        Artisan::call('richter:detect-changes', ['--json' => true, '--fail-on' => 'bogus  value']);
        $decoded = json_decode(Artisan::output(), true);

        $this->assertIsArray($decoded);
        $error = $decoded['error'] ?? null;
        $this->assertIsString($error);
        $this->assertStringContainsString('bogus  value', $error, 'the error message lost the double space it was given');
    }

    #[Test]
    public function the_plain_lane_keeps_its_exactly_empty_contract_under_a_rebound_writer(): void
    {
        // `--plain` feeds command substitution, so its stdout contract is "test paths and nothing else".
        // This pins that routing it through the protected writer did not add a stray byte.
        //
        // KNOWN LIMIT, stated rather than papered over: no assertion here can prove the routing itself.
        // Cleaning is a measured no-op on a list of test paths — the only thing it would alter is a path
        // containing two consecutive spaces or a stripped glyph, and no fixture in this suite produces a
        // non-empty selection, let alone one with such a path. So `--plain` is routed on the same reasoning
        // as the rest and reviewed by eye, and reverting it breaks no test. The protection is free, which
        // is why it is there.
        $this->installPaoLikeCleaner();

        $exitCode = Artisan::call('richter:affected-tests', ['--base' => 'HEAD', '--plain' => true]);

        $this->assertSame(0, $exitCode);
        $this->assertSame('', trim(Artisan::output()));
    }

    #[Test]
    public function a_protected_document_still_honours_quiet(): void
    {
        $this->fakeDiffReachingRoutes();
        $this->installPaoLikeCleaner();

        Artisan::call('richter:detect-changes', ['--base' => 'origin/main', '--markdown' => true, '--quiet' => true]);

        $this->assertSame('', Artisan::output(), '--quiet must still suppress a protected document');
    }

    /** The same diff shape the command tests use, so a real report is produced. */
    private function fakeDiffReachingRoutes(): void
    {
        $diff = "diff --git a/app/Models/User.php b/app/Models/User.php\n--- a/app/Models/User.php\n+++ b/app/Models/User.php\n@@ -0,0 +1,1 @@\n+    public function added(): void {}\n";

        Process::fake([
            '*merge-base*' => Process::result("abc123\n"),
            '*show*' => Process::result(errorOutput: 'bad object', exitCode: 128),
            '*diff*' => Process::result($diff),
            '*' => Process::result(),
        ]);
    }
}
