<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Analysis\BoundedPresenter;
use SanderMuller\Richter\Analysis\JsonPresenter;
use SanderMuller\Richter\Tests\TestCase;

final class BoundedPresenterTest extends TestCase
{
    /**
     * A synthetic hub-sized impact document: every breadth array crosses the cap, and every
     * per-entry map carries one value per list entry so restriction is observable.
     *
     * @return array{target: string, callers: list<array{depth: int, node: string, via: string}>, dependencies: list<array{depth: int, node: string, via: string}>, entryPoints: list<string>, associationEntryPoints: list<string>, associationEntryPointsVia: array<string, list<string>>, entryPointPaths: array<string, list<array{node: string, via: string}>>, entryPointLocations: array<string, array{file: string}>, entryPointSecurity: array{}, entryPointGates: array{}, entryPointAuthGates: array{}, entryPointTestReferences: array<string, string>, entryPointRuntimeGuards: array<string, list<array{middleware: string, group: string|null}>>, bogusIgnored: string}
     */
    private function hubImpactDocument(): array
    {
        $callers = [];
        $dependencies = [];

        foreach (range(1, 20) as $i) {
            $callers[] = ['depth' => intdiv($i + 1, 2), 'node' => "App\\Caller{$i}", 'via' => 'static-call'];
        }

        foreach (range(1, 18) as $i) {
            $dependencies[] = ['depth' => 1, 'node' => "App\\Dependency{$i}", 'via' => 'action-to-service'];
        }

        $entryPoints = array_map(static fn (int $i): string => "route::GET::/things/{$i}", range(1, 17));
        $association = array_map(static fn (int $i): string => "route::GET::/admin/{$i}", range(1, 16));

        return [
            'target' => 'App\\Models\\Thing',
            'callers' => $callers,
            'dependencies' => $dependencies,
            'entryPoints' => $entryPoints,
            'associationEntryPoints' => $association,
            'associationEntryPointsVia' => array_fill_keys($association, ['model-relationship']),
            'entryPointPaths' => array_fill_keys($entryPoints, [['node' => 'App\\X', 'via' => 'route-to-controller']]),
            'entryPointLocations' => array_fill_keys($entryPoints, ['file' => 'routes/web.php']),
            'entryPointSecurity' => [],
            'entryPointGates' => [],
            'entryPointAuthGates' => [],
            'entryPointTestReferences' => array_fill_keys($entryPoints, 'unreferenced'),
            'entryPointRuntimeGuards' => array_fill_keys($entryPoints, [['middleware' => 'Illuminate\\Auth\\Middleware\\Authenticate', 'group' => 'web']]),
            'bogusIgnored' => 'untouched',
        ];
    }

    #[Test]
    public function a_hub_impact_document_is_capped_with_totals_and_restricted_maps(): void
    {
        $document = BoundedPresenter::impact($this->hubImpactDocument());

        $this->assertTrue($document['bounded']);
        $this->assertSame(20, $document['callersTotal']);
        $this->assertSame(18, $document['dependenciesTotal']);
        $this->assertSame(17, $document['entryPointsTotal']);
        $this->assertSame(16, $document['associationEntryPointsTotal']);

        $this->assertIsArray($document['callers']);
        $this->assertIsArray($document['dependencies']);
        $this->assertIsArray($document['entryPoints']);
        $this->assertIsArray($document['associationEntryPoints']);
        $this->assertCount(15, $document['callers']);
        $this->assertCount(15, $document['dependencies']);
        $this->assertCount(15, $document['entryPoints']);
        $this->assertCount(15, $document['associationEntryPoints']);

        // Order preserved: BFS depth order means the nearest hops survive, so the first caller in
        // the full document is still the first caller in the capped one.
        $this->assertSame(['depth' => 1, 'node' => 'App\\Caller1', 'via' => 'static-call'], $document['callers'][0]);

        // Maps restrict to the shown keys of their own key domain.
        $this->assertIsArray($document['entryPointPaths']);
        $this->assertIsArray($document['entryPointTestReferences']);
        $this->assertIsArray($document['associationEntryPointsVia']);
        $this->assertSame($document['entryPoints'], array_keys($document['entryPointPaths']));
        $this->assertSame($document['entryPoints'], array_keys($document['entryPointTestReferences']));
        $this->assertIsArray($document['entryPointRuntimeGuards']);
        $this->assertSame($document['entryPoints'], array_keys($document['entryPointRuntimeGuards']));
        $this->assertSame($document['associationEntryPoints'], array_keys($document['associationEntryPointsVia']));

        // A map that was already empty stays an empty ARRAY, so it still serializes as [].
        $this->assertSame([], $document['entryPointSecurity']);
    }

