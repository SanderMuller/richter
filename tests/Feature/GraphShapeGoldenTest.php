<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Graph\CodeGraph;
use SanderMuller\Richter\Graph\CodeGraphBuilder;
use SanderMuller\Richter\Tests\TestCase;

/**
 * The whole edge set of the fixture project's graph, in build order, pinned to a committed file.
 *
 * The per-lane tests each assert the edges their own tracer draws; none of them can see a change in
 * what the lanes produce *together*, or in the order the merged set arrives in. That order is load
 * bearing — {@see CodeGraph} preserves insertion order, so a refactor that
 * kept every edge but emitted them differently would change `--json` output and every walk's tie-breaks
 * without failing a single existing test.
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

    #[Test]
    public function the_golden_comparison_is_order_sensitive(): void
    {
        // Without this, a golden test that only compared edge *sets* would pass through exactly the
        // reordering the refactor it guards is most likely to cause — so the gate would read green
        // while failing at its one job.
        $lines = explode("\n", trim((string) file_get_contents(self::GOLDEN)));
        $this->assertGreaterThan(2, count($lines));

        [$lines[0], $lines[1]] = [$lines[1], $lines[0]];

        $this->assertNotSame(file_get_contents(self::GOLDEN), implode("\n", $lines) . "\n");
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
