<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Analysis\JsonPresenter;
use SanderMuller\Richter\Analysis\RiskLevel;
use SanderMuller\Richter\Analysis\TestReferenceIndex;
use SanderMuller\Richter\Tests\TestCase;

final class JsonPresenterTest extends TestCase
{
    #[Test]
    public function impact_passes_the_analyzer_shape_through_unchanged(): void
    {
        $result = [
            'target' => 'App\\Models\\User',
            'callers' => [['depth' => 1, 'node' => 'route::GET /users', 'via' => 'controller']],
            'dependencies' => [['depth' => 2, 'node' => 'App\\Models\\Team', 'via' => 'relation']],
        ];

        $this->assertSame($result, JsonPresenter::impact($result));
    }

    #[Test]
    public function impact_no_match_serialises_to_empty_arrays_never_prose(): void
    {
        $json = JsonPresenter::impact([
            'target' => 'Zzz\\Nonexistent\\Symbol',
            'callers' => [],
            'dependencies' => [],
        ]);

        $this->assertSame('Zzz\\Nonexistent\\Symbol', $json['target']);
        $this->assertSame([], $json['callers']);
        $this->assertSame([], $json['dependencies']);
    }

    #[Test]
    public function detect_changes_stringifies_risk_and_omits_walk_internals(): void
    {
        $json = JsonPresenter::detectChanges($this->detectChangesResult(risk: RiskLevel::Medium), 'origin/main');

        $this->assertSame('origin/main', $json['base']);
        $this->assertSame('medium', $json['risk']);
        $this->assertArrayNotHasKey('callers', $json);
        $this->assertArrayNotHasKey('dependencies', $json);
        $this->assertSame(3, $json['impacted']);
        $this->assertSame(['App\\Models\\Video'], $json['relatedModels']);
        $this->assertSame(['app/Jobs/ProcessVideoJob.php: touches a queue job'], $json['findings']);
    }

    #[Test]
    public function detect_changes_carries_the_entry_point_chains_with_hop_locations(): void
    {
        $json = JsonPresenter::detectChanges($this->detectChangesResult(), 'origin/main');

        $this->assertSame([
            'route::GET /a' => [
                ['node' => 'route::GET /a', 'via' => 'route-to-controller', 'file' => 'routes/web.php', 'line' => 12],
                ['node' => 'App\\Jobs\\ProcessVideoJob::handle', 'via' => '', 'file' => 'app/Jobs/ProcessVideoJob.php'],
            ],
        ], $json['entryPointPaths']);
    }

    #[Test]
    public function detect_changes_carries_entry_point_locations_and_security_annotation(): void
    {
        $json = JsonPresenter::detectChanges($this->detectChangesResult(), 'origin/main');

        $this->assertSame(['route::GET /a' => ['file' => 'routes/web.php', 'line' => 12]], $json['entryPointLocations']);
        $this->assertSame(['route::GET /a' => ['interactive-video']], $json['entryPointGates']);
        $this->assertSame([
            'route::GET /a' => ['exposure' => 'public', 'riskLevel' => 'high', 'issues' => [
                ['type' => 'PUBLIC_WRITE', 'severity' => 'high', 'message' => 'POST route with no auth middleware'],
            ]],
        ], $json['entryPointSecurity']);
    }

    #[Test]
    public function detect_changes_flags_unresolved_from_the_coverage_map(): void
    {
        $withUnresolved = JsonPresenter::detectChanges($this->detectChangesResult(coverageUnresolved: true), 'origin/main');
        $allAnalyzed = JsonPresenter::detectChanges($this->detectChangesResult(coverageUnresolved: false), 'origin/main');

        $this->assertTrue($withUnresolved['unresolved']);
        $this->assertFalse($allAnalyzed['unresolved']);
    }

    #[Test]
    public function detect_changes_leaves_the_entry_point_list_uncapped(): void
    {
        // The text formatter caps rendered lists at 15; the machine payload must stay complete.
        $entryPoints = array_map(static fn (int $i): string => "route::GET /r{$i}", range(1, 20));

        $json = JsonPresenter::detectChanges($this->detectChangesResult(entryPoints: $entryPoints), 'origin/main');

        $this->assertCount(20, $json['entryPoints']);
    }

