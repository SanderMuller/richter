<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use App\Http\Resources\Api\v2\Video\QuestionPlayerResource;
use App\Models\Interaction;
use App\Models\Video;
use Iterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Analysis\ImpactAnalyzer;
use SanderMuller\Richter\Analysis\ImpactFormatter;
use SanderMuller\Richter\Analysis\RiskLevel;
use SanderMuller\Richter\Analysis\TestReferenceIndex;
use SanderMuller\Richter\Changes\ChangedFileSymbols;
use SanderMuller\Richter\Changes\MemberChange;
use SanderMuller\Richter\Graph\CodeGraph;
use SanderMuller\Richter\Tests\TestCase;

final class ImpactFormatterTest extends TestCase
{
    private function method(string $file, string $fqcn, string $name): ChangedFileSymbols
    {
        return new ChangedFileSymbols($file, $fqcn, [
            new MemberChange($name, MemberChange::KIND_METHOD, MemberChange::CHANGE_MODIFIED, resolvable: true),
        ], cosmeticOnly: false);
    }

    /**
     * @param  list<string>  $entryPoints
     * @param  list<string>  $relatedModels
     * @return array{changed: array<string, int>, coverage: array<string, 'analyzed'|'unresolved'>, entryPoints: list<string>, impacted: int, relatedModels: list<string>, risk: RiskLevel, lowConfidence: bool, coarseCapApplied: bool}
     */
    private function summary(array $entryPoints, array $relatedModels = [], bool $lowConfidence = false, RiskLevel $risk = RiskLevel::Low, bool $coarseCapApplied = false): array
    {
        return [
            'changed' => ['app/Models/Video.php' => 1],
            'coverage' => ['app/Models/Video.php' => 'analyzed'],
            'entryPoints' => $entryPoints,
            'impacted' => count($entryPoints),
            'relatedModels' => $relatedModels,
            'risk' => $risk,
            'lowConfidence' => $lowConfidence,
            'coarseCapApplied' => $coarseCapApplied,
        ];
    }

    /**
     * Any non-additive change that resolves to no graph node — regardless of namespace — reads as
     * "couldn't determine", not a falsely-reassuring "no impact".
     *
     * @return Iterator<string, array{string, string}>
     */
    public static function unmappedChanges(): Iterator
    {
        yield 'API resource' => ['app/Http/Resources/Api/v2/Video/QuestionPlayerResource.php', QuestionPlayerResource::class];
        yield 'plain service' => ['app/Services/Lonely.php', 'App\Services\Lonely'];
    }

    #[Test]
    public function it_renders_source_findings_between_the_reach_and_the_risk(): void
    {
        $result = $this->summary(['route::GET /videos']) + ['findings' => ["app/Exports/X.php: eager-load string 'interactionsquestions' matches no relation"]];

        $output = ImpactFormatter::detectChanges($result);

        $this->assertStringContainsString('Findings (in the changed source itself):', $output);
        $this->assertStringContainsString("! app/Exports/X.php: eager-load string 'interactionsquestions' matches no relation", $output);
    }

    #[Test]
    public function it_renders_no_findings_section_when_there_are_none(): void
    {
        $this->assertStringNotContainsString('Findings', ImpactFormatter::detectChanges($this->summary([])));
    }

    #[Test]
    public function it_annotates_entry_points_with_their_test_reference_state(): void
    {
        $tests = new TestReferenceIndex();
        $tests->addSource('<?php $this->get("/covered");');

        $output = ImpactFormatter::detectChanges(
            $this->summary(['route::GET::/covered', 'route::GET::/uncovered', 'schedule::tick']),
            $tests,
        );

        $this->assertStringContainsString('route::GET::/covered  [test-referenced]', $output);
        $this->assertStringContainsString('route::GET::/uncovered  [⚠ no test references this]', $output);
        $this->assertStringContainsString("- schedule::tick\n", $output);
    }

    #[Test]
    public function it_renders_no_annotation_without_a_test_index(): void
    {
        $output = ImpactFormatter::detectChanges($this->summary(['route::GET::/covered']));

        $this->assertStringNotContainsString('[test-referenced]', $output);
        $this->assertStringNotContainsString('no test references', $output);
    }

    #[Test]
    #[DataProvider('unmappedChanges')]
    public function a_change_resolving_to_no_graph_node_reads_unresolved(string $file, string $fqcn): void
    {
        $result = new ImpactAnalyzer(new CodeGraph([]))->detectChanges([
            $this->method($file, $fqcn, 'run'),
        ]);

        $this->assertStringContainsString('UNRESOLVED', ImpactFormatter::detectChanges($result));
    }

    #[Test]
    public function a_change_to_a_graphed_but_uncalled_node_reads_analyzed_not_unresolved(): void
    {
        // In the graph but nothing calls it — a genuine leaf the tool can vouch for, so it reads
        // "analyzed" (no impact), not "couldn't determine".
        $result = new ImpactAnalyzer(new CodeGraph([
            ['source' => 'App\Services\Lonely::run', 'target' => Video::class, 'type' => 'action-to-model'],
        ]))->detectChanges([
            $this->method('app/Services/Lonely.php', 'App\Services\Lonely', 'run'),
        ]);

        $this->assertStringNotContainsString('UNRESOLVED', ImpactFormatter::detectChanges($result));
    }

