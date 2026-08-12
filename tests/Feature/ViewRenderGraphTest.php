<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Feature;

use Illuminate\Foundation\Application;
use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Analysis\ImpactAnalyzer;
use SanderMuller\Richter\Graph\BladeViews;
use SanderMuller\Richter\Graph\CodeGraph;
use SanderMuller\Richter\Graph\CodeGraphBuilder;
use SanderMuller\Richter\Support\AppNamespace;
use SanderMuller\Richter\Tests\TestCase;

/**
 * The payoff for the view-render lane, end to end: a changed Blade view that only a Livewire
 * component renders. Before it, that view had a graph node and no caller, so every diff touching one
 * reported UNRESOLVED — a whole recurring class of "coverage incomplete" on files richter could place.
 */
final class ViewRenderGraphTest extends TestCase
{
    private const string COMPONENT = 'App\\Livewire\\StatusPanel';

    private const string VIEW_FILE = 'resources/views/livewire/status-panel.blade.php';

    private const string PAGE = 'App\\Pages\\SettingsPage';

    private const string DECLARED_VIEW_FILE = 'resources/views/pages/settings.blade.php';

    private static ?CodeGraph $graph = null;

    protected function setUp(): void
    {
        parent::setUp();

        $app = $this->app;
        $this->assertInstanceOf(Application::class, $app);
        $app->setBasePath($this->projectPath());

        AppNamespace::flush();
    }

    #[Test]
    public function a_changed_livewire_view_reaches_the_component_that_renders_it(): void
    {
        $seed = BladeViews::seedForChangedFile(self::VIEW_FILE);
        $this->assertNotNull($seed);

        $callers = array_column(new ImpactAnalyzer($this->graph())->impact($seed)['callers'], 'node');

        $this->assertContains(self::COMPONENT . '::render', $callers);
    }

    #[Test]
    public function the_component_reaches_its_view_downstream_too(): void
    {
        $reached = array_column(new ImpactAnalyzer($this->graph())->impact(self::COMPONENT . '::render')['dependencies'], 'node');

        $this->assertContains(BladeViews::seedForChangedFile(self::VIEW_FILE), $reached);
    }

    #[Test]
    public function a_changed_view_reaches_the_page_that_only_declares_it(): void
    {
        // The property form, where the subclass renders nothing and a base class does. Anchored on
        // the class rather than a member: there is no method to name, and inventing one would send a
        // reviewer to a symbol that does not exist.
        $seed = BladeViews::seedForChangedFile(self::DECLARED_VIEW_FILE);
        $this->assertNotNull($seed);

        $callers = array_column(new ImpactAnalyzer($this->graph())->impact($seed)['callers'], 'node');

        $this->assertContains(self::PAGE, $callers);
    }

    #[Test]
    public function a_controller_brain_already_covered_carries_exactly_one_edge_to_its_view(): void
    {
        // Both lanes emit `action-to-view`, so the merge's (source, target, type) dedupe collapses
        // them. A parallel type would have put two hops between the same pair in every chain.
        $matching = array_filter(
            $this->graph()->toArray()['edges'],
            static fn (array $edge): bool => $edge['source'] === 'App\\Http\\Controllers\\Post\\ReviewController::edit'
                && $edge['target'] === 'view::blade__posts.show',
        );

        $this->assertCount(1, $matching);
    }

    /** Built once per process: the build runs Brain plus every tracer over the fixture tree. */
    private function graph(): CodeGraph
    {
        return self::$graph ??= new CodeGraphBuilder()->build($this->projectPath());
    }

    private function projectPath(): string
    {
        return __DIR__ . '/../Fixtures/project';
    }
}