    #[Test]
    public function a_leaf_impact_document_is_unbounded_with_matching_totals(): void
    {
        $leaf = $this->hubImpactDocument();
        $leaf['callers'] = array_slice($leaf['callers'], 0, 2);
        $leaf['dependencies'] = [];
        $leaf['entryPoints'] = array_slice($leaf['entryPoints'], 0, 3);
        $leaf['associationEntryPoints'] = [];
        $leaf['associationEntryPointsVia'] = [];
        $leaf['entryPointPaths'] = array_fill_keys($leaf['entryPoints'], []);
        $leaf['entryPointLocations'] = [];
        $leaf['entryPointTestReferences'] = array_fill_keys($leaf['entryPoints'], 'referenced');
        $leaf['entryPointRuntimeGuards'] = [];

        $document = BoundedPresenter::impact($leaf);

        $this->assertFalse($document['bounded']);
        $this->assertSame(2, $document['callersTotal']);
        $this->assertSame(0, $document['dependenciesTotal']);
        $this->assertSame(3, $document['entryPointsTotal']);
        $this->assertSame(0, $document['associationEntryPointsTotal']);

        // Byte-identical to the input apart from the new fields: nothing was capped or restricted.
        $withoutNewFields = $document;
        unset($withoutNewFields['bounded'], $withoutNewFields['callersTotal'], $withoutNewFields['dependenciesTotal'], $withoutNewFields['entryPointsTotal'], $withoutNewFields['associationEntryPointsTotal']);
        $this->assertSame($leaf, $withoutNewFields);
    }

    #[Test]
    public function a_detect_changes_keep_set_is_capped_with_its_own_total(): void
    {
        $entryPoints = array_map(static fn (int $i): string => "route::GET::/things/{$i}", range(1, 17));

        $document = BoundedPresenter::detectChanges([
            'base' => 'origin/main',
            'entryPoints' => $entryPoints,
            'associationEntryPoints' => [],
            'relatedModels' => ['App\\Models\\A', 'App\\Models\\B'],
            'traitAndOverrideReach' => [],
            'entryPointKeepSet' => ['kept' => $entryPoints, 'droppedHub' => 3],
        ]);

        $this->assertTrue($document['bounded']);
        $this->assertSame(17, $document['entryPointsTotal']);
        $this->assertSame(2, $document['relatedModelsTotal']);

        $keepSet = $document['entryPointKeepSet'];
        $this->assertIsArray($keepSet);
        $this->assertIsArray($keepSet['kept']);
        $this->assertCount(15, $keepSet['kept']);
        $this->assertSame(17, $keepSet['keptTotal']);
        $this->assertSame(3, $keepSet['droppedHub']);
    }

    #[Test]
    public function the_empty_detect_changes_document_reads_unbounded_with_zero_totals(): void
    {
        $document = BoundedPresenter::detectChanges(JsonPresenter::emptyDetectChanges('origin/main'));

        $this->assertFalse($document['bounded']);
        $this->assertSame(0, $document['entryPointsTotal']);
        $this->assertSame(0, $document['associationEntryPointsTotal']);
        $this->assertSame(0, $document['relatedModelsTotal']);
        $this->assertSame(0, $document['traitAndOverrideReachTotal']);

        $keepSet = $document['entryPointKeepSet'];
        $this->assertIsArray($keepSet);
        $this->assertSame([], $keepSet['kept']);
        $this->assertSame(0, $keepSet['keptTotal']);

        // The empty maps must still be PHP arrays so they JSON-encode as [].
        $this->assertSame('[]', json_encode($document['entryPointPaths']));
    }

    #[Test]
    public function full_lifts_every_cap_while_keeping_the_bounding_fields(): void
    {
        $original = $this->hubImpactDocument();
        $document = BoundedPresenter::impact($original, full: true);

        $this->assertFalse($document['bounded']);
        $this->assertSame($original['callers'], $document['callers']);
        $this->assertSame($original['entryPoints'], $document['entryPoints']);
        $this->assertSame($original['entryPointPaths'], $document['entryPointPaths']);
        $this->assertSame(20, $document['callersTotal']);
        $this->assertSame(17, $document['entryPointsTotal']);
    }

