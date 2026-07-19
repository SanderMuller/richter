<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Analysis\ImpactFormatter;
use SanderMuller\Richter\Analysis\JsonPresenter;
use SanderMuller\Richter\Analysis\MarkdownFormatter;
use SanderMuller\Richter\Analysis\RiskLevel;
use SanderMuller\Richter\Analysis\TestReferenceIndex;
use SanderMuller\Richter\Tests\TestCase;

/**
 * One rich `detectChanges`-shaped fixture, rendered through all three surfaces
 * ({@see ImpactFormatter}, {@see MarkdownFormatter}, {@see JsonPresenter}). Unlike each formatter's
 * own test file — which builds a minimal, divergent fixture per assertion — this fixture turns on
 * every renderable field at once, so a field one format forgets shows up here as a missing
 * substring rather than as a silent gap noticed only in production. These are presence assertions,
 * not golden strings: they catch "field dropped in one format", not styling drift.
 */
final class FormatterContractTest extends TestCase
{
    private const string ANNOTATED_ENTRY = 'route::GET::/annotated';

    /**
     * Base shape lifted from {@see JsonPresenterTest::detectChangesResult()}, extended with: an
     * over-the-cap entry-point list (20, so both formatters' cap/collapse branch fires), a
     * multi-hop explain chain, an entry-point location, a security issue, a Pennant gate, an
     * unresolved changed file, related models, source findings, and a coarse-capped low-confidence
     * risk — every field either formatter can render, on at once.
     *
     * @return array{changed: array<string, int>, coverage: array<string, 'analyzed'|'unresolved'>, entryPoints: list<string>, entryPointPaths: array<string, list<array{node: string, via: string, file?: string, line?: int}>>, entryPointLocations: array<string, array{file: string, line?: int}>, entryPointSecurity: array<string, array{exposure: string, riskLevel: string, issues: list<array{type: string, severity: string, message: string, file?: string, line?: int}>}>, entryPointGates: array<string, list<string>>, impacted: int, relatedModels: list<string>, risk: RiskLevel, lowConfidence: bool, coarseCapApplied: bool, findings: list<string>}
     */
    private function richFixture(): array
    {
        // 1 annotated entry + 19 plain ones = 20, five over LIST_CAP (15). "annotated" sorts before
        // "r00".."r18" so it lands in the visible/shown portion, not the collapsed overflow.
        $entryPoints = [self::ANNOTATED_ENTRY];

        for ($i = 0; $i < 19; ++$i) {
            $entryPoints[] = sprintf('route::GET::/r%02d', $i);
        }

        return [
            'changed' => ['app/Models/Video.php' => 3, 'app/Services/Lost.php' => 1],
            'coverage' => ['app/Models/Video.php' => 'analyzed', 'app/Services/Lost.php' => 'unresolved'],
            'entryPoints' => $entryPoints,
            'entryPointPaths' => [
                self::ANNOTATED_ENTRY => [
                    ['node' => self::ANNOTATED_ENTRY, 'via' => 'route-to-controller', 'file' => 'routes/web.php', 'line' => 9],
                    ['node' => 'App\Http\Controllers\AnnotatedController::show', 'via' => 'action-to-service'],
                    ['node' => 'App\Services\AnnotatedService::run', 'via' => ''],
                ],
            ],
            'entryPointLocations' => [
                self::ANNOTATED_ENTRY => ['file' => 'routes/web.php', 'line' => 9],
            ],
            'entryPointSecurity' => [
                self::ANNOTATED_ENTRY => ['exposure' => 'public', 'riskLevel' => 'high', 'issues' => [
                    ['type' => 'PUBLIC_WRITE', 'severity' => 'high', 'message' => 'POST route with no auth middleware', 'file' => 'app/Http/Controllers/AnnotatedController.php', 'line' => 31],
                ]],
            ],
            'entryPointGates' => [self::ANNOTATED_ENTRY => ['beta-feature']],
            'impacted' => 42,
            'relatedModels' => ['App\Models\Interaction'],
            'risk' => RiskLevel::Medium,
            'lowConfidence' => true,
            'coarseCapApplied' => true,
            'findings' => ["app/Exports/X.php: eager-load string 'interactionsquestions' matches no relation"],
        ];
    }

    /** A test index that references the annotated entry's URI directly — exercises the
     *  test-referenced tag both formatters render. */
    private function richTestIndex(): TestReferenceIndex
    {
        $tests = new TestReferenceIndex();
        $tests->addSource('<?php $this->get("/annotated");');
        // A second, shallow-only reference (with a file, so it is gradable) exercises the
        // assertion-weak sub-tag on one of the plain entry points.
        $tests->addSource('<?php $this->get("/r01"); $response->assertOk();', 'tests/Feature/ShallowR01Test.php');

        return $tests;
    }

