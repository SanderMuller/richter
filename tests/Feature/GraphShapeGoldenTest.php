<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Graph\CodeGraph;
use SanderMuller\Richter\Graph\CodeGraphBuilder;
use SanderMuller\Richter\Tests\TestCase;

/**
 * The whole edge set of the fixture project's graph, pinned to a committed file.
 *
 * The per-lane tests each assert the edges their own tracer draws. None of them can see the merged
 * result: an edge a lane stops drawing, one it starts drawing, or one whose type changes still leaves
 * every per-lane test green so long as that lane's own fixture is unaffected. This is the only test
 * that fails on the set as a whole.
 *
 * Order is NOT what it guards. {@see CodeGraph} sorts every edge set canonically in its constructor —
 * deliberately, so a cache-revived graph tie-breaks its walks exactly as a fresh build does — which is
 * also what makes a committed expectation file usable here: the rendering is stable for a given set
 * regardless of the order the lanes emitted in.
 *
 * Expected to change when a lane's behaviour intentionally changes. Regenerate with
 * `RICHTER_UPDATE_GRAPH_GOLDEN=1 vendor/bin/phpunit --filter graph_shape` and read the diff — that
 * diff is the point, so review it rather than accepting it.
 */
final class GraphShapeGoldenTest extends TestCase
{
    private const string GOLDEN = __DIR__ . '/../Fixtures/graph-shape.golden.txt';

    #[Test]
    public function the_graph_shape_is_unchanged(): void
    {
        $actual = $this->render(new CodeGraphBuilder()->build(self::fixtureProjectPath()));

        if (getenv('RICHTER_UPDATE_GRAPH_GOLDEN') !== false) {
            file_put_contents(self::GOLDEN, $actual);
            self::markTestSkipped('golden file regenerated — review the diff before committing it');
        }

        $this->assertFileExists(self::GOLDEN, 'run with RICHTER_UPDATE_GRAPH_GOLDEN=1 to create it');
        $this->assertSame(file_get_contents(self::GOLDEN), $actual);
    }

    private function render(CodeGraph $graph): string
    {
        $lines = array_map(
            static fn (array $edge): string => "{$edge['source']}\t{$edge['type']}\t{$edge['target']}",
            $graph->toArray()['edges'],
        );

        return implode("\n", $lines) . "\n";
    }
}
