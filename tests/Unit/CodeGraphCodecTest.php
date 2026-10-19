<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use Iterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Graph\CodeGraph;
use SanderMuller\Richter\Graph\CodeGraphCodec;
use SanderMuller\Richter\Tests\TestCase;

/**
 * Every key of a stored graph is required and shape-checked, and one bad key refuses the whole entry.
 * A default on the way in is a fact the report states about a project nobody checked.
 */
final class CodeGraphCodecTest extends TestCase
{
    #[Test]
    public function a_graph_survives_the_round_trip(): void
    {
        $graph = new CodeGraph(
            [['source' => 'route::GET::/posts', 'target' => 'App\Http\Controllers\PostController::index', 'type' => 'route-to-controller']],
            hasUnparseableFiles: true,
            unresolvedDispatchSites: [['file' => 'app/Services/Importer.php', 'line' => 12, 'dispatcher' => 'App\Services\Importer::run']],
            nodeMetadata: ['route::GET::/posts' => ['file' => 'routes/web.php', 'line' => 3]],
        );

        $stored = json_decode(json_encode($graph->toArray(), JSON_THROW_ON_ERROR), associative: true);
        $this->assertIsArray($stored);

        $revived = CodeGraphCodec::decode($stored, 'graph.json')->graph;

        $this->assertInstanceOf(CodeGraph::class, $revived);
        $this->assertSame($graph->toArray(), $revived->toArray());
    }

    /** @return Iterator<string, array{string, mixed, string}> */
    public static function untrustedKeys(): Iterator
    {
        yield 'edges absent' => ['edges', null, 'edges-rejected'];
        yield 'an edge without a type' => ['edges', [['source' => 'a', 'target' => 'b']], 'edges-rejected'];
        yield 'the unparseable flag absent' => ['hasUnparseableFiles', null, 'unparseable-flag-rejected'];
        yield 'the unparseable flag as a string' => ['hasUnparseableFiles', '1', 'unparseable-flag-rejected'];
        yield 'the unparseable flag as an integer' => ['hasUnparseableFiles', 0, 'unparseable-flag-rejected'];
        yield 'dispatch sites absent' => ['unresolvedDispatchSites', null, 'dispatch-sites-rejected'];
        yield 'a dispatch site with a string line' => ['unresolvedDispatchSites', [['file' => 'app/A.php', 'line' => 'twelve', 'dispatcher' => 'App\A::run']], 'dispatch-sites-rejected'];
        yield 'dispatch sites keyed as a map' => ['unresolvedDispatchSites', ['first' => ['file' => 'app/A.php', 'line' => 1, 'dispatcher' => 'App\A::run']], 'dispatch-sites-rejected'];
        yield 'node metadata absent' => ['nodeMetadata', null, 'metadata-rejected'];
        yield 'node metadata as a string' => ['nodeMetadata', 'corrupt', 'metadata-rejected'];
        yield 'a node metadata record that is not a map' => ['nodeMetadata', ['route::GET::/posts' => 'routes/web.php'], 'metadata-rejected'];
    }

    #[Test]
    #[DataProvider('untrustedKeys')]
    public function one_untrusted_key_refuses_the_whole_entry(string $key, mixed $value, string $reason): void
    {
        $data = $this->validEntry();

        if ($value === null) {
            unset($data[$key]);
        } else {
            $data[$key] = $value;
        }

        $outcome = CodeGraphCodec::decode($data, 'graph.json');

        $this->assertNotInstanceOf(CodeGraph::class, $outcome->graph);
        $this->assertSame($reason, $outcome->refusal);
    }

    #[Test]
    public function a_metadata_record_loses_only_its_unusable_fields(): void
    {
        // The named exception: metadata annotates reports and never feeds the walk, so one bad field
        // costs that field, not the entry.
        $data = $this->validEntry();
        $data['nodeMetadata'] = ['route::GET::/posts' => ['file' => 'routes/web.php', 'line' => 'three']];

        $revived = CodeGraphCodec::decode($data, 'graph.json')->graph;

        $this->assertInstanceOf(CodeGraph::class, $revived);
        $this->assertSame(['file' => 'routes/web.php'], $revived->locationOf('route::GET::/posts'));
    }

    /** @return array<string, mixed> */
    private function validEntry(): array
    {
        return [
            'edges' => [['source' => 'route::GET::/posts', 'target' => 'App\Http\Controllers\PostController::index', 'type' => 'route-to-controller']],
            'hasUnparseableFiles' => false,
            'unresolvedDispatchSites' => [],
            'nodeMetadata' => [],
        ];
    }
}
