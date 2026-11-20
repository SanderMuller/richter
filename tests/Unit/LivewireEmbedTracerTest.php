<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Graph\BladeViews;
use SanderMuller\Richter\Graph\TracerNodeCollector;
use SanderMuller\Richter\Support\AppFiles;
use SanderMuller\Richter\Tests\TestCase;
use SanderMuller\Richter\Tracers\LivewireEmbedTracer;

final class LivewireEmbedTracerTest extends TestCase
{
    private const array CLASSES = [
        'App\\Livewire\\Posts\\CommentList' => ['parent' => null, 'declared' => []],
        'App\\Livewire\\Inbox' => ['parent' => null, 'declared' => []],
        'App\\Livewire\\Archive' => ['parent' => null, 'declared' => []],
        'App\\Http\\Panels\\BasePanel' => ['parent' => 'Livewire\\Component', 'declared' => []],
        'App\\Http\\Panels\\ExportPanel' => ['parent' => 'App\\Http\\Panels\\BasePanel', 'declared' => []],
        'App\\Models\\Post' => ['parent' => 'Illuminate\\Database\\Eloquent\\Model', 'declared' => []],
    ];

    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/livewire-embed-tracer-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            $this->deleteTree($this->root);
        }

        parent::tearDown();
    }

    #[Test]
    public function it_links_a_view_to_the_components_its_tags_and_directives_name(): void
    {
        $this->writeView('posts/show', <<<'BLADE'
        <livewire:posts.comment-list :post="$post" />
        @livewire('inbox')
        @livewire(\App\Livewire\Archive::class)
        <livewire:pkg::widget />
        <livewire:missing-component />
        BLADE);

        $targets = $this->targetsOf(new LivewireEmbedTracer(), 'posts.show');

        $this->assertSame(['App\\Livewire\\Archive', 'App\\Livewire\\Inbox', 'App\\Livewire\\Posts\\CommentList'], $targets);
    }

    #[Test]
    public function a_runtime_pick_links_the_livewire_components_the_called_methods_name(): void
    {
        $tracer = $this->tracerCollecting(<<<'PHP'
        <?php
        namespace App\Enums;
        use App\Http\Panels\ExportPanel;
        use App\Livewire\Inbox;
        use App\Models\Post;
        enum Tab: string
        {
            case A = 'a';
            public function component(): string { return $this === self::A ? Inbox::class : ExportPanel::class; }
            public function model(): string { return Post::class; }
            public function unused(): string { return \App\Livewire\Archive::class; }
        }
        PHP);

        $this->writeView('settings', '<livewire:dynamic-component :is="$tab->component()" :model="$tab->model()" />');
        $this->writeView('plain', '{{ $tab->component() }}');

        // ExportPanel is a component by its parent chain, Inbox by its namespace. Post is no
        // component, and Archive is named only by a method the view does not call.
        $this->assertSame(['App\\Http\\Panels\\ExportPanel', 'App\\Livewire\\Inbox'], $this->targetsOf($tracer, 'settings'));
        // A view without a runtime pick embeds nothing through a method call.
        $this->assertSame([], $this->targetsOf($tracer, 'plain'));
    }

    #[Test]
    public function the_directive_with_a_variable_is_a_runtime_pick_too(): void
    {
        $tracer = $this->tracerCollecting('<?php namespace App\Enums; final class Tabs { public function current(): string { return \App\Livewire\Inbox::class; } }');

        $this->writeView('dashboard', '@livewire($tabs->current())');

        $this->assertSame(['App\\Livewire\\Inbox'], $this->targetsOf($tracer, 'dashboard'));
    }

    #[Test]
    public function a_project_without_views_has_no_edges(): void
    {
        $this->assertSame([], new LivewireEmbedTracer()->edges($this->root, self::CLASSES));
    }

    private function tracerCollecting(string $source): LivewireEmbedTracer
    {
        $collector = new TracerNodeCollector();
        AppFiles::parseResolved($source, $collector);

        $tracer = new LivewireEmbedTracer();
        $tracer->collect($collector->classMethods);

        return $tracer;
    }

    /** @return list<string> */
    private function targetsOf(LivewireEmbedTracer $tracer, string $view): array
    {
        $targets = [];

        foreach ($tracer->edges($this->root, self::CLASSES) as $edge) {
            $this->assertSame(LivewireEmbedTracer::EDGE_TYPE, $edge['type']);

            if ($edge['source'] === BladeViews::nodeId($view)) {
                $targets[] = $edge['target'];
            }
        }

        sort($targets);

        return $targets;
    }

    private function writeView(string $viewPath, string $content): void
    {
        $file = $this->root . '/resources/views/' . $viewPath . '.blade.php';
        @mkdir(dirname($file), 0o777, recursive: true);
        file_put_contents($file, $content);
    }
}