    #[Test]
    public function empty_detect_changes_is_the_canonical_zero_object(): void
    {
        $json = JsonPresenter::emptyDetectChanges('origin/develop');

        $this->assertSame('origin/develop', $json['base']);
        $this->assertSame('low', $json['risk']);
        $this->assertSame([], $json['changed']);
        $this->assertSame([], $json['coverage']);
        $this->assertSame([], $json['entryPoints']);
        $this->assertSame([], $json['entryPointPaths']);
        $this->assertSame([], $json['entryPointLocations']);
        $this->assertSame([], $json['entryPointSecurity']);
        $this->assertSame([], $json['entryPointGates']);
        $this->assertSame([], $json['entryPointTestReferences']);
        $this->assertSame(0, $json['impacted']);
        $this->assertFalse($json['unresolved']);
    }

    #[Test]
    public function detect_changes_omits_the_test_reference_map_without_an_index(): void
    {
        $json = JsonPresenter::detectChanges($this->detectChangesResult(), 'origin/main');

        $this->assertSame([], $json['entryPointTestReferences']);
    }

    #[Test]
    public function detect_changes_carries_the_test_reference_map_referenced_weak_unreferenced_and_omitted(): void
    {
        $tests = new TestReferenceIndex();
        $tests->addSource('<?php $this->get("/a"); $this->assertDatabaseHas("videos", ["id" => 1]);', 'tests/Feature/RichTest.php');
        $tests->addSource('<?php $this->get("/b"); $response->assertOk();', 'tests/Feature/ShallowTest.php');

        $entryPoints = ['route::GET::/a', 'route::GET::/b', 'route::GET::/c', 'schedule::nightly-report'];
        $json = JsonPresenter::detectChanges($this->detectChangesResult(entryPoints: $entryPoints), 'origin/main', $tests);

        $this->assertSame([
            'route::GET::/a' => 'referenced',
            'route::GET::/b' => 'referenced-no-behavioural-assertion',
            'route::GET::/c' => 'unreferenced',
        ], $json['entryPointTestReferences']);
        // A schedule node cannot be checked at all — omitted, never guessed.
        $this->assertArrayNotHasKey('schedule::nightly-report', $json['entryPointTestReferences']);
    }

    #[Test]
    public function encode_emits_parseable_json(): void
    {
        $decoded = json_decode(JsonPresenter::encode(['risk' => 'high']), associative: true);

        $this->assertSame(['risk' => 'high'], $decoded);
    }

    /**
     * @param  list<string>  $entryPoints
     * @return array{changed: array<string, int>, coverage: array<string, 'analyzed'|'unresolved'>, entryPoints: list<string>, entryPointPaths: array<string, list<array{node: string, via: string, file?: string, line?: int}>>, entryPointLocations: array<string, array{file: string, line?: int}>, entryPointSecurity: array<string, array{exposure: string, riskLevel: string, issues: list<array{type: string, severity: string, message: string, file?: string, line?: int}>}>, entryPointGates: array<string, list<string>>, impacted: int, relatedModels: list<string>, risk: RiskLevel, lowConfidence: bool, coarseCapApplied: bool, findings: list<string>}
     */
    private function detectChangesResult(RiskLevel $risk = RiskLevel::Low, bool $coverageUnresolved = false, array $entryPoints = ['route::GET /a', 'route::GET /b', 'route::GET /c']): array
    {
        return [
            'changed' => ['app/Jobs/ProcessVideoJob.php' => 3],
            'coverage' => ['app/Jobs/ProcessVideoJob.php' => $coverageUnresolved ? 'unresolved' : 'analyzed'],
            'entryPoints' => $entryPoints,
            'entryPointPaths' => [
                'route::GET /a' => [
                    ['node' => 'route::GET /a', 'via' => 'route-to-controller', 'file' => 'routes/web.php', 'line' => 12],
                    ['node' => 'App\\Jobs\\ProcessVideoJob::handle', 'via' => '', 'file' => 'app/Jobs/ProcessVideoJob.php'],
                ],
            ],
            'entryPointLocations' => [
                'route::GET /a' => ['file' => 'routes/web.php', 'line' => 12],
            ],
            'entryPointSecurity' => [
                'route::GET /a' => ['exposure' => 'public', 'riskLevel' => 'high', 'issues' => [
                    ['type' => 'PUBLIC_WRITE', 'severity' => 'high', 'message' => 'POST route with no auth middleware'],
                ]],
            ],
            'entryPointGates' => ['route::GET /a' => ['interactive-video']],
            'impacted' => count($entryPoints),
            'relatedModels' => ['App\\Models\\Video'],
            'risk' => $risk,
            'lowConfidence' => false,
            'coarseCapApplied' => false,
            'findings' => ['app/Jobs/ProcessVideoJob.php: touches a queue job'],
        ];
    }
}
