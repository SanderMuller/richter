<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Analysis\ImpactAnalyzer;
use SanderMuller\Richter\Graph\CodeGraph;
use SanderMuller\Richter\Tests\TestCase;

/**
 * Spec `override-fan-out-walk-policy`, phase `gate`: an `override` hop out of a node whose whole path
 * from the seed was structural is refused, so one class in a wide interface hierarchy stops dragging
 * in every sibling implementor. Everything reached by a path that carries a call still fans out —
 * that is Class-Hierarchy Analysis doing its job.
 */
final class OverrideWalkGateTest extends TestCase
{
    private const string INTERFACE_FQCN = 'App\Contracts\Exportable';

    private const string INTERFACE_METHOD = 'App\Contracts\Exportable::export';

    /**
     * One app interface, three implementors. The `override` edges run ancestor → descendant, exactly
     * as `ClassHierarchyTracer` emits them, and each class declares its own member.
     *
     * @param  list<array{source: string, target: string, type: string}>  $extra
     */
    private function hierarchy(array $extra = []): CodeGraph
    {
        $edges = [];

        foreach (['CsvExporter', 'PdfExporter', 'HtmlExporter'] as $class) {
            $fqcn = "App\\Reports\\{$class}";

            $edges[] = ['source' => $fqcn, 'target' => self::INTERFACE_FQCN, 'type' => 'implements'];
            $edges[] = ['source' => $fqcn, 'target' => "{$fqcn}::export", 'type' => 'declares'];
            $edges[] = ['source' => self::INTERFACE_METHOD, 'target' => "{$fqcn}::export", 'type' => 'override'];
        }

        $edges[] = ['source' => self::INTERFACE_FQCN, 'target' => self::INTERFACE_METHOD, 'type' => 'declares'];

        return new CodeGraph([...$edges, ...$extra], hasUnparseableFiles: false);
    }

    /**
     * @param  list<array{depth: int, node: string, via: string, file?: string, line?: int}>  $hops
     * @return list<string>
     */
    private function nodes(array $hops): array
    {
        return array_values(array_column($hops, 'node'));
    }

    #[Test]
    public function a_seed_adjacent_override_still_reaches_every_implementor(): void
    {
        $reached = $this->nodes($this->hierarchy()->dependenciesOf([self::INTERFACE_METHOD]));

        $this->assertContains('App\Reports\CsvExporter::export', $reached);
        $this->assertContains('App\Reports\PdfExporter::export', $reached);
        $this->assertContains('App\Reports\HtmlExporter::export', $reached);
    }

    #[Test]
    public function a_seed_adjacent_override_still_climbs_to_the_declaring_ancestor(): void
    {
        $reached = $this->nodes($this->hierarchy()->callersOf(['App\Reports\CsvExporter::export']));

        // Upstream from a concrete override: the abstract call site is reached, and the walk keeps
        // going up rather than back down into the siblings.
        $this->assertContains(self::INTERFACE_METHOD, $reached);
        $this->assertNotContains('App\Reports\PdfExporter::export', $reached);
    }

    #[Test]
    public function a_call_edge_onto_the_interface_method_still_fans_out(): void
    {
        $graph = $this->hierarchy([
            ['source' => 'App\Http\Controllers\ReportController::show', 'target' => self::INTERFACE_METHOD, 'type' => 'action-to-service'],
        ]);

        $reached = $this->nodes($graph->dependenciesOf(['App\Http\Controllers\ReportController::show']));

        $this->assertContains('App\Reports\PdfExporter::export', $reached);
        $this->assertContains('App\Reports\HtmlExporter::export', $reached);
    }

    #[Test]
    public function a_structural_only_path_does_not_reach_a_sibling_implementor(): void
    {
        $reached = $this->nodes($this->hierarchy()->dependenciesOf(['App\Reports\CsvExporter']));

        // The class node's own member is a `declares` hop and stays reachable; the siblings behind
        // implements → declares → override do not.
        $this->assertContains('App\Reports\CsvExporter::export', $reached);
        $this->assertContains(self::INTERFACE_METHOD, $reached);
        $this->assertNotContains('App\Reports\PdfExporter::export', $reached);
        $this->assertNotContains('App\Reports\HtmlExporter::export', $reached);
    }

    #[Test]
    public function a_new_files_class_node_seed_does_not_list_its_siblings(): void
    {
        // Same shape as above, reached the way a brand-new implementor seeds: its class node is a
        // PRECISE seed, so only the gate — never the coarse-seed split — keeps the siblings out.
        $reach = $this->hierarchy()->reachedViaTypes(['App\Reports\CsvExporter']);

        $this->assertArrayHasKey(self::INTERFACE_METHOD, $reach);
        $this->assertArrayNotHasKey('App\Reports\PdfExporter::export', $reach);
    }

