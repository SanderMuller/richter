<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Analysis\RadialLayout;
use SanderMuller\Richter\Tests\TestCase;

/**
 * @phpstan-import-type Layout from RadialLayout
 * @phpstan-import-type PositionedNode from RadialLayout
 */
final class RadialLayoutTest extends TestCase
{
    /**
     * @param  list<PositionedNode>  $nodes
     * @return list<string>
     */
    private function ids(array $nodes): array
    {
        return array_values(array_map(static fn (array $node): string => $node['id'], $nodes));
    }

    /**
     * @param  Layout  $layout
     * @return PositionedNode
     */
    private function node(array $layout, string $id): array
    {
        foreach ($layout['nodes'] as $node) {
            if ($node['id'] === $id) {
                return $node;
            }
        }

        self::fail("Node {$id} was not laid out.");
    }

    #[Test]
    public function seeds_sit_at_the_centre_and_depth_is_the_minimum_across_walks(): void
    {
        // X is reached at depth 3 by the caller walk and depth 1 by the dependency walk. The two
        // walks keep independent seen-sets (see CodeGraph::callerEdgesOf), so both entries are
        // legitimate — the diagram must place X on the nearer ring, not the further one.
        $layout = RadialLayout::compute(
            edges: [
                ['source' => 'A', 'target' => 'S', 'via' => 'call', 'depth' => 1],
                ['source' => 'B', 'target' => 'A', 'via' => 'call', 'depth' => 2],
                ['source' => 'X', 'target' => 'B', 'via' => 'call', 'depth' => 3],
                ['source' => 'S', 'target' => 'X', 'via' => 'call', 'depth' => 1],
            ],
            reach: [
                'A' => ['call' => true],
                'B' => ['call' => true],
                'X' => ['call' => true],
            ],
            seeds: ['S'],
        );

        $this->assertSame(0, $this->node($layout, 'S')['depth']);
        $this->assertSame('seed', $this->node($layout, 'S')['kind']);
        $this->assertSame(1, $this->node($layout, 'X')['depth']);
        $this->assertSame(1, $this->node($layout, 'A')['depth']);
        $this->assertSame(2, $this->node($layout, 'B')['depth']);
    }

    #[Test]
    public function a_node_reached_only_by_association_edges_is_not_counted(): void
    {
        $layout = RadialLayout::compute(
            edges: [
                ['source' => 'S', 'target' => 'Related', 'via' => 'model-relationship', 'depth' => 1],
                ['source' => 'S', 'target' => 'Called', 'via' => 'action-to-service', 'depth' => 1],
                ['source' => 'S', 'target' => 'Both', 'via' => 'model-relationship', 'depth' => 1],
            ],
            reach: [
                'Related' => ['model-relationship' => true],
                'Called' => ['action-to-service' => true],
                // Any behavioural edge makes a node count, whichever the walk recorded first.
                'Both' => ['model-relationship' => true, 'action-to-service' => true],
            ],
            seeds: ['S'],
        );

        $this->assertSame('association', $this->node($layout, 'Related')['kind']);
        $this->assertSame('impacted', $this->node($layout, 'Called')['kind']);
        $this->assertSame('impacted', $this->node($layout, 'Both')['kind']);
        // Seeds are drawn but are not "impacted" — impacted counts what the change REACHES.
        $this->assertSame(2, $layout['impactedCount']);
    }

    #[Test]
    public function uses_trait_and_declares_are_association_edges_too(): void
    {
        // The excluded set is three types, not just model-relationship: a hub trait would otherwise
        // saturate the count for any one-method change.
        $layout = RadialLayout::compute(
            edges: [
                ['source' => 'S', 'target' => 'User', 'via' => 'uses-trait', 'depth' => 1],
                ['source' => 'S', 'target' => 'Declared', 'via' => 'declares', 'depth' => 1],
            ],
            reach: ['User' => ['uses-trait' => true], 'Declared' => ['declares' => true]],
            seeds: ['S'],
        );

        $this->assertSame('association', $this->node($layout, 'User')['kind']);
        $this->assertSame('association', $this->node($layout, 'Declared')['kind']);
        $this->assertSame(0, $layout['impactedCount']);
    }

    #[Test]
    public function nodes_in_a_ring_are_evenly_spaced_and_deterministically_ordered(): void
    {
        $edges = [
            ['source' => 'S', 'target' => 'c', 'via' => 'call', 'depth' => 1],
            ['source' => 'S', 'target' => 'a', 'via' => 'call', 'depth' => 1],
            ['source' => 'S', 'target' => 'b', 'via' => 'call', 'depth' => 1],
        ];
        $reach = ['a' => ['call' => true], 'b' => ['call' => true], 'c' => ['call' => true]];

        $first = RadialLayout::compute($edges, $reach, ['S']);
        $second = RadialLayout::compute($edges, $reach, ['S']);

        $this->assertSame($first, $second);
        // Ring membership is sorted by node id, so the SVG is snapshot-stable run to run.
        $this->assertSame(['S', 'a', 'b', 'c'], $this->ids($first['nodes']));
    }

    #[Test]
    public function several_seeds_do_not_stack_on_the_same_point(): void
    {
        // Depth 0 is a single point, so more than one seed would render as one visible dot with the
        // rest hidden underneath it.
        $layout = RadialLayout::compute(
            edges: [
                ['source' => 'A', 'target' => 'S1', 'via' => 'call', 'depth' => 1],
                ['source' => 'A', 'target' => 'S2', 'via' => 'call', 'depth' => 1],
            ],
            reach: ['A' => ['call' => true]],
            seeds: ['S1', 'S2', 'S3'],
        );

        $points = array_map(
            static fn (array $node): string => $node['x'] . ',' . $node['y'],
            array_filter($layout['nodes'], static fn (array $node): bool => $node['kind'] === 'seed'),
        );

        $this->assertCount(3, $points);
        $this->assertCount(count($points), array_unique($points));
    }

