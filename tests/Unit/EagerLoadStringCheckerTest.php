<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Tests\TestCase;
use SanderMuller\Richter\Tracers\EagerLoadStringChecker;

final class EagerLoadStringCheckerTest extends TestCase
{
    /** @return list<string> */
    private function findings(string $body): array
    {
        $source = "<?php\nnamespace App\Exports;\nuse App\Models\Question;\nuse App\Models\Video;\nclass VideoExport\n{\n    public function __construct(private Video \$video)\n    {\n        {$body}\n    }\n}\n";

        return new EagerLoadStringChecker(self::fixtureProjectPath() . '/app/Models')->findingsFor($source);
    }

    #[Test]
    public function a_valid_relation_constant_produces_no_finding(): void
    {
        $this->assertSame([], $this->findings('$this->video->load([Video::INTERACTIONS, Video::QUESTIONS . \'.\' . Question::ANSWERS]);'));
    }

    #[Test]
    public function a_broken_constant_concatenation_is_flagged(): void
    {
        // The HPB-5108 shape: a missing comma concatenates two relation constants into one invalid name.
        $findings = $this->findings('$this->video->load([Video::INTERACTIONS . Video::QUESTIONS . \'.\' . Question::ANSWERS]);');

        $this->assertCount(1, $findings);
        $this->assertStringContainsString("'interactionsquestions'", $findings[0]);
    }

    #[Test]
    public function a_plain_string_without_a_model_constant_is_not_checked(): void
    {
        $this->assertSame([], $this->findings('$this->video->load([\'definitelyNotARelation\']);'));
    }

    #[Test]
    public function a_typo_in_a_string_concatenated_with_a_model_constant_is_flagged(): void
    {
        $findings = $this->findings('$this->video->load(Video::QUESTIONS . \'.answerz\');');

        $this->assertCount(1, $findings);
        $this->assertStringContainsString("'answerz'", $findings[0]);
    }

    #[Test]
    public function an_array_key_relation_with_a_closure_constraint_is_checked(): void
    {
        $findings = $this->findings('$this->video->load([Video::QUESTIONS . \'x\' => fn ($q) => $q]);');

        $this->assertCount(1, $findings);
        $this->assertStringContainsString("'questionsx'", $findings[0]);
    }

    #[Test]
    public function a_dynamic_argument_is_skipped(): void
    {
        $this->assertSame([], $this->findings('$this->video->load($this->relations());'));
    }

    #[Test]
    public function a_column_selection_suffix_is_ignored(): void
    {
        $this->assertSame([], $this->findings('$this->video->load(Video::QUESTIONS . \':id,title\');'));
    }

    #[Test]
    public function a_non_load_call_is_not_checked(): void
    {
        $this->assertSame([], $this->findings('$this->video->update([Video::QUESTIONS . \'zz\' => 1]);'));
    }

    #[Test]
    public function a_bare_has_call_is_not_checked(): void
    {
        // `has` is overloaded (Request, Session, Collection) — a model column constant passed to it
        // must not fire; the tracer still follows it for reach.
        $this->assertSame([], $this->findings('$request->has(Video::QUESTIONS . \'zz\');'));
    }

    #[Test]
    public function a_with_only_call_is_checked(): void
    {
        $findings = $this->findings('$this->video->withOnly(Video::QUESTIONS . \'zz\');');

        $this->assertCount(1, $findings);
        $this->assertStringContainsString("'questionszz'", $findings[0]);
    }

    #[Test]
    public function an_incomplete_model_set_reports_a_single_visible_skip_note_instead_of_findings(): void
    {
        // A models directory whose classes cannot be autoloaded degrades the checker: it must
        // suppress validation visibly (one note per file), never silently — and never fire false
        // alarms from the shrunken set.
        $checker = new EagerLoadStringChecker(dirname(__DIR__) . '/Fixtures/broken-models');

        $source = "<?php\nnamespace App\Exports;\nuse App\Models\Video;\nclass VideoExport\n{\n    public function a(): void { \$x->load(Video::QUESTIONS . 'zz'); }\n    public function b(): void { \$x->load(Video::INTERACTIONS . 'yy'); }\n}\n";

        $findings = $checker->findingsFor($source);

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('eager-load check skipped', $findings[0]);
    }
}