    #[Test]
    public function a_coarse_change_renders_a_low_confidence_note_and_related_models_context(): void
    {
        $result = new ImpactAnalyzer(new CodeGraph([
            ['source' => Video::class, 'target' => Interaction::class, 'type' => 'model-relationship'],
        ]))->detectChanges([
            new ChangedFileSymbols('app/Models/Video.php', Video::class, [
                new MemberChange('fillable', MemberChange::KIND_PROPERTY, MemberChange::CHANGE_MODIFIED, resolvable: false),
            ], cosmeticOnly: false),
        ]);

        $text = ImpactFormatter::detectChanges($result);

        $this->assertStringContainsString('low confidence', $text);
        $this->assertStringContainsString('Related models', $text);
        $this->assertStringContainsString(Interaction::class, $text);
    }

    #[Test]
    public function the_cap_note_only_claims_a_cap_when_one_actually_fired(): void
    {
        // Capped HIGH→MEDIUM: the note explains the cap.
        $capped = ImpactFormatter::detectChanges(
            $this->summary(['route::GET::/r'], lowConfidence: true, risk: RiskLevel::Medium, coarseCapApplied: true),
        );
        $this->assertStringContainsString('risk capped at MEDIUM', $capped);

        // Low-confidence but precise seeds drove HIGH — the cap did not fire, so it must not be claimed.
        $notCapped = ImpactFormatter::detectChanges(
            $this->summary(['route::GET::/r'], lowConfidence: true, risk: RiskLevel::High),
        );
        $this->assertStringContainsString('low confidence', $notCapped);
        $this->assertStringNotContainsString('capped at MEDIUM', $notCapped);
    }

    #[Test]
    public function a_console_command_entry_point_renders_without_its_signature(): void
    {
        $text = ImpactFormatter::detectChanges(
            $this->summary(['command::vector-store:cleanup {--days=7 : Days to keep}']),
        );

        $this->assertStringContainsString('command::vector-store:cleanup', $text);
        $this->assertStringNotContainsString('{--days', $text);
    }

    #[Test]
    public function an_entry_point_list_over_the_cap_is_sampled_sorted_with_a_breadth_note(): void
    {
        // 20 entries fed in reverse order — the rendered sample must be the 15 lowest, sorted.
        $entryPoints = [];
        for ($i = 19; $i >= 0; --$i) {
            $entryPoints[] = sprintf('route::GET::/r%02d', $i);
        }

        $text = ImpactFormatter::detectChanges($this->summary($entryPoints));

        $this->assertStringContainsString('Entry points reached: 20', $text);
        $this->assertStringContainsString('route::GET::/r00', $text);
        $this->assertStringContainsString('route::GET::/r14', $text);
        $this->assertStringContainsString('… and 5 more', $text);
        $this->assertStringContainsString('breadth', $text);
        // The 5 highest are sampled out — they must not render.
        $this->assertStringNotContainsString('route::GET::/r15', $text);
        $this->assertStringNotContainsString('route::GET::/r19', $text);
        // Sorted: /r00 renders before /r14.
        $this->assertLessThan(mb_strpos($text, '/r14'), mb_strpos($text, '/r00'));
    }

    #[Test]
    public function a_list_at_the_cap_renders_in_full_without_a_breadth_note(): void
    {
        $entryPoints = [];
        for ($i = 0; $i < 15; ++$i) {
            $entryPoints[] = sprintf('route::GET::/r%02d', $i);
        }

        $text = ImpactFormatter::detectChanges($this->summary($entryPoints));

        $this->assertStringContainsString('route::GET::/r14', $text);
        $this->assertStringNotContainsString('… and', $text);
        $this->assertStringNotContainsString('breadth', $text);
    }

    #[Test]
    public function one_entry_over_the_cap_renders_and_1_more(): void
    {
        // Boundary: 16 entries must render the 15 lowest + exactly "… and 1 more", hiding the 16th.
        $entryPoints = [];
        for ($i = 0; $i < 16; ++$i) {
            $entryPoints[] = sprintf('route::GET::/r%02d', $i);
        }

        $text = ImpactFormatter::detectChanges($this->summary($entryPoints));

        $this->assertStringContainsString('… and 1 more', $text);
        $this->assertStringContainsString('route::GET::/r14', $text);
        $this->assertStringNotContainsString('route::GET::/r15', $text);
    }

    #[Test]
    public function zero_entry_points_and_related_models_render_no_breadth_note(): void
    {
        $text = ImpactFormatter::detectChanges($this->summary([]));

        $this->assertStringContainsString('Entry points reached: 0', $text);
        $this->assertStringNotContainsString('breadth', $text);
        $this->assertStringNotContainsString('Related models', $text);
    }

    #[Test]
    public function a_related_models_list_over_the_cap_is_summarised(): void
    {
        $models = [];
        for ($i = 0; $i < 30; ++$i) {
            $models[] = sprintf('App\Models\M%02d', $i);
        }

        $text = ImpactFormatter::detectChanges($this->summary(['route::GET::/r'], $models, lowConfidence: true));

        $this->assertStringContainsString('Related models (association reach — context, not risk): 30', $text);
        $this->assertStringContainsString('… and 15 more', $text);
        $this->assertStringContainsString('breadth', $text);
        // The breadth note coexists with the low-confidence note.
        $this->assertStringContainsString('low confidence', $text);
    }
}
