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
        ], hasUnresolvedDispatches: true, nodeMetadata: [
            'route::GET::/r' => ['file' => 'routes/web.php', 'line' => 3, 'uri' => '/r'],
        ]);

        $revived = CodeGraph::fromArray($graph->toArray());

        $this->assertSame($graph->toArray(), $revived->toArray());
        $this->assertSame($graph->callersOf(['App\Services\S::run']), $revived->callersOf(['App\Services\S::run']));
        $this->assertSame($graph->dependenciesOf(['route::GET::/r']), $revived->dependenciesOf(['route::GET::/r']));
        $this->assertTrue($revived->hasUnresolvedDispatches());
        $this->assertSame(['file' => 'routes/web.php', 'line' => 3], $revived->locationOf('route::GET::/r'));
    }

    #[Test]
    public function an_array_form_without_metadata_revives_as_an_unannotated_graph(): void
    {
        $revived = CodeGraph::fromArray([
            'edges' => [['source' => 'A', 'target' => 'B', 'type' => 'call']],
            'hasUnresolvedDispatches' => false,
        ]);

        $this->assertNull($revived->locationOf('A'));
    }

    #[Test]
    public function location_and_security_read_from_the_node_metadata(): void
    {
        $graph = new CodeGraph(
            [['source' => 'route::POST::/checkout', 'target' => 'App\Services\S::run', 'type' => 'route-to-controller']],
            nodeMetadata: [
                'route::POST::/checkout' => [
                    'file' => 'routes/web.php',
                    'line' => 21,
                    'security' => ['exposure' => 'public', 'riskLevel' => 'high', 'issues' => []],
                ],
                'App\Services\S::run' => ['file' => 'app/Services/S.php'],
            ],
        );

        $this->assertSame(['file' => 'routes/web.php', 'line' => 21], $graph->locationOf('route::POST::/checkout'));
        // Sparse: no line means no line key, so JSON consumers never see nulls.
        $this->assertSame(['file' => 'app/Services/S.php'], $graph->locationOf('App\Services\S::run'));
        $this->assertSame(['exposure' => 'public', 'riskLevel' => 'high', 'issues' => []], $graph->securityOf('route::POST::/checkout'));
        $this->assertNull($graph->locationOf('unknown'));
        $this->assertNull($graph->securityOf('App\Services\S::run'));
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

    #[Test]
    public function nodes_containing_matches_only_at_identifier_boundaries(): void
    {
        $graph = new CodeGraph([
            ['source' => 'model::App\Models\Video', 'target' => 'App\Models\VideoContainer', 'type' => 'model-relationship'],
            ['source' => 'App\Models\SuperVideo', 'target' => 'App\Models\VideoContainer', 'type' => 'model-relationship'],
        ]);

        // "Video" must match only the exact identifier, not as a prefix or suffix of a sibling name.
        $this->assertSame(['model::App\Models\Video'], $graph->nodesContaining('Video'));

        // A fully-qualified needle behaves the same way.
        $this->assertSame(['model::App\Models\Video'], $graph->nodesContaining('App\Models\Video'));
    }

    #[Test]
    public function nodes_containing_is_case_insensitive(): void
    {
        $graph = new CodeGraph([
            ['source' => 'model::App\Models\Video', 'target' => 'App\Models\VideoContainer', 'type' => 'model-relationship'],
        ]);

        $this->assertSame(['model::App\Models\Video'], $graph->nodesContaining('video'));
    }

    #[Test]
    public function nodes_containing_matches_member_needles_on_either_side_of_the_double_colon(): void
    {
        $graph = new CodeGraph([
            ['source' => 'App\Models\Video::query', 'target' => 'App\Services\S::run', 'type' => 'action-to-service'],
        ]);

        // The full member needle matches, and so does the bare method name — the "::" left of it
        // is itself a boundary character.
        $this->assertSame(['App\Models\Video::query'], $graph->nodesContaining('Video::query'));
        $this->assertSame(['App\Models\Video::query'], $graph->nodesContaining('query'));
    }

    #[Test]
    public function nodes_containing_returns_nothing_for_an_empty_needle(): void
    {
        $graph = new CodeGraph([
            ['source' => 'App\Models\Video::query', 'target' => 'App\Services\S::run', 'type' => 'action-to-service'],
        ]);

        $this->assertSame([], $graph->nodesContaining(''));
    }

    #[Test]
    public function nodes_containing_returns_nothing_for_a_needle_with_no_identifier_characters(): void
    {
        // "::" never sits at an identifier boundary in real node ids — it's always immediately
        // preceded by the class/verb identifier it separates from the method/path — so it matches
        // nothing even though the substring is present in every member node. Pinning that as the
        // current, tokenizer-free behavior; a needle that yields no tokens must keep falling back
        // to the full regex scan.
        $graph = new CodeGraph([
            ['source' => 'App\Models\Video::query', 'target' => 'App\Services\S::run', 'type' => 'action-to-service'],
            ['source' => 'route::GET::/r', 'target' => 'App\Http\Controllers\C::index', 'type' => 'route-to-controller'],
        ]);

        $this->assertSame([], $graph->nodesContaining('::'));
    }
}
