<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Analysis\ImpactAnalyzer;
use SanderMuller\Richter\Changes\ChangedFileSymbols;
use SanderMuller\Richter\Changes\MemberChange;
use SanderMuller\Richter\Graph\CodeGraph;
use SanderMuller\Richter\Tests\TestCase;

/**
 * Spec `override-fan-out-walk-policy`, phase `seeds`: a coarse class-level seed answers a caller
 * question, so it seeds the caller walk only. Walking the same class node downstream leaves the change
 * through `implements`/`uses-trait` and reaches everything the class is structurally related to.
 */
final class CoarseSeedDirectionTest extends TestCase
{
    private const string CHANGED_CLASS = 'App\Reports\CsvExporter';

    private const string INTERFACE_METHOD = 'App\Contracts\Exportable::export';

    /** @param  list<array{source: string, target: string, type: string}>  $extra */
    private function graph(array $extra = []): CodeGraph
    {
        $edges = [
            ['source' => self::CHANGED_CLASS, 'target' => 'App\Contracts\Exportable', 'type' => 'implements'],
            ['source' => 'App\Contracts\Exportable', 'target' => self::INTERFACE_METHOD, 'type' => 'declares'],
            ['source' => self::CHANGED_CLASS, 'target' => self::CHANGED_CLASS . '::export', 'type' => 'declares'],
            ['source' => self::INTERFACE_METHOD, 'target' => self::CHANGED_CLASS . '::export', 'type' => 'override'],
            ['source' => self::INTERFACE_METHOD, 'target' => 'App\Reports\PdfExporter::export', 'type' => 'override'],
            // A real caller of the changed CLASS — the reach a coarse change depends on.
            ['source' => 'route::GET::/exports', 'target' => 'App\Http\Controllers\ReportController', 'type' => 'route-to-controller'],
            ['source' => 'App\Http\Controllers\ReportController', 'target' => 'App\Http\Controllers\ReportController::index', 'type' => 'controller-to-action'],
            ['source' => 'App\Http\Controllers\ReportController::index', 'target' => self::CHANGED_CLASS, 'type' => 'action-to-service'],
        ];

        return new CodeGraph([...$edges, ...$extra], hasUnparseableFiles: false);
    }

    /** A removed method: non-additive and unresolvable, so the file needs the coarse class seed. */
    private function removedMethod(string $file, string $fqcn): ChangedFileSymbols
    {
        return new ChangedFileSymbols($file, $fqcn, [
            new MemberChange('legacyExport', MemberChange::KIND_METHOD, MemberChange::CHANGE_REMOVED, resolvable: false),
        ], cosmeticOnly: false);
    }

    /**
     * @param  list<array{depth: int, node: string, via: string, file?: string, line?: int}>  $hops
     * @return list<string>
     */
    private function nodes(array $hops): array
    {
        return array_values(array_column($hops, 'node'));
    }

    #[Test]
    public function a_coarse_seed_no_longer_walks_the_class_nodes_structure_downstream(): void
    {
        $result = new ImpactAnalyzer($this->graph())->detectChanges([
            $this->removedMethod('app/Reports/CsvExporter.php', self::CHANGED_CLASS),
        ]);

        $this->assertNotContains('App\Reports\PdfExporter::export', $this->nodes($result['dependencies']));
        $this->assertNotContains(self::INTERFACE_METHOD, $this->nodes($result['dependencies']));
        $this->assertArrayNotHasKey('App\Reports\PdfExporter::export', $result['reach']);
        // The ancestor the changed member overrides is still listed — it is the one node that really
        // does run this code without calling it. The SIBLING implementor is what had to go.
        $this->assertSame([self::INTERFACE_METHOD], $result['traitAndOverrideReach']);

        // The drawn edge set follows the same seeds, so the HTML report cannot draw a region the
        // counts deny.
        $dependencyEdgeSources = array_column($result['edges'], 'source');
        $this->assertNotContains(self::CHANGED_CLASS, $dependencyEdgeSources);
    }

    #[Test]
    public function a_coarse_seed_still_reports_the_changed_classs_callers(): void
    {
        $result = new ImpactAnalyzer($this->graph())->detectChanges([
            $this->removedMethod('app/Reports/CsvExporter.php', self::CHANGED_CLASS),
        ]);

        // The whole reason the class node stays a caller seed: this is the only reach a change that
        // pins to no member has.
        $this->assertContains('App\Http\Controllers\ReportController::index', $this->nodes($result['callers']));
        $this->assertSame(['route::GET::/exports'], $result['entryPoints']);
        $this->assertTrue($result['lowConfidence']);
    }

    #[Test]
    public function a_coarse_seed_with_no_member_nodes_keeps_its_class_node_downstream(): void
    {
        // An enum whose cases carry no node of their own: the class node is all there is, so
        // withholding it would unseed the walk instead of tightening it.
        $graph = new CodeGraph([
            ['source' => 'App\Enums\ExportFormat', 'target' => 'App\Services\FormatRegistry', 'type' => 'action-to-service'],
        ], hasUnparseableFiles: false);

        $result = new ImpactAnalyzer($graph)->detectChanges([
            new ChangedFileSymbols('app/Enums/ExportFormat.php', 'App\Enums\ExportFormat', [
                new MemberChange('Csv', MemberChange::KIND_ENUM_CASE, MemberChange::CHANGE_MODIFIED, resolvable: false),
            ], cosmeticOnly: false),
        ]);

        $this->assertContains('App\Services\FormatRegistry', $this->nodes($result['dependencies']));
        $this->assertSame(1, $result['impacted']);
    }

    #[Test]
    public function a_precise_member_change_keeps_walking_both_directions_from_its_member(): void
    {
        // The split touches the coarse half only: a resolvable member change is symmetric as before.
        $result = new ImpactAnalyzer($this->graph([
            ['source' => self::CHANGED_CLASS . '::export', 'target' => 'App\Services\Formatter::format', 'type' => 'action-to-service'],
        ]))->detectChanges([
            new ChangedFileSymbols('app/Reports/CsvExporter.php', self::CHANGED_CLASS, [
                new MemberChange('export', MemberChange::KIND_METHOD, MemberChange::CHANGE_MODIFIED, resolvable: true),
            ], cosmeticOnly: false),
        ]);

        $this->assertContains('App\Services\Formatter::format', $this->nodes($result['dependencies']));
        $this->assertContains(self::INTERFACE_METHOD, $this->nodes($result['callers']));
        $this->assertFalse($result['lowConfidence']);
    }

    #[Test]
    public function a_coarse_change_beside_a_precise_one_keeps_the_precise_members_reach(): void
    {
        $result = new ImpactAnalyzer($this->graph([
            ['source' => self::CHANGED_CLASS . '::export', 'target' => 'App\Services\Formatter::format', 'type' => 'action-to-service'],
        ]))->detectChanges([
            new ChangedFileSymbols('app/Reports/CsvExporter.php', self::CHANGED_CLASS, [
                new MemberChange('export', MemberChange::KIND_METHOD, MemberChange::CHANGE_MODIFIED, resolvable: true),
                new MemberChange('formats', MemberChange::KIND_PROPERTY, MemberChange::CHANGE_MODIFIED, resolvable: false),
            ], cosmeticOnly: false),
        ]);

        // Only the bare class node is withheld downstream; the precise member seed walks as always.
        $this->assertContains('App\Services\Formatter::format', $this->nodes($result['dependencies']));
        $this->assertNotContains('App\Reports\PdfExporter::export', $this->nodes($result['dependencies']));
        $this->assertTrue($result['lowConfidence']);
    }
}
