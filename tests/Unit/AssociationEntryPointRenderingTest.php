<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Analysis\HtmlFormatter;
use SanderMuller\Richter\Analysis\ImpactAnalyzer;
use SanderMuller\Richter\Analysis\ImpactFormatter;
use SanderMuller\Richter\Analysis\JsonPresenter;
use SanderMuller\Richter\Analysis\MarkdownFormatter;
use SanderMuller\Richter\Changes\ChangedFileSymbols;
use SanderMuller\Richter\Changes\MemberChange;
use SanderMuller\Richter\Graph\CodeGraph;
use SanderMuller\Richter\Tests\TestCase;

/**
 * Demoting a surface out of the reached list only helps if every format still shows it somewhere.
 * The first cut of the split printed it in the plain-text report alone, so the same change reported
 * the surface in one output mode and silently dropped it in the other four — worse than the
 * over-counting the split exists to end. One test per renderer, because the failure was per renderer.
 */
final class AssociationEntryPointRenderingTest extends TestCase
{
    private const string SURFACE = 'App\\Filament\\Resources\\CommentResource';

    #[Test]
    public function every_detect_changes_renderer_shows_an_association_surface(): void
    {
        $result = $this->analyzer()->detectChanges($this->changed());

        $this->assertSame([self::SURFACE], $result['associationEntryPoints']);

        $this->assertStringContainsString(self::SURFACE, ImpactFormatter::detectChanges($result));
        $this->assertStringContainsString(self::SURFACE, MarkdownFormatter::detectChanges($result));
        $this->assertStringContainsString(self::SURFACE, HtmlFormatter::detectChanges($result, [], 'main'));
        $this->assertContains(self::SURFACE, JsonPresenter::detectChanges($result, 'main')['associationEntryPoints']);
    }

    #[Test]
    public function it_is_never_counted_among_the_reached_entry_points(): void
    {
        $result = $this->analyzer()->detectChanges($this->changed());

        $this->assertNotContains(self::SURFACE, $result['entryPoints']);
        $this->assertStringContainsString('only by association', ImpactFormatter::detectChanges($result));
    }

    #[Test]
    public function an_explained_chain_never_runs_through_an_edge_the_classification_excluded(): void
    {
        // A route with BOTH a call path and a shorter association path stays in the main list — and
        // its chain has to be the call one. Explaining it through the relation would present an
        // association as the reason it calls the change, contradicting the list it appears in.
        $result = new ImpactAnalyzer(new CodeGraph([
            ['source' => 'App\\Models\\Comment', 'target' => 'App\\Models\\Post', 'type' => 'model-relationship'],
            ['source' => 'route::GET::/posts', 'target' => 'App\\Models\\Comment', 'type' => 'call'],
            ['source' => 'route::GET::/posts', 'target' => 'App\\Services\\Publisher::run', 'type' => 'route-to-controller'],
            ['source' => 'App\\Services\\Publisher::run', 'target' => 'App\\Models\\Post', 'type' => 'call'],
        ], hasUnparseableFiles: false))->detectChanges($this->changed());

        $this->assertContains('route::GET::/posts', $result['entryPoints']);

        $vias = array_column($result['entryPointPaths']['route::GET::/posts'], 'via');
        $this->assertNotContains('model-relationship', $vias);
    }

    #[Test]
    public function each_association_surface_records_why_it_is_listed(): void
    {
        $result = $this->analyzer()->detectChanges($this->changed());

        // Without this map the section can only hedge across the whole list ("a model relation OR a
        // registry lookup"), and a reader cannot tell a link that names one model from one that names
        // every class a registry lists.
        $this->assertSame(['model-relationship'], $result['associationEntryPointsVia'][self::SURFACE]);
        $this->assertSame(
            ['model-relationship'],
            JsonPresenter::detectChanges($result, 'main')['associationEntryPointsVia'][self::SURFACE],
        );
    }

    #[Test]
    public function a_registry_fanout_surface_collapses_while_a_named_relation_stays_inline(): void
    {
        // The whole point of the split: a registry names no single class, so the same surfaces answer
        // for every class it lists. Those fold under one shared cause; a model relation names ONE
        // model and keeps its line.
        $fanout = 'App\\Filament\\Pages\\FormulaSetup';

        $result = new ImpactAnalyzer(new CodeGraph([
            ['source' => 'App\\Models\\Comment', 'target' => 'App\\Models\\Post', 'type' => 'model-relationship'],
            ['source' => self::SURFACE, 'target' => 'App\\Models\\Comment', 'type' => 'call'],
            ['source' => $fanout, 'target' => 'App\\Models\\Post', 'type' => 'config-registry-fanout'],
        ], hasUnparseableFiles: false))->detectChanges($this->changed());

        $this->assertSame(['config-registry-fanout'], $result['associationEntryPointsVia'][$fanout]);

        $markdown = MarkdownFormatter::detectChanges($result);

        // Both are still reported — nothing is dropped — but only the fan-out one is behind a summary.
        $this->assertStringContainsString($fanout, $markdown);
        $this->assertStringContainsString(self::SURFACE, $markdown);
        $this->assertMatchesRegularExpression(
            '/<summary>1 more, reached only through a registry lookup that names no single class/',
            $markdown,
        );
        // The discriminating surface must NOT be the one inside the collapsed block.
        $collapsed = substr($markdown, (int) strpos($markdown, '<details>'));
        $this->assertStringNotContainsString(self::SURFACE, $collapsed);

        // The text report states the count rather than listing them, and never hides the total.
        $text = ImpactFormatter::detectChanges($result);
        $this->assertStringContainsString('only by association (context, not callers): 2', $text);
        $this->assertStringContainsString('and 1 reached only through a registry lookup', $text);
    }

    /** A Filament resource that touches a model RELATED to the changed one: associated, not a caller. */
    private function analyzer(): ImpactAnalyzer
    {
        return new ImpactAnalyzer(new CodeGraph([
            ['source' => 'App\\Models\\Comment', 'target' => 'App\\Models\\Post', 'type' => 'model-relationship'],
            ['source' => self::SURFACE, 'target' => 'App\\Models\\Comment', 'type' => 'call'],
        ], hasUnparseableFiles: false));
    }

    /** @return list<ChangedFileSymbols> */
    private function changed(): array
    {
        return [
            new ChangedFileSymbols('app/Models/Post.php', 'App\\Models\\Post', [
                new MemberChange('fillable', MemberChange::KIND_PROPERTY, MemberChange::CHANGE_MODIFIED, resolvable: false),
            ], cosmeticOnly: false),
        ];
    }
}
