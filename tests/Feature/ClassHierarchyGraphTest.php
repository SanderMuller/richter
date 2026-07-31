<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Graph\CodeGraph;
use SanderMuller\Richter\Graph\CodeGraphBuilder;
use SanderMuller\Richter\Tests\TestCase;
use SanderMuller\Richter\Tracers\ClassHierarchyTracer;

/**
 * Plan cha-wire: the graph build wires {@see ClassHierarchyTracer} into
 * the tracer branch, so a concrete override reached only through polymorphic dispatch stops being
 * orphaned. Uses an isolated temp-dir app so no shared-fixture counts shift, and the Brain-independent
 * {@see CodeGraphBuilder::buildTracerBranch()} so the assertion needs no booted route graph.
 */
final class ClassHierarchyGraphTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/richter-cha-' . bin2hex(random_bytes(6));

        // An abstract template-method base plus two concrete overrides — the classic polymorphic
        // dispatch shape (a caller would hold a ReportExporter and call ->export(), which runs the
        // concrete ->body()). Brain would link the abstract only; CHA must link the overrides.
        $this->write('app/Reports/ReportExporter.php', <<<'PHP'
            <?php declare(strict_types=1);
            namespace App\Reports;
            abstract class ReportExporter
            {
                abstract protected function body(): string;
                final public function export(): string { return $this->body(); }
            }
            PHP);

        $this->write('app/Reports/CsvExporter.php', <<<'PHP'
            <?php declare(strict_types=1);
            namespace App\Reports;
            final class CsvExporter extends ReportExporter
            {
                protected function body(): string { return 'csv'; }
            }
            PHP);

        $this->write('app/Reports/PdfExporter.php', <<<'PHP'
            <?php declare(strict_types=1);
            namespace App\Reports;
            final class PdfExporter extends ReportExporter
            {
                protected function body(): string { return 'pdf'; }
            }
            PHP);
    }

    protected function tearDown(): void
    {
        $this->deleteRecursive($this->root);

        parent::tearDown();
    }

    #[Test]
    public function the_build_links_an_abstract_method_to_its_concrete_overrides(): void
    {
        $branch = new CodeGraphBuilder()->buildTracerBranch($this->root);

        // The wiring produced the override edges (ancestor → concrete), for every implementor.
        $this->assertContains(
            ['source' => 'App\Reports\ReportExporter::body', 'target' => 'App\Reports\CsvExporter::body', 'type' => 'override'],
            $branch['edges'],
        );
        $this->assertContains(
            ['source' => 'App\Reports\ReportExporter::body', 'target' => 'App\Reports\PdfExporter::body', 'type' => 'override'],
            $branch['edges'],
        );
    }

    #[Test]
    public function a_concrete_override_reaches_the_abstract_call_site_through_the_graph(): void
    {
        $graph = new CodeGraph(new CodeGraphBuilder()->buildTracerBranch($this->root)['edges'], hasUnparseableFiles: false);

        // Seeded as a changed member, the concrete override now walks UP to the abstract method — the
        // reach the whole feature exists for. Without the CHA override edge this list would be empty
        // (the concrete override has no other caller), so this assertion fails closed if CHA is removed.
        // callersOf returns {depth, node, via} rows.
        $callers = $graph->callersOf(['App\Reports\CsvExporter::body']);

        $this->assertContains('App\Reports\ReportExporter::body', array_column($callers, 'node'));
        $abstract = array_values(array_filter($callers, static fn (array $c): bool => $c['node'] === 'App\Reports\ReportExporter::body'));
        $this->assertSame('override', $abstract[0]['via'], 'the concrete override should be reached via the CHA override edge');
    }

    private function write(string $relativePath, string $contents): void
    {
        $absolute = $this->root . '/' . $relativePath;
        @mkdir(dirname($absolute), 0777, true);
        file_put_contents($absolute, $contents);
    }

    private function deleteRecursive(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach (array_diff((array) scandir($path), ['.', '..']) as $entry) {
            $child = $path . '/' . $entry;
            is_dir($child) ? $this->deleteRecursive($child) : @unlink($child);
        }

        @rmdir($path);
    }
}
