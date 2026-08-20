<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Analysis\ImpactAnalyzer;
use SanderMuller\Richter\Changes\ChangedFileSymbols;
use SanderMuller\Richter\Changes\MemberChange;
use SanderMuller\Richter\Graph\CodeGraph;
use SanderMuller\Richter\Graph\CodeGraphBuilder;
use SanderMuller\Richter\Tests\TestCase;

/**
 * Spec `override-fan-out-walk-policy`, phase `fixture`: the end-to-end guard, over a real build rather
 * than hand-written edges. One app interface implemented by four classes is the shape that made a
 * change to ONE of them report every sibling as reached. Uses an isolated temp-dir app and the
 * Brain-independent {@see CodeGraphBuilder::buildTracerBranch()}, so no shared-fixture counts shift.
 */
final class HubInterfaceFanOutTest extends TestCase
{
    private const string CHANGED_FILE = 'app/Reports/CsvExporter.php';

    private const string CHANGED_CLASS = 'App\Reports\CsvExporter';

    private const string INTERFACE_METHOD = 'App\Contracts\Exportable::export';

    /** @var list<string> */
    private const array SIBLINGS = ['App\Reports\PdfExporter', 'App\Reports\HtmlExporter', 'App\Reports\XmlExporter'];

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/richter-hub-' . bin2hex(random_bytes(6));

        $this->write('app/Contracts/Exportable.php', <<<'PHP'
            <?php declare(strict_types=1);
            namespace App\Contracts;
            interface Exportable
            {
                public function export(): string;
                public function label(): string;
            }
            PHP);

        foreach (['Csv', 'Pdf', 'Html', 'Xml'] as $format) {
            $this->write("app/Reports/{$format}Exporter.php", <<<PHP
                <?php declare(strict_types=1);
                namespace App\Reports;
                use App\Contracts\Exportable;
                final class {$format}Exporter implements Exportable
                {
                    public function export(): string { return '{$format}'; }
                    public function label(): string { return '{$format}'; }
                }
                PHP);
        }