    #[Test]
    public function the_text_formatter_renders_every_populated_field(): void
    {
        $output = ImpactFormatter::detectChanges($this->richFixture(), $this->richTestIndex(), explain: true);

        $this->assertStringContainsString(self::ANNOTATED_ENTRY, $output);
        $this->assertStringContainsString('routes/web.php:9', $output);
        $this->assertStringContainsString('[public]', $output);
        $this->assertStringContainsString('[gated: beta-feature]', $output);
        $this->assertStringContainsString('test-referenced', $output);
        $this->assertStringContainsString('route::GET::/r01  [test-referenced — no behavioural assertion found]', $output);
        $this->assertStringContainsString('PUBLIC_WRITE (high): POST route with no auth middleware', $output);
        $this->assertStringContainsString('app/Http/Controllers/AnnotatedController.php:31', $output);
        $this->assertStringContainsString('App\Http\Controllers\AnnotatedController::show', $output);
        $this->assertStringContainsString('App\Services\AnnotatedService::run', $output);
        $this->assertStringContainsString('App\Models\Interaction', $output);
        $this->assertStringContainsString("eager-load string 'interactionsquestions' matches no relation", $output);
        $this->assertStringContainsString('UNRESOLVED', $output);
        $this->assertStringContainsString('… and 5 more', $output);
        $this->assertStringContainsStringIgnoringCase('low confidence', $output);
        $this->assertStringContainsString('risk capped at MEDIUM', $output);
    }

    #[Test]
    public function the_markdown_formatter_renders_every_populated_field(): void
    {
        $output = MarkdownFormatter::detectChanges($this->richFixture(), $this->richTestIndex(), explain: true);

        $this->assertStringContainsString(self::ANNOTATED_ENTRY, $output);
        $this->assertStringContainsString('routes/web.php:9', $output);
        $this->assertStringContainsString('🔓 public', $output);
        $this->assertStringContainsString('🚩 beta-feature', $output);
        $this->assertStringContainsString('test-referenced', $output);
        $this->assertStringContainsString('`route::GET::/r01` — 🟡 test-referenced, no behavioural assertion found', $output);
        $this->assertStringContainsString('PUBLIC_WRITE** (high): POST route with no auth middleware', $output);
        $this->assertStringContainsString('app/Http/Controllers/AnnotatedController.php:31', $output);
        $this->assertStringContainsString('App\Http\Controllers\AnnotatedController::show', $output);
        $this->assertStringContainsString('App\Services\AnnotatedService::run', $output);
        $this->assertStringContainsString('App\Models\Interaction', $output);
        $this->assertStringContainsString("eager-load string 'interactionsquestions' matches no relation", $output);
        $this->assertStringContainsString('UNRESOLVED', $output);
        $this->assertStringContainsString('… and 5 more', $output);
        $this->assertStringContainsStringIgnoringCase('low confidence', $output);
        $this->assertStringContainsString('risk capped at MEDIUM', $output);
    }

    #[Test]
    public function the_json_presenter_carries_every_documented_key(): void
    {
        $json = JsonPresenter::detectChanges($this->richFixture(), 'origin/main', $this->richTestIndex());

        foreach ([
            'base', 'changed', 'coverage', 'entryPoints', 'entryPointPaths', 'entryPointLocations',
            'entryPointSecurity', 'entryPointGates', 'entryPointTestReferences', 'impacted',
            'relatedModels', 'risk', 'lowConfidence', 'coarseCapApplied', 'findings', 'unresolved',
        ] as $key) {
            $this->assertArrayHasKey($key, $json);
        }

        // The annotated entry's reference has no file to grade (fileless) — plain "referenced".
        // The r01 entry carries only a shallow status-check assertion — the weak sub-state.
        $this->assertSame('referenced', $json['entryPointTestReferences'][self::ANNOTATED_ENTRY]);
        $this->assertSame('referenced-no-behavioural-assertion', $json['entryPointTestReferences']['route::GET::/r01']);
        $this->assertSame('unreferenced', $json['entryPointTestReferences']['route::GET::/r02']);

        // Key presence alone is tautological — JsonPresenter hard-codes every key in one array
        // literal — so assert the populated VALUES survived the mapping, mirroring what the text
        // and markdown tests prove through substrings.
        $this->assertSame(self::ANNOTATED_ENTRY, array_key_first($json['entryPointPaths']));
        $this->assertCount(3, $json['entryPointPaths'][self::ANNOTATED_ENTRY]);
        $this->assertSame(['file' => 'routes/web.php', 'line' => 9], $json['entryPointLocations'][self::ANNOTATED_ENTRY]);
        $this->assertSame('public', $json['entryPointSecurity'][self::ANNOTATED_ENTRY]['exposure']);
        $this->assertSame('PUBLIC_WRITE', $json['entryPointSecurity'][self::ANNOTATED_ENTRY]['issues'][0]['type']);
        $this->assertSame(['beta-feature'], $json['entryPointGates'][self::ANNOTATED_ENTRY]);
        $this->assertSame(['App\Models\Interaction'], $json['relatedModels']);
        $this->assertSame(['app/Models/Video.php' => 3, 'app/Services/Lost.php' => 1], $json['changed']);
        $this->assertSame('unresolved', $json['coverage']['app/Services/Lost.php']);
        $this->assertSame(["app/Exports/X.php: eager-load string 'interactionsquestions' matches no relation"], $json['findings']);
        $this->assertSame(42, $json['impacted']);
        $this->assertSame('origin/main', $json['base']);
        $this->assertTrue($json['unresolved']);
        $this->assertTrue($json['lowConfidence']);
        $this->assertTrue($json['coarseCapApplied']);
        $this->assertSame('medium', $json['risk']);
        $this->assertCount(20, $json['entryPoints']);
    }
}
