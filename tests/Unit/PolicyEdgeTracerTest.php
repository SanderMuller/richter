<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use App\Http\Controllers\Post\DashboardSearchController;
use App\Policies\PostPolicy;
use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Graph\BladeViews;
use SanderMuller\Richter\Tests\TestCase;
use SanderMuller\Richter\Tracers\PolicyEdgeTracer;

final class PolicyEdgeTracerTest extends TestCase
{
    private const string CONTROLLER = DashboardSearchController::class;

    private string $root = '';

    protected function tearDown(): void
    {
        if ($this->root !== '' && is_dir($this->root)) {
            $this->deleteTree($this->root);
        }

        parent::tearDown();
    }

    /**
     * @return list<array{source: string, target: string, type: string}>
     */
    private function edges(string $body, string $uses): array
    {
        $source = "<?php\nnamespace App\Http\Controllers\Post;\n{$uses}\nclass DashboardSearchController\n{\n    public function __invoke(): void\n    {\n        {$body}\n    }\n}\n";

        return new PolicyEdgeTracer()->edgesForSource($source, self::CONTROLLER);
    }

    #[Test]
    public function it_links_a_user_can_policy_check_to_the_policy(): void
    {
        $edges = $this->edges('$user->can(PostPolicy::UPDATE, $post);', 'use App\Policies\PostPolicy;');

        $this->assertContains(['source' => self::CONTROLLER . '::__invoke', 'target' => PostPolicy::class, 'type' => 'authorizes'], $edges);
    }

    #[Test]
    public function it_links_a_fully_qualified_policy_reference_without_an_import(): void
    {
        $edges = $this->edges('\App\Policies\PostPolicy::UPDATE;', '');

        $this->assertContains(['source' => self::CONTROLLER . '::__invoke', 'target' => PostPolicy::class, 'type' => 'authorizes'], $edges);
    }

    #[Test]
    public function it_emits_no_edge_when_no_policy_is_referenced(): void
    {
        $this->assertSame([], $this->edges('$user->cannot("update", $post);', ''));
    }

    #[Test]
    public function it_finds_fully_qualified_policy_references_in_blade(): void
    {
        $content = "@can(App\Policies\PostPolicy::VIEW_STATS, \$post) x @endcan \$c = \\App\Policies\OtherPolicy::FOO;";

        $policies = new PolicyEdgeTracer()->policiesReferencedInBlade($content);

        $this->assertContains(PostPolicy::class, $policies);
        $this->assertContains('App\Policies\OtherPolicy', $policies);
    }

    #[Test]
    public function it_finds_no_policy_in_blade_using_only_string_abilities(): void
    {
        $this->assertSame([], new PolicyEdgeTracer()->policiesReferencedInBlade('@can(\'update\', $post) x @endcan'));
    }

    #[Test]
    public function it_does_not_emit_a_self_edge_from_a_policy_to_itself_but_keeps_edges_to_other_policies(): void
    {
        $source = "<?php\nnamespace App\Policies;\nclass PostPolicy\n{\n    public function update(): void\n    {\n        \\App\Policies\PostPolicy::UPDATE;\n        \\App\Policies\OtherPolicy::VIEW;\n    }\n}\n";

        $edges = new PolicyEdgeTracer()->edgesForSource($source, PostPolicy::class);

        $this->assertSame([['source' => PostPolicy::class . '::update', 'target' => 'App\Policies\OtherPolicy', 'type' => 'authorizes']], $edges);
    }

    #[Test]
    public function blade_views_emit_authorizes_edges_from_their_own_pass(): void
    {
        // The PHP side now runs through the consolidated per-file AST loop in CodeGraphBuilder
        // (covered by edgesForSource tests above); the Blade side keeps its own file walk.
        $this->root = sys_get_temp_dir() . '/policy-edge-tracer-' . bin2hex(random_bytes(6));

        $this->write('resources/views/card.blade.php', '@can(App\Policies\PostPolicy::VIEW_STATS, $post) x @endcan');

        $edges = new PolicyEdgeTracer()->bladeEdges($this->root);

        $this->assertContains(['source' => BladeViews::nodeId('card'), 'target' => PostPolicy::class, 'type' => 'authorizes'], $edges);
    }

    private function write(string $relativePath, string $content): void
    {
        $file = $this->root . '/' . $relativePath;
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