        // Holds the interface and dispatches through it — the polymorphic call CHA exists to follow.
        $this->write('app/Services/ExportRunner.php', <<<'PHP'
            <?php declare(strict_types=1);
            namespace App\Services;
            use App\Contracts\Exportable;
            final class ExportRunner
            {
                public function run(Exportable $exporter): string
                {
                    return $exporter->export();
                }
            }
            PHP);
    }

    protected function tearDown(): void
    {
        $this->deleteRecursive($this->root);

        parent::tearDown();
    }

    /**
     * The built hierarchy plus the two edge kinds a real build adds outside the tracer branch, which
     * is deliberately Brain-independent and emits neither:
     *
     * - `declares`, from a class-like to a member node something references. {@see CodeGraphBuilder}
     *   emits these in the merge step, so `buildTracerBranch()` alone leaves the interface node
     *   unconnected to its own methods — and the `implements` → `declares` → `override` chain this
     *   spec is about could not form.
     * - the call on a constructor-injected interface (`$exporter->export()`), which Brain resolves
     *   against the parameter's static type.
     *
     * Stated explicitly rather than pretended: the assertions below are only meaningful over a graph
     * that carries the same chain a real application graph does.
     */
    private function graph(): CodeGraph
    {
        $edges = new CodeGraphBuilder()->buildTracerBranch($this->root)['edges'];

        return new CodeGraph([
            ...$edges,
            ['source' => 'App\Contracts\Exportable', 'target' => self::INTERFACE_METHOD, 'type' => 'declares'],
            ['source' => 'App\Contracts\Exportable', 'target' => 'App\Contracts\Exportable::label', 'type' => 'declares'],
            ['source' => 'App\Services\ExportRunner::run', 'target' => self::INTERFACE_METHOD, 'type' => 'action-to-service'],
        ], hasUnparseableFiles: false);
    }

    private function analyzer(): ImpactAnalyzer
    {
        return new ImpactAnalyzer($this->graph());
    }

    /**
     * @param  list<string>  $traitAndOverrideReach
     * @param  array<string, array<string, true>>  $reach
     */
    private function assertNoSiblingReported(array $traitAndOverrideReach, array $reach): void
    {
        foreach (self::SIBLINGS as $sibling) {
            foreach (['export', 'label'] as $method) {
                $this->assertNotContains("{$sibling}::{$method}", $traitAndOverrideReach);
                $this->assertArrayNotHasKey("{$sibling}::{$method}", $reach);
            }
        }
    }

    #[Test]
    public function the_fixture_really_carries_the_hub_override_edges(): void
    {
        // Fails closed: if the build stopped emitting these, every assertion below would pass for the
        // wrong reason.
        $edges = new CodeGraphBuilder()->buildTracerBranch($this->root)['edges'];

        $this->assertContains(['source' => self::INTERFACE_METHOD, 'target' => 'App\Reports\PdfExporter::export', 'type' => 'override'], $edges);
        $this->assertContains(['source' => self::INTERFACE_METHOD, 'target' => self::CHANGED_CLASS . '::export', 'type' => 'override'], $edges);
    }

    #[Test]
    public function a_resolvable_member_change_reports_no_sibling_implementor(): void
    {
        $result = $this->analyzer()->detectChanges([
            new ChangedFileSymbols(self::CHANGED_FILE, self::CHANGED_CLASS, [
                new MemberChange('export', MemberChange::KIND_METHOD, MemberChange::CHANGE_MODIFIED, resolvable: true),
            ], cosmeticOnly: false),
        ]);

        $this->assertNoSiblingReported($result['traitAndOverrideReach'], $result['reach']);

        // The ancestor the changed member overrides is the one node that runs it without calling it.
        $this->assertSame([self::INTERFACE_METHOD], $result['traitAndOverrideReach']);
        $this->assertFalse($result['lowConfidence']);
    }

    #[Test]
    public function a_removed_method_takes_the_coarse_lane_and_still_reports_no_sibling(): void
    {
        $result = $this->analyzer()->detectChanges([
            new ChangedFileSymbols(self::CHANGED_FILE, self::CHANGED_CLASS, [
                new MemberChange('legacyExport', MemberChange::KIND_METHOD, MemberChange::CHANGE_REMOVED, resolvable: false),
            ], cosmeticOnly: false),
        ]);

        $this->assertTrue($result['lowConfidence'], 'a removed method pins to no node, so this is the coarse lane');
        $this->assertNoSiblingReported($result['traitAndOverrideReach'], $result['reach']);
        $this->assertSame('analyzed', $result['coverage'][self::CHANGED_FILE]);
    }

    #[Test]
    public function the_reached_set_of_a_member_change_is_exactly_the_hierarchy_above_it(): void
    {
        $result = $this->analyzer()->detectChanges([
            new ChangedFileSymbols(self::CHANGED_FILE, self::CHANGED_CLASS, [
                new MemberChange('export', MemberChange::KIND_METHOD, MemberChange::CHANGE_MODIFIED, resolvable: true),
            ], cosmeticOnly: false),
        ]);

        $reached = array_keys($result['reach']);
        sort($reached);

        // Asserted as a whole set, not one absent node. The sibling CLASS nodes are still here: they
        // arrive upstream through `implements` from the interface, which the spec records as a known
        // residual (Open Questions 1) and does not address. What the fix removes is their MEMBERS —
        // `PdfExporter::export` and friends, the 473-node fan-out — and none of them appear.
        $this->assertSame([
            'App\Contracts\Exportable',
            self::INTERFACE_METHOD,
            'App\Reports\CsvExporter',
            'App\Reports\HtmlExporter',
            'App\Reports\PdfExporter',
            'App\Reports\XmlExporter',
            'App\Services\ExportRunner::run',
        ], $reached);
    }

    #[Test]
    public function a_change_to_the_interface_method_still_reaches_every_implementor(): void
    {
        // The CHA feature itself, over the same fixture: seeded on the ancestor, every override is
        // seed-adjacent and must still be reported.
        $result = $this->analyzer()->detectChanges([
            new ChangedFileSymbols('app/Contracts/Exportable.php', 'App\Contracts\Exportable', [
                new MemberChange('export', MemberChange::KIND_METHOD, MemberChange::CHANGE_MODIFIED, resolvable: true),
            ], cosmeticOnly: false),
        ]);

        foreach ([self::CHANGED_CLASS, ...self::SIBLINGS] as $implementor) {
            $this->assertContains("{$implementor}::export", $result['traitAndOverrideReach']);
        }
    }

    #[Test]
    public function a_caller_dispatching_through_the_interface_still_reaches_every_implementor(): void
    {
        // The path carries a call, so the fan-out is real dispatch and must survive the gate.
        $reached = array_column($this->graph()->dependenciesOf(['App\Services\ExportRunner::run']), 'node');

        foreach ([self::CHANGED_CLASS, ...self::SIBLINGS] as $implementor) {
            $this->assertContains("{$implementor}::export", $reached);
        }
    }

    #[Test]
    public function a_brand_new_implementor_does_not_list_its_siblings(): void
    {
        // A new file seeds its CLASS node precisely, so the coarse split never applies — only the gate
        // keeps the siblings out.
        $result = $this->analyzer()->detectChanges([
            new ChangedFileSymbols(self::CHANGED_FILE, self::CHANGED_CLASS, [
                new MemberChange('export', MemberChange::KIND_METHOD, MemberChange::CHANGE_ADDED, resolvable: true),
            ], cosmeticOnly: false, isNewFile: true),
        ]);

        $this->assertNoSiblingReported($result['traitAndOverrideReach'], $result['reach']);
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
