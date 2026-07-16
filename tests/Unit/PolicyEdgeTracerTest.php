<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use App\Http\Controllers\Video\DashboardSearchController;
use App\Policies\VideoPolicy;
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
        $source = "<?php\nnamespace App\Http\Controllers\Video;\n{$uses}\nclass DashboardSearchController\n{\n    public function __invoke(): void\n    {\n        {$body}\n    }\n}\n";

        return new PolicyEdgeTracer()->edgesForSource($source, self::CONTROLLER);
    }

    #[Test]
    public function it_links_a_user_can_policy_check_to_the_policy(): void
    {
        $edges = $this->edges('$user->can(VideoPolicy::UPDATE, $video);', 'use App\Policies\VideoPolicy;');

        $this->assertContains(['source' => self::CONTROLLER . '::__invoke', 'target' => VideoPolicy::class, 'type' => 'authorizes'], $edges);
    }

    #[Test]
    public function it_links_a_fully_qualified_policy_reference_without_an_import(): void
    {
        $edges = $this->edges('\App\Policies\VideoPolicy::UPDATE;', '');

        $this->assertContains(['source' => self::CONTROLLER . '::__invoke', 'target' => VideoPolicy::class, 'type' => 'authorizes'], $edges);
    }

    #[Test]
    public function it_emits_no_edge_when_no_policy_is_referenced(): void
    {
        $this->assertSame([], $this->edges('$user->cannot("update", $video);', ''));
    }

    #[Test]
    public function it_finds_fully_qualified_policy_references_in_blade(): void
    {
        $content = "@can(App\Policies\VideoPolicy::VIEW_STATS, \$video) x @endcan \$c = \\App\Policies\OtherPolicy::FOO;";

        $policies = new PolicyEdgeTracer()->policiesReferencedInBlade($content);

        $this->assertContains(VideoPolicy::class, $policies);
        $this->assertContains('App\Policies\OtherPolicy', $policies);
    }

    #[Test]
    public function it_finds_no_policy_in_blade_using_only_string_abilities(): void
    {
        $this->assertSame([], new PolicyEdgeTracer()->policiesReferencedInBlade('@can(\'update\', $video) x @endcan'));
    }

    #[Test]
    public function it_does_not_emit_a_self_edge_from_a_policy_to_itself_but_keeps_edges_to_other_policies(): void
    {
        $source = "<?php\nnamespace App\Policies;\nclass VideoPolicy\n{\n    public function update(): void\n    {\n        \\App\Policies\VideoPolicy::UPDATE;\n        \\App\Policies\OtherPolicy::VIEW;\n    }\n}\n";

        $edges = new PolicyEdgeTracer()->edgesForSource($source, VideoPolicy::class);

        $this->assertSame([['source' => VideoPolicy::class . '::update', 'target' => 'App\Policies\OtherPolicy', 'type' => 'authorizes']], $edges);
    }

    #[Test]
    public function blade_views_emit_authorizes_edges_from_their_own_pass(): void
    {
        // The PHP side now runs through the consolidated per-file AST loop in CodeGraphBuilder
        // (covered by edgesForSource tests above); the Blade side keeps its own file walk.
        $this->root = sys_get_temp_dir() . '/policy-edge-tracer-' . bin2hex(random_bytes(6));

        $this->write('resources/views/card.blade.php', '@can(App\Policies\VideoPolicy::VIEW_STATS, $video) x @endcan');

        $edges = new PolicyEdgeTracer()->bladeEdges($this->root);

        $this->assertContains(['source' => BladeViews::nodeId('card'), 'target' => VideoPolicy::class, 'type' => 'authorizes'], $edges);
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