    #[Test]
    public function full_wins_over_entries(): void
    {
        $withEntries = BoundedPresenter::impact($this->hubImpactDocument(), full: true, entries: ['route::GET::/things/17']);

        $this->assertSame(BoundedPresenter::impact($this->hubImpactDocument(), full: true), $withEntries);
    }

    #[Test]
    public function entries_keeps_named_nodes_visible_past_the_cap_without_duplicates_or_fabrication(): void
    {
        $document = BoundedPresenter::impact($this->hubImpactDocument(), entries: [
            'route::GET::/things/17',   // past the cap: appended
            'route::GET::/things/17',   // repeated in the request: once
            'route::GET::/things/1',    // already in the capped prefix: no duplicate
            'route::GET::/nowhere',     // unknown: silently no row
        ]);

        $this->assertTrue($document['bounded']);
        $entryPoints = $this->stringList($document['entryPoints']);
        $this->assertCount(16, $entryPoints);
        $this->assertSame('route::GET::/things/17', $entryPoints[15]);
        $this->assertSame(array_unique($entryPoints), $entryPoints);
        $this->assertNotContains('route::GET::/nowhere', $entryPoints);
        $this->assertLessThanOrEqual($document['entryPointsTotal'], count($entryPoints));

        // The expanded node keeps its existing map values — and gains none the full document never
        // held: the security map is empty in the fixture, and expansion must not fabricate a key.
        $this->assertIsArray($document['entryPointPaths']);
        $this->assertArrayHasKey('route::GET::/things/17', $document['entryPointPaths']);
        $this->assertSame([], $document['entryPointSecurity']);
    }

    #[Test]
    public function entries_naming_every_omitted_node_restores_the_complete_list_and_clears_bounded(): void
    {
        $original = $this->hubImpactDocument();
        $pastCap = array_slice($original['entryPoints'], BoundedPresenter::LIST_CAP);

        $document = BoundedPresenter::impact($original, entries: $pastCap);

        // Callers/dependencies are still capped, so the document as a whole stays bounded — but a
        // request that only fans out entry points must clear when every omitted node was named.
        $entryPointsOnly = BoundedPresenter::impact([...$original, 'callers' => [], 'dependencies' => [], 'associationEntryPoints' => [], 'associationEntryPointsVia' => []], entries: $pastCap);

        $this->assertCount(17, $this->stringList($document['entryPoints']));
        $this->assertFalse($entryPointsOnly['bounded']);
        $this->assertTrue($document['bounded']);
    }

    #[Test]
    public function the_keep_set_and_entry_points_cap_independently(): void
    {
        // Hub folding can drop EARLY entry points from `kept`, so its capped prefix may carry rows
        // past the visible entryPoints prefix. That is the documented contract: kept is a subset of
        // the FULL entryPoints, not of the capped ones.
        $entryPoints = array_map(static fn (int $i): string => "route::GET::/things/{$i}", range(1, 20));
        $kept = array_slice($entryPoints, 5); // the first five are hub-only

        $document = BoundedPresenter::detectChanges([
            'base' => 'origin/main',
            'entryPoints' => $entryPoints,
            'associationEntryPoints' => [],
            'relatedModels' => [],
            'traitAndOverrideReach' => [],
            'entryPointKeepSet' => ['kept' => $kept, 'droppedHub' => 5],
        ]);

        $shownEntryPoints = $this->stringList($document['entryPoints']);
        $keepSet = $document['entryPointKeepSet'];
        $this->assertIsArray($keepSet);
        $shownKept = $this->stringList($keepSet['kept']);

        $this->assertCount(15, $shownEntryPoints);
        $this->assertCount(15, $shownKept);
        $this->assertSame(15, $keepSet['keptTotal']);
        $this->assertContains('route::GET::/things/20', $shownKept);
        $this->assertNotContains('route::GET::/things/20', $shownEntryPoints);
    }

    /**
     * Narrows a document value to the string list it is asserted to be — the count assertion right
     * after each use is what proves nothing was filtered away.
     *
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        $strings = [];

        foreach (is_array($value) ? $value : [] as $item) {
            if (is_string($item)) {
                $strings[] = $item;
            }
        }

        return $strings;
    }

    #[Test]
    public function the_cli_json_presenter_documents_carry_no_bounding_fields(): void
    {
        // RICH-011: CLI --json is the complete, uncapped contract. The bounding fields exist only
        // on the MCP surface, so the presenter documents themselves must not carry them.
        $document = JsonPresenter::emptyDetectChanges('origin/main');

        $this->assertArrayNotHasKey('bounded', $document);
        $this->assertArrayNotHasKey('entryPointsTotal', $document);
    }
}
