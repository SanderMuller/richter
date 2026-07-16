<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Analysis\JsonPresenter;
use SanderMuller\Richter\Analysis\RiskLevel;
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
        $this->assertSame(0, $json['impacted']);
        $this->assertFalse($json['unresolved']);
    }

    #[Test]
    public function encode_emits_parseable_json(): void
    {
        $decoded = json_decode(JsonPresenter::encode(['risk' => 'high']), associative: true);

        $this->assertSame(['risk' => 'high'], $decoded);
    }

    /**
     * @param  list<string>  $entryPoints
     * @return array{changed: array<string, int>, coverage: array<string, 'analyzed'|'unresolved'>, entryPoints: list<string>, impacted: int, relatedModels: list<string>, risk: RiskLevel, lowConfidence: bool, coarseCapApplied: bool, findings: list<string>}
     */
    private function detectChangesResult(RiskLevel $risk = RiskLevel::Low, bool $coverageUnresolved = false, array $entryPoints = ['route::GET /a', 'route::GET /b', 'route::GET /c']): array
    {
        return [
            'changed' => ['app/Jobs/ProcessVideoJob.php' => 3],
            'coverage' => ['app/Jobs/ProcessVideoJob.php' => $coverageUnresolved ? 'unresolved' : 'analyzed'],
            'entryPoints' => $entryPoints,
            'impacted' => count($entryPoints),
            'relatedModels' => ['App\\Models\\Video'],
            'risk' => $risk,
            'lowConfidence' => false,
            'coarseCapApplied' => false,
            'findings' => ['app/Jobs/ProcessVideoJob.php: touches a queue job'],
        ];
    }
}
