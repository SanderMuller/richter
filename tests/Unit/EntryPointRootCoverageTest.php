<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Analysis\EntryPointRootCoverage;
use SanderMuller\Richter\Graph\CodeGraph;
use SanderMuller\Richter\Tests\TestCase;

/**
 * The note that catches a subsystem missing from the graph because its directory is not a
 * configured entry-point root. Its whole value is in staying quiet, so most of what is asserted
 * here is silence.
 */
final class EntryPointRootCoverageTest extends TestCase
{
    private string $root = '';

    protected function tearDown(): void
    {
        if ($this->root !== '' && is_dir($this->root)) {
            $this->deleteTree($this->root);
        }

        parent::tearDown();
    }

    #[Test]
    public function a_populated_directory_absent_from_the_graph_is_reported(): void
    {
        $this->project(['Commands' => 6]);

        $notes = EntryPointRootCoverage::notes($this->root, $this->graphOf(['App\Models\Post']));

        $this->assertCount(1, $notes);
        $this->assertStringContainsString('app/Commands/ holds 6 classes', $notes[0]);
        $this->assertStringContainsString('richter.entry_point_roots', $notes[0]);
    }

    #[Test]
    public function a_directory_with_any_graph_presence_is_silent(): void
    {
        // One class of the six is enough: partial presence is the normal state of a directory, and
        // a ratio threshold would fire on healthy apps.
        $this->project(['Commands' => 6]);

        $this->assertSame([], EntryPointRootCoverage::notes($this->root, $this->graphOf(['App\Commands\Class3::handle'])));
    }

    #[Test]
    public function graph_presence_counts_through_a_prefixed_node_id(): void
    {
        // A class reaches the graph as `model::Fqcn` or `action::Fqcn::method` just as truly as by
        // its bare FQCN; missing that would make the note fire on directories that are covered.
        $this->project(['Models' => 5]);

        $this->assertSame([], EntryPointRootCoverage::notes($this->root, $this->graphOf(['model::App\Models\Class1'])));
    }

    #[Test]
    public function a_surface_node_is_never_read_as_a_class(): void
    {
        // `route::`/`view::`/`command::`/`schedule::` ids address a uri, name or signature. Parsing
        // one as an FQCN could only ever produce a false match.
        $this->project(['Commands' => 5]);

        $notes = EntryPointRootCoverage::notes($this->root, $this->graphOf([
            'route::GET::/commands',
            'view::commands.index',
            'command::app:run {--x}',
        ]));

        $this->assertCount(1, $notes);
    }

    #[Test]
    public function a_directory_under_the_minimum_is_silent(): void
    {
        // A stub or half-created directory is absent from the graph for reasons that are not a
        // misconfiguration.
        $this->project(['Commands' => 4]);

        $this->assertSame([], EntryPointRootCoverage::notes($this->root, $this->graphOf(['App\Models\Post'])));
    }

    #[Test]
    public function a_configured_root_is_never_proposed(): void
    {
        $this->project(['Jobs' => 6]);

        $this->assertSame([], EntryPointRootCoverage::notes($this->root, $this->graphOf(['App\Models\Post']), ['Jobs']));
    }

    #[Test]
    public function a_directory_containing_a_configured_root_is_never_proposed(): void
    {
        // `Http/Middleware` being traced must not turn into advice to add `Http` — that would trace
        // every controller as an entry surface.
        $this->project(['Http' => 6]);

        $this->assertSame([], EntryPointRootCoverage::notes($this->root, $this->graphOf(['App\Models\Post']), ['Http/Middleware']));
    }

    #[Test]
    public function every_reported_directory_is_named_once_and_in_order(): void
    {
        $this->project(['Zeta' => 5, 'Alpha' => 5]);

        $notes = EntryPointRootCoverage::notes($this->root, $this->graphOf(['App\Models\Post']));

        $this->assertCount(2, $notes);
        $this->assertStringContainsString('app/Alpha/', $notes[0]);
        $this->assertStringContainsString('app/Zeta/', $notes[1]);
    }

    #[Test]
    public function a_project_without_an_app_directory_is_silent(): void
    {
        $this->root = (string) tempnam(sys_get_temp_dir(), 'richter-coverage-');
        unlink($this->root);
        mkdir($this->root, 0o777, true);

        $this->assertSame([], EntryPointRootCoverage::notes($this->root, $this->graphOf(['App\Models\Post'])));
    }

    /**
     * A throwaway project tree: `app/<dir>/ClassN.php` per entry. Only the paths matter — the
     * coverage check derives an FQCN from the path and never parses the file.
     *
     * @param  array<string, int>  $directories  directory name => how many classes it holds
     */
    private function project(array $directories): void
    {
        $this->root = (string) tempnam(sys_get_temp_dir(), 'richter-coverage-');
        unlink($this->root);

        foreach ($directories as $directory => $count) {
            mkdir($this->root . '/app/' . $directory, 0o777, true);

            for ($i = 1; $i <= $count; ++$i) {
                file_put_contents($this->root . '/app/' . $directory . '/Class' . $i . '.php', '<?php');
            }
        }
    }

    /** @param  list<string>  $nodes */
    private function graphOf(array $nodes): CodeGraph
    {
        return new CodeGraph(
            array_map(static fn (string $node): array => ['source' => $node, 'target' => 'App\Support\Sink', 'type' => 'service'], $nodes),
            hasUnparseableFiles: false,
        );
    }
}
