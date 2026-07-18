<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Graph\CodeGraph;
use SanderMuller\Richter\Tests\TestCase;

final class CodeGraphWalkTest extends TestCase
{
    #[Test]
    public function reached_via_types_records_both_directions_and_excludes_the_seed(): void
    {
        $graph = new CodeGraph([
            ['source' => 'A', 'target' => 'B', 'type' => 'call'],      // downstream of A
            ['source' => 'C', 'target' => 'A', 'type' => 'relation'],  // upstream of A
        ]);

        $reach = $graph->reachedViaTypes(['A']);

        $this->assertSame(['call' => true], $reach['B']);
        $this->assertSame(['relation' => true], $reach['C']);
        $this->assertArrayNotHasKey('A', $reach);
    }

    #[Test]
    public function reached_via_types_merges_edge_types_for_a_node_reachable_two_ways(): void
    {
        // X is reachable from A by two parallel edges of different types — both must be recorded,
        // regardless of which the BFS visits first.
        $graph = new CodeGraph([
            ['source' => 'A', 'target' => 'X', 'type' => 'model-relationship'],
            ['source' => 'A', 'target' => 'X', 'type' => 'action-to-service'],
        ]);

        $this->assertSame(['action-to-service' => true, 'model-relationship' => true], $graph->reachedViaTypes(['A'])['X']);
    }

    #[Test]
    public function a_graph_round_trips_through_its_array_form_unchanged(): void
    {
        $graph = new CodeGraph([
            ['source' => 'route::GET::/r', 'target' => 'App\Http\Controllers\C::index', 'type' => 'route-to-controller'],
            ['source' => 'App\Http\Controllers\C::index', 'target' => 'App\Services\S::run', 'type' => 'action-to-service'],
        ], hasUnresolvedDispatches: true);

        $revived = CodeGraph::fromArray($graph->toArray());

        $this->assertSame($graph->toArray(), $revived->toArray());
        $this->assertSame($graph->callersOf(['App\Services\S::run']), $revived->callersOf(['App\Services\S::run']));
        $this->assertSame($graph->dependenciesOf(['route::GET::/r']), $revived->dependenciesOf(['route::GET::/r']));
        $this->assertTrue($revived->hasUnresolvedDispatches());
    }

    #[Test]
    public function a_revived_graph_walks_identically_to_a_fresh_one_regardless_of_edge_order(): void
    {
        // toArray() regroups edges by source while a fresh build receives them build-ordered —
        // interleaved so that regrouping would flip the relative order of the two callers of S.
        $graph = new CodeGraph([
            ['source' => 'route::GET::/a', 'target' => 'App\X::run', 'type' => 'route-to-controller'],
            ['source' => 'route::GET::/b', 'target' => 'App\S::run', 'type' => 'route-to-controller'],
            ['source' => 'route::GET::/a', 'target' => 'App\S::run', 'type' => 'route-to-controller'],
        ]);

        $revived = CodeGraph::fromArray($graph->toArray());

        $this->assertSame($graph->callersOf(['App\S::run']), $revived->callersOf(['App\S::run']));
        $this->assertSame(
            $graph->callerPathsTo(['App\S::run'], ['route::GET::/a', 'route::GET::/b']),
            $revived->callerPathsTo(['App\S::run'], ['route::GET::/a', 'route::GET::/b']),
        );
    }

    #[Test]
    public function a_caller_path_runs_from_the_entry_point_down_to_the_seed_with_edge_types(): void
    {
        // route → controller action → service: walking up from the changed service must explain the
        // chain back down, each hop's via naming the edge to the next hop and the seed carrying ''.
        $graph = new CodeGraph([
            ['source' => 'route::GET::/r', 'target' => 'App\Http\Controllers\C::index', 'type' => 'route-to-controller'],
            ['source' => 'App\Http\Controllers\C::index', 'target' => 'App\Services\S::run', 'type' => 'action-to-service'],
        ]);

        $paths = $graph->callerPathsTo(['App\Services\S::run'], ['route::GET::/r']);

        $this->assertSame([
            ['node' => 'route::GET::/r', 'via' => 'route-to-controller'],
            ['node' => 'App\Http\Controllers\C::index', 'via' => 'action-to-service'],
            ['node' => 'App\Services\S::run', 'via' => ''],
        ], $paths['route::GET::/r']);
    }

    #[Test]
    public function the_shorter_of_two_caller_chains_wins(): void
    {
        // The route reaches the seed both directly and via an intermediate — the reported chain must
        // be the two-hop direct one, not the three-hop detour.
        $graph = new CodeGraph([
            ['source' => 'route::GET::/r', 'target' => 'App\Services\S::run', 'type' => 'direct'],
            ['source' => 'route::GET::/r', 'target' => 'App\Support\M::mid', 'type' => 'call'],
            ['source' => 'App\Support\M::mid', 'target' => 'App\Services\S::run', 'type' => 'call'],
        ]);

        $paths = $graph->callerPathsTo(['App\Services\S::run'], ['route::GET::/r']);

        $this->assertCount(2, $paths['route::GET::/r']);
        $this->assertSame('direct', $paths['route::GET::/r'][0]['via']);
    }

    #[Test]
    public function an_unreached_target_is_absent_from_the_caller_paths(): void
    {
        $graph = new CodeGraph([
            ['source' => 'route::GET::/r', 'target' => 'App\Services\S::run', 'type' => 'direct'],
        ]);

        $paths = $graph->callerPathsTo(['App\Services\S::run'], ['route::GET::/unrelated']);

        $this->assertSame([], $paths);
    }

    #[Test]
    public function a_target_that_is_itself_a_seed_yields_a_single_hop_path(): void
    {
        $graph = new CodeGraph([
            ['source' => 'A', 'target' => 'B', 'type' => 'call'],
        ]);

        $paths = $graph->callerPathsTo(['A'], ['A']);

        $this->assertSame([['node' => 'A', 'via' => '']], $paths['A']);
    }

    #[Test]
    public function caller_paths_terminate_on_a_cyclic_graph(): void
    {
        // A <-> B cycle plus an entry above it — reconstruction must terminate at the seed, not loop.
        $graph = new CodeGraph([
            ['source' => 'route::GET::/r', 'target' => 'App\Services\A::run', 'type' => 'route-to-controller'],
            ['source' => 'App\Services\A::run', 'target' => 'App\Services\B::run', 'type' => 'call'],
            ['source' => 'App\Services\B::run', 'target' => 'App\Services\A::run', 'type' => 'call'],
        ]);

        $paths = $graph->callerPathsTo(['App\Services\B::run'], ['route::GET::/r']);

        $this->assertSame([
            ['node' => 'route::GET::/r', 'via' => 'route-to-controller'],
            ['node' => 'App\Services\A::run', 'via' => 'call'],
            ['node' => 'App\Services\B::run', 'via' => ''],
        ], $paths['route::GET::/r']);
    }
}
