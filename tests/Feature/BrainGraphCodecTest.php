<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Feature;

use LaraMint\LaravelBrain\Analysis\ProjectAnalyzer;
use LaraMint\LaravelBrain\Graph\Graph;
use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Graph\BrainGraphCodec;
use SanderMuller\Richter\Tests\TestCase;

/**
 * The merge base an incremental rebuild is built onto. If Brain's graph does not survive richter's
 * cache intact, every scoped build after it merges into something subtly wrong — so the round-trip is
 * asserted on a real analysed project, not on a hand-built graph that could not show a lossy field.
 */
final class BrainGraphCodecTest extends TestCase
{
    #[Test]
    public function a_real_brain_graph_round_trips_byte_identically(): void
    {
        $original = new ProjectAnalyzer()->analyze(
            self::fixtureProjectPath(),
            static fn (string $event, array $data): null => null,
        )->fullGraph;

        $revived = BrainGraphCodec::fromArray(BrainGraphCodec::toArray($original));

        $this->assertInstanceOf(Graph::class, $revived);
        $this->assertSame($original->toJson(), $revived->toJson());
        $this->assertSame($original->nodeCount(), $revived->nodeCount());
        $this->assertSame($original->edgeCount(), $revived->edgeCount());
    }

    #[Test]
    public function the_file_provenance_the_merge_keys_on_survives(): void
    {
        // `IncrementalMerge` substitutes a changed file's nodes by matching `data['file']`. A graph
        // that round-trips its ids but loses that field would merge nothing and silently keep every
        // stale node — the failure this assertion exists to make impossible.
        $original = new ProjectAnalyzer()->analyze(
            self::fixtureProjectPath(),
            static fn (string $event, array $data): null => null,
        )->fullGraph;
        $revived = BrainGraphCodec::fromArray(BrainGraphCodec::toArray($original));

        $this->assertInstanceOf(Graph::class, $revived);

        $provenance = static function (Graph $graph): array {
            $files = [];

            foreach ($graph->nodes() as $node) {
                if (isset($node->data['file'])) {
                    $files[$node->id] = $node->data['file'];
                }
            }

            return $files;
        };

        $before = $provenance($original);

        $this->assertNotSame([], $before, 'the fixture graph must carry provenance for this to prove anything');
        $this->assertSame($before, $provenance($revived));
    }

    #[Test]
    public function a_truncated_payload_yields_null_not_a_partial_graph(): void
    {
        $payload = BrainGraphCodec::toArray(new ProjectAnalyzer()->analyze(
            self::fixtureProjectPath(),
            static fn (string $event, array $data): null => null,
        )->fullGraph);

        $this->assertNotSame([], $payload['nodes']);

        foreach ([
            'no meta' => ['nodes' => $payload['nodes'], 'edges' => $payload['edges']],
            'no nodes' => ['meta' => $payload['meta'], 'edges' => $payload['edges']],
            'no edges' => ['meta' => $payload['meta'], 'nodes' => $payload['nodes']],
            'not an array' => 'graph',
            'node missing its id' => ['meta' => $payload['meta'], 'edges' => [], 'nodes' => [['type' => 't', 'label' => 'l', 'data' => []]]],
            'edge with a non-string target' => ['meta' => $payload['meta'], 'nodes' => [], 'edges' => [['id' => 'e', 'source' => 's', 'target' => 42, 'label' => 'l', 'type' => 't']]],
        ] as $case => $broken) {
            $this->assertNotInstanceOf(Graph::class, BrainGraphCodec::fromArray($broken), "{$case} must discard the whole graph");
        }
    }
}
