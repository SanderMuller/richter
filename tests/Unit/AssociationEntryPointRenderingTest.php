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
