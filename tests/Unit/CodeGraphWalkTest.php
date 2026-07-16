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

        $this->assertSame(['model-relationship' => true, 'action-to-service' => true], $graph->reachedViaTypes(['A'])['X']);
    }
}