    #[Test]
    public function an_inherits_hop_out_of_a_structural_only_path_is_refused_too(): void
    {
        // The same cousin shape from the other side: instead of every class that OVERRIDES an
        // ancestor member, every class that INHERITS it. The changed member overrides the base, so
        // the base is one legal seed-adjacent hop up; from there its other subclasses are cousins.
        $graph = new CodeGraph([
            ['source' => 'App\Reports\BaseExporter::export', 'target' => 'App\Reports\CsvExporter::export', 'type' => 'override'],
            ['source' => 'App\Reports\PdfExporter::export', 'target' => 'App\Reports\BaseExporter::export', 'type' => 'inherits'],
            ['source' => 'App\Reports\HtmlExporter::export', 'target' => 'App\Reports\BaseExporter::export', 'type' => 'inherits'],
        ], hasUnparseableFiles: false);

        $reached = $this->nodes($graph->callersOf(['App\Reports\CsvExporter::export']));

        $this->assertContains('App\Reports\BaseExporter::export', $reached, 'the ancestor it overrides is seed-adjacent');
        $this->assertNotContains('App\Reports\PdfExporter::export', $reached);
        $this->assertNotContains('App\Reports\HtmlExporter::export', $reached);
    }

    #[Test]
    public function an_inherited_ancestor_is_still_reached_from_the_member_that_inherits_it(): void
    {
        // The other direction of the same edge: the body that runs for a changed member lives in the
        // ancestor, one hop downstream, and stays reachable.
        $graph = new CodeGraph([
            ['source' => 'App\Reports\CsvExporter::export', 'target' => 'App\Reports\BaseExporter::export', 'type' => 'inherits'],
        ], hasUnparseableFiles: false);

        $this->assertContains(
            'App\Reports\BaseExporter::export',
            $this->nodes($graph->dependenciesOf(['App\Reports\CsvExporter::export'])),
        );
    }

    #[Test]
    public function a_call_path_still_reaches_every_inheriting_descendant(): void
    {
        // The counterpart guard. Walking callers of a helper: the ancestor member calls it, and each
        // descendant that inherits that member is a real route by which the call arrives.
        $graph = new CodeGraph([
            ['source' => 'App\Reports\BaseExporter::export', 'target' => 'App\Services\Formatter::format', 'type' => 'action-to-service'],
            ['source' => 'App\Reports\CsvExporter::export', 'target' => 'App\Reports\BaseExporter::export', 'type' => 'inherits'],
            ['source' => 'App\Reports\PdfExporter::export', 'target' => 'App\Reports\BaseExporter::export', 'type' => 'inherits'],
        ], hasUnparseableFiles: false);

        $reached = $this->nodes($graph->callersOf(['App\Services\Formatter::format']));

        $this->assertContains('App\Reports\BaseExporter::export', $reached);
        $this->assertContains('App\Reports\CsvExporter::export', $reached);
        $this->assertContains('App\Reports\PdfExporter::export', $reached);
    }

    #[Test]
    public function a_three_level_hierarchy_still_reaches_the_grandchild(): void
    {
        // ClassHierarchyTracer emits an edge from EVERY app ancestor, so the root reaches the
        // grandchild directly even though the chained hop out of the middle class is gated.
        $graph = new CodeGraph([
            ['source' => 'App\Reports\BaseExporter::export', 'target' => 'App\Reports\CsvExporter::export', 'type' => 'override'],
            ['source' => 'App\Reports\BaseExporter::export', 'target' => 'App\Reports\TabbedCsvExporter::export', 'type' => 'override'],
            ['source' => 'App\Reports\CsvExporter::export', 'target' => 'App\Reports\TabbedCsvExporter::export', 'type' => 'override'],
        ], hasUnparseableFiles: false);

        $reached = $this->nodes($graph->dependenciesOf(['App\Reports\BaseExporter::export']));

        $this->assertContains('App\Reports\CsvExporter::export', $reached);
        $this->assertContains('App\Reports\TabbedCsvExporter::export', $reached);
    }

