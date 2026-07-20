<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Graph\BladeViews;
use SanderMuller\Richter\Tests\TestCase;
use SanderMuller\Richter\Tracers\BladeViewTracer;

final class BladeViewTracerTest extends TestCase
{
    private string $root = '';

    protected function tearDown(): void
    {
        if ($this->root !== '' && is_dir($this->root)) {
            $this->deleteTree($this->root);
        }

        parent::tearDown();
    }

    #[Test]
    public function it_extracts_component_and_include_candidates_but_skips_unpinnable_references(): void
    {
        $content = <<<'BLADE'
        <x-post-dashboard.post-action-buttons :post="$post"/>
        @include('dashboard.home.post-item')
        @extends('layouts.app')
        <x-slot name="header"></x-slot>
        <x-dynamic-component :component="$c"/>
        @include($dynamic)
        @include('pkg::thing')
        BLADE;

        $candidates = new BladeViewTracer()->referencedViewCandidates($content);

        $this->assertContains('components.post-dashboard.post-action-buttons', $candidates);
        $this->assertContains('components.post-dashboard.post-action-buttons.index', $candidates);
        $this->assertContains('dashboard.home.post-item', $candidates);
        $this->assertContains('layouts.app', $candidates);

        // <x-slot>, <x-dynamic-component>, a dynamic name and a namespaced view can't be pinned to a file.
        $this->assertNotContains('components.slot', $candidates);
        $this->assertNotContains('components.dynamic-component', $candidates);
        $this->assertNotContains('pkg::thing', $candidates);
    }

    #[Test]
    public function it_links_a_view_to_a_rendered_component_and_an_include_that_exist_on_disk(): void
    {
        $this->root = sys_get_temp_dir() . '/blade-view-tracer-' . bin2hex(random_bytes(6));

        $this->writeView('dashboard/home/post-item', "<x-post-dashboard.post-action-buttons/> @include('partials.footer') <x-ghost/>");
        $this->writeView('components/post-dashboard/post-action-buttons', 'buttons');
        $this->writeView('partials/footer', 'footer');

        $edges = new BladeViewTracer()->trace($this->root);

        $parent = BladeViews::nodeId('dashboard.home.post-item');
        $this->assertContains(['source' => $parent, 'target' => BladeViews::nodeId('components.post-dashboard.post-action-buttons'), 'type' => 'view-to-view'], $edges);
        $this->assertContains(['source' => $parent, 'target' => BladeViews::nodeId('partials.footer'), 'type' => 'view-to-view'], $edges);

        // <x-ghost/> has no Blade file, so no dangling edge is minted for it.
        $targets = array_column($edges, 'target');
        $this->assertNotContains(BladeViews::nodeId('components.ghost'), $targets);
    }

    #[Test]
    public function it_resolves_a_component_that_exists_only_as_a_folder_index_file(): void
    {
        $this->root = sys_get_temp_dir() . '/blade-view-tracer-' . bin2hex(random_bytes(6));

        $this->writeView('parent', '<x-chat/>');
        $this->writeView('components/chat/index', 'chat');

        $edges = new BladeViewTracer()->trace($this->root);

        $this->assertContains(['source' => BladeViews::nodeId('parent'), 'target' => BladeViews::nodeId('components.chat.index'), 'type' => 'view-to-view'], $edges);
    }

    private function writeView(string $viewPath, string $content): void
    {
        $file = $this->root . '/resources/views/' . $viewPath . '.blade.php';
        @mkdir(dirname($file), 0o777, recursive: true);
        file_put_contents($file, $content);
    }

    private function deleteTree(string $dir): void
    {
        $entries = scandir($dir);

        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry === '.') {
                continue;
            }

            if ($entry === '..') {
                continue;
            }

            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->deleteTree($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
