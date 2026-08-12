<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use PhpParser\Node\Stmt\ClassLike;
use PhpParser\NodeFinder;
use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Graph\BladeViews;
use SanderMuller\Richter\Support\AppFiles;
use SanderMuller\Richter\Tests\TestCase;
use SanderMuller\Richter\Tracers\ViewRenderTracer;

/**
 * A class no route resolves to — a Livewire component, a Filament page, a mailable — has its body
 * skipped by a route-anchored walk, so the view it renders ends up with no caller and a change to
 * that view reads UNRESOLVED. These pin the call this reads instead, and the shapes that must draw
 * nothing rather than point an edge at a node the graph does not share.
 */
final class ViewRenderTracerTest extends TestCase
{
    private const string CALLER = 'App\\Livewire\\StatusPanel';

    #[Test]
    public function a_literal_view_call_reaches_the_view_node(): void
    {
        $this->assertSame([[
            'source' => self::CALLER . '::render',
            'target' => 'view::blade__livewire.status_panel',
            'type' => 'action-to-view',
        ]], $this->edges("return view('livewire.status-panel');"));
    }

    #[Test]
    public function the_view_facade_form_is_recognised(): void
    {
        $this->assertCount(1, $this->edges("return \\Illuminate\\Support\\Facades\\View::make('livewire.status-panel');"));
    }

    #[Test]
    public function the_facade_method_name_is_matched_case_insensitively(): void
    {
        $this->assertCount(1, $this->edges("return \\Illuminate\\Support\\Facades\\View::MAKE('livewire.status-panel');"));
    }

    #[Test]
    public function brains_own_edge_type_is_reused_so_a_controller_it_already_covered_yields_one_edge(): void
    {
        // The merge dedupes on (source, target, type). Minting a parallel type would put two hops
        // between the same pair in every --explain chain for a route-anchored controller.
        $this->assertSame('action-to-view', $this->edges("return view('livewire.status-panel');")[0]['type']);
    }

    #[Test]
    public function a_slash_delimited_view_name_lands_on_the_same_node_as_its_file(): void
    {
        // Laravel accepts either separator. Left unfolded, the slash hits the slug's `_` rule and
        // produces a node no changed-file seed ever mints, so the edge exists and the view stays
        // unresolved anyway — the failure looks exactly like no coverage at all.
        $this->assertSame(
            BladeViews::seedForChangedFile('resources/views/livewire/status-panel.blade.php'),
            $this->edges("return view('livewire/status-panel');")[0]['target'],
        );
    }

    #[Test]
    public function a_computed_view_name_draws_nothing(): void
    {
        // `view($this->template)` names no view, and guessing one would point the reader at an
        // unrelated screen.
        $this->assertSame([], $this->edges('return view($this->template);'));
    }

    #[Test]
    public function an_interpolated_view_name_draws_nothing(): void
    {
        $this->assertSame([], $this->edges('return view("livewire.{$panel}");'));
    }

    #[Test]
    public function a_view_with_no_blade_file_here_draws_nothing(): void
    {
        // A package-namespaced name (`mail::message`) resolves outside `resources/views`. An edge to
        // it would mint a view node nothing else in the graph shares — reach that leads nowhere.
        $this->assertSame([], $this->edges("return view('mail::message');"));
    }

    #[Test]
    public function a_render_in_a_second_class_in_the_file_is_attributed_to_that_class(): void
    {
        $source = "<?php\nnamespace App\\Livewire;\n"
            . "class StatusPanel { public function render(): void {} }\n"
            . "class SecondaryPanel { public function render(): mixed { return view('livewire.status-panel'); } }\n";

        $this->assertSame([[
            'source' => 'App\\Livewire\\SecondaryPanel::render',
            'target' => 'view::blade__livewire.status_panel',
            'type' => 'action-to-view',
        ]], $this->edgesForSource($source));
    }

    #[Test]
    public function a_render_inside_an_anonymous_class_is_credited_to_the_method_that_builds_it(): void
    {
        // An anonymous class has no name to be an edge source, and naming it after the file's
        // primary class invents `StatusPanel::render` — a member that does not exist, so a reviewer
        // opens it and finds nothing. The method that builds the class is the true owner: its return
        // value is what renders, so a change to the view really does affect `build()`.
        $source = "<?php\nnamespace App\\Livewire;\n"
            . "class StatusPanel { public function build(): object { return new class { public function render(): mixed { return view('livewire.status-panel'); } }; } }\n";

        $sources = array_column($this->edgesForSource($source), 'source');

        $this->assertSame(['App\\Livewire\\StatusPanel::build'], $sources);
    }

    /**
     * @return list<array{source: string, target: string, type: string}>
     */
    private function edges(string $body): array
    {
        return $this->edgesForSource("<?php\nnamespace App\\Livewire;\nclass StatusPanel\n{\n    public function render(): mixed\n    {\n        {$body}\n    }\n}\n");
    }

    /**
     * @return list<array{source: string, target: string, type: string}>
     */
    private function edgesForSource(string $source): array
    {
        $ast = AppFiles::parseResolved($source);
        $this->assertNotNull($ast);

        /** @var list<ClassLike> $classLikes */
        $classLikes = array_values(new NodeFinder()->findInstanceOf($ast, ClassLike::class));

        return new ViewRenderTracer(__DIR__ . '/../Fixtures/project')->edgesForClassLikes($classLikes);
    }
}