    #[Test]
    public function a_lone_seed_sits_dead_centre(): void
    {
        $layout = RadialLayout::compute(
            edges: [['source' => 'A', 'target' => 'S', 'via' => 'call', 'depth' => 1]],
            reach: ['A' => ['call' => true]],
            seeds: ['S'],
        );

        $seed = $this->node($layout, 'S');

        $this->assertEqualsWithDelta($layout['centre'], $seed['x'], 0.001);
        $this->assertEqualsWithDelta($layout['centre'], $seed['y'], 0.001);
    }

    #[Test]
    public function the_seed_disc_stays_inside_the_canvas(): void
    {
        // A seeds-only graph has size 120 and centre 60; the disc must sit inside the margin or
        // seeds paint on the viewBox edge. Pins SEED_RADIUS < MARGIN so changing either fails here.
        $layout = RadialLayout::compute(
            edges: [],
            reach: [],
            seeds: ['S1', 'S2', 'S3'],
        );

        foreach ($layout['nodes'] as $node) {
            $this->assertGreaterThanOrEqual(0, $node['x']);
            $this->assertLessThanOrEqual($layout['size'], $node['x']);
            $this->assertGreaterThanOrEqual(0, $node['y']);
            $this->assertLessThanOrEqual($layout['size'], $node['y']);
        }
    }

    #[Test]
    public function the_seed_disc_sits_inside_the_first_ring(): void
    {
        // Seeds at their disc radius must not reach the depth-1 ring, or the centre cluster and the
        // first ring visually merge.
        $twoSeeds = RadialLayout::compute(
            edges: [['source' => 'A', 'target' => 'S1', 'via' => 'call', 'depth' => 1]],
            reach: ['A' => ['call' => true]],
            seeds: ['S1', 'S2'],
        );

        $ringOne = $this->node($twoSeeds, 'A');
        $seed = $this->node($twoSeeds, 'S1');
        $centre = $twoSeeds['centre'];

        $seedRadius = hypot($seed['x'] - $centre, $seed['y'] - $centre);
        $ringRadius = hypot($ringOne['x'] - $centre, $ringOne['y'] - $centre);

        $this->assertLessThan($ringRadius, $seedRadius);
    }

    #[Test]
    public function rings_are_one_per_occupied_depth_and_exclude_the_centre(): void
    {
        $layout = RadialLayout::compute(
            edges: [
                ['source' => 'S', 'target' => 'a', 'via' => 'call', 'depth' => 1],
                ['source' => 'a', 'target' => 'b', 'via' => 'call', 'depth' => 2],
                ['source' => 'S', 'target' => 'c', 'via' => 'call', 'depth' => 1],
            ],
            reach: ['a' => ['call' => true], 'b' => ['call' => true], 'c' => ['call' => true]],
            seeds: ['S'],
        );

        // Two occupied depths (1 and 2); depth 0 is the seed, which is not "a step from the change".
        $this->assertSame([1, 2], array_map(static fn (array $ring): int => $ring['depth'], $layout['rings']));
    }

    #[Test]
    public function the_layout_is_capped_and_reports_the_hidden_count(): void
    {
        $edges = [];
        $reach = [];

        // 20 nodes on the near ring, then MAX_NODES more on a far ring: the far ones are dropped
        // first, because shallow means closer to the change.
        for ($i = 0; $i < 20; ++$i) {
            $edges[] = ['source' => 'S', 'target' => "near{$i}", 'via' => 'call', 'depth' => 1];
            $reach["near{$i}"] = ['call' => true];
        }

        for ($i = 0; $i < RadialLayout::MAX_NODES; ++$i) {
            $edges[] = ['source' => 'near0', 'target' => "far{$i}", 'via' => 'call', 'depth' => 2];
            $reach["far{$i}"] = ['call' => true];
        }

        $layout = RadialLayout::compute($edges, $reach, ['S']);

        $this->assertCount(RadialLayout::MAX_NODES, $layout['nodes']);
        $this->assertSame(21, $layout['hiddenCount']);
        // Every near node survived; the drop came out of the far ring.
        $this->assertContains('near19', $this->ids($layout['nodes']));
        // An edge whose far endpoint was dropped is not drawn as a dangling stub.
        $this->assertCount(RadialLayout::MAX_NODES - 1, $layout['edges']);
    }

    #[Test]
    public function the_impacted_count_reports_the_full_set_even_when_the_drawing_is_capped(): void
    {
        // The cap hides nodes from the PICTURE, never from the count — the report says so in words.
        $edges = [];
        $reach = [];

        for ($i = 0; $i < RadialLayout::MAX_NODES + 10; ++$i) {
            $edges[] = ['source' => 'S', 'target' => "n{$i}", 'via' => 'call', 'depth' => 1];
            $reach["n{$i}"] = ['call' => true];
        }

        $layout = RadialLayout::compute($edges, $reach, ['S']);

        $this->assertSame(RadialLayout::MAX_NODES + 10, $layout['impactedCount']);
        $this->assertGreaterThan(0, $layout['hiddenCount']);
    }

    #[Test]
    public function an_empty_diff_produces_an_empty_layout(): void
    {
        $layout = RadialLayout::compute([], [], []);

        $this->assertSame([], $layout['nodes']);
        $this->assertSame([], $layout['edges']);
        $this->assertSame(0, $layout['hiddenCount']);
        $this->assertSame(0, $layout['impactedCount']);
    }
}