    #[Test]
    public function a_node_first_reached_structurally_still_fans_out_when_a_call_path_arrives(): void
    {
        // The structural path reaches the interface method at depth 2; the call-carrying path only at
        // depth 4. Without the re-enqueue the node would keep its gated flag and the fan-out would be
        // lost — a silent under-reach that would also depend on adjacency order.
        $graph = $this->hierarchy([
            ['source' => 'App\Reports\CsvExporter', 'target' => 'App\Services\ExportRunner::run', 'type' => 'action-to-service'],
            ['source' => 'App\Services\ExportRunner::run', 'target' => 'App\Services\ExportDispatcher::dispatch', 'type' => 'action-to-service'],
            ['source' => 'App\Services\ExportDispatcher::dispatch', 'target' => self::INTERFACE_METHOD, 'type' => 'action-to-service'],
        ]);

        $reached = $this->nodes($graph->dependenciesOf(['App\Reports\CsvExporter']));

        $this->assertContains('App\Reports\PdfExporter::export', $reached);
        $this->assertContains('App\Reports\HtmlExporter::export', $reached);

        // The drawn tree stays a BFS tree: the re-reached node is emitted once, on its first arrival.
        $edges = $graph->dependencyEdgesOf(['App\Reports\CsvExporter']);
        $interfaceRows = array_values(array_filter($edges, static fn (array $e): bool => $e['target'] === self::INTERFACE_METHOD));

        $this->assertCount(1, $interfaceRows, 'a re-enqueued node is never emitted twice');
    }

    #[Test]
    public function an_upgraded_node_keeps_the_walks_shortest_path_contracts(): void
    {
        // The re-enqueue lifts the gate for REACH only. Parent pointers and edge rows still describe
        // the node's first arrival, so a chain stays shortest and inside the depth limit, and the edge
        // list stays in non-decreasing depth order. A drawn chain through such a node can therefore
        // name the structural route — documented on `bfs()`, and the cheaper side of the trade.
        $graph = new CodeGraph([
            ['source' => self::INTERFACE_METHOD, 'target' => 'App\Reports\CsvExporter::export', 'type' => 'override'],
            ['source' => 'App\Contracts\Renderable::export', 'target' => self::INTERFACE_METHOD, 'type' => 'override'],
            ['source' => 'App\Services\ExportRunner::run', 'target' => 'App\Reports\CsvExporter::export', 'type' => 'action-to-service'],
            ['source' => self::INTERFACE_METHOD, 'target' => 'App\Services\ExportRunner::run', 'type' => 'action-to-service'],
        ], hasUnparseableFiles: false);

        $seed = ['App\Reports\CsvExporter::export'];

        // Reach: the upgrade makes the second override hop legal, so the ancestor is reported.
        $this->assertContains('App\Contracts\Renderable::export', $this->nodes($graph->callersOf($seed, maxDepth: 3)));

        // Contracts: every chain is at most maxDepth hops, and the edge rows never step backwards.
        $path = $graph->callerPathsTo($seed, ['App\Contracts\Renderable::export'], maxDepth: 3)['App\Contracts\Renderable::export'];
        $this->assertLessThanOrEqual(4, count($path));

        $depths = array_column($graph->callerEdgesOf($seed, maxDepth: 3), 'depth');
        $sorted = $depths;
        sort($sorted);
        $this->assertSame($sorted, $depths);
    }

    #[Test]
    public function a_call_edge_onto_a_class_node_followed_by_declares_still_fans_out(): void
    {
        // The guard against re-narrowing the rule to the arriving edge: the last hop before the
        // override is `declares`, but the path carries a call, so this is real dispatch.
        $graph = $this->hierarchy([
            ['source' => 'App\Http\Controllers\ReportController::show', 'target' => self::INTERFACE_FQCN, 'type' => 'action-to-service'],
        ]);

        $reached = $this->nodes($graph->dependenciesOf(['App\Http\Controllers\ReportController::show']));

        $this->assertContains(self::INTERFACE_METHOD, $reached);
        $this->assertContains('App\Reports\PdfExporter::export', $reached);
    }

    #[Test]
    public function impact_on_an_abstract_method_still_reports_every_override(): void
    {
        $analyzer = new ImpactAnalyzer($this->hierarchy([
            ['source' => 'route::GET::/exports', 'target' => 'App\Http\Controllers\ReportController::show', 'type' => 'route-to-controller'],
            ['source' => 'App\Http\Controllers\ReportController::show', 'target' => self::INTERFACE_METHOD, 'type' => 'action-to-service'],
        ]));

        $result = $analyzer->impact(self::INTERFACE_METHOD);

        $this->assertContains('App\Reports\PdfExporter::export', $this->nodes($result['dependencies']));
        $this->assertSame(['route::GET::/exports'], $result['entryPoints']);
    }

    #[Test]
    public function the_gate_never_removes_a_node_reachable_by_a_legal_path(): void
    {
        // PdfExporter::export is unreachable structurally, but a direct call edge reaches it. The gate
        // refuses edges, never nodes.
        $graph = $this->hierarchy([
            ['source' => 'App\Reports\CsvExporter::export', 'target' => 'App\Reports\PdfExporter::export', 'type' => 'static-call'],
        ]);

        $this->assertContains('App\Reports\PdfExporter::export', $this->nodes($graph->dependenciesOf(['App\Reports\CsvExporter'])));
    }
}
