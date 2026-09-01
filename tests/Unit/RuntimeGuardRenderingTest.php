<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Analysis\HtmlFormatter;
use SanderMuller\Richter\Analysis\ImpactFormatter;
use SanderMuller\Richter\Analysis\MarkdownFormatter;
use SanderMuller\Richter\Analysis\RiskLevel;
use SanderMuller\Richter\Tests\TestCase;

final class RuntimeGuardRenderingTest extends TestCase
{
    /** @return array{target: string, callers: list<array{depth: int, node: string, via: string}>, dependencies: list<array{depth: int, node: string, via: string}>, entryPoints: list<string>, associationEntryPoints: list<string>, entryPointPaths: array<string, list<array{node: string, via: string}>>, entryPointLocations: array<string, array{file: string}>, entryPointSecurity: array<string, array{exposure: string, riskLevel: string, issues: list<array{type: string, severity: string, message: string}>}>, entryPointGates: array<string, list<string>>, entryPointAuthGates: array<string, list<string>>, entryPointAuthMiddleware: array<string, list<string>>, entryPointRuntimeGuards: array<string, list<array{middleware: string, group: string|null}>>} */
    private static function impactResult(): array
    {
        $route = 'route::POST::/pay';

        return [
            'target' => 'App\\Services\\PaymentRecorder',
            'callers' => [['depth' => 1, 'node' => $route, 'via' => 'route-to-controller']],
            'dependencies' => [],
            'entryPoints' => [$route],
            'associationEntryPoints' => [],
            'entryPointPaths' => [],
            'entryPointLocations' => [],
            'entryPointSecurity' => [$route => [
                'exposure' => 'public',
                'riskLevel' => 'medium',
                'issues' => [['type' => 'PUBLIC_WRITE', 'severity' => 'medium', 'message' => 'POST route with no auth middleware']],
            ]],
            'entryPointGates' => [],
            'entryPointAuthGates' => [],
            'entryPointAuthMiddleware' => [],
            'entryPointRuntimeGuards' => [$route => [
                ['middleware' => 'Illuminate\\Auth\\Middleware\\Authenticate', 'group' => 'web'],
                ['middleware' => 'App\\Http\\Middleware\\HmacGate', 'group' => null],
            ]],
        ];
    }

    #[Test]
    public function the_text_report_renders_the_runtime_note_beside_brains_finding(): void
    {
        $report = ImpactFormatter::impact(self::impactResult());

        // Evidence beside the finding, never a suppression: both lines render.
        $this->assertStringContainsString('PUBLIC_WRITE', $report);
        $this->assertStringContainsString("Illuminate\\Auth\\Middleware\\Authenticate (via middleware group 'web')", $report);
        $this->assertStringContainsString('App\\Http\\Middleware\\HmacGate (applied directly)', $report);
        $this->assertStringContainsString('booted router', $report);
    }

    #[Test]
    public function the_markdown_report_renders_the_runtime_note_beside_brains_finding(): void
    {
        $report = MarkdownFormatter::impact(self::impactResult());

        $this->assertStringContainsString('PUBLIC_WRITE', $report);
        $this->assertStringContainsString("(via middleware group 'web')", $report);
        $this->assertStringContainsString('(applied directly)', $report);
    }

    #[Test]
    public function the_html_report_renders_the_runtime_note_beside_brains_finding(): void
    {
        $result = [
            ...self::impactResult(),
            'changed' => ['app/Services/PaymentRecorder.php' => 1],
            'coverage' => ['app/Services/PaymentRecorder.php' => 'analyzed'],
            'newFiles' => [],
            'fqcns' => [],
            'seeds' => [],
            'reach' => [],
            'edges' => [],
            'impacted' => 1,
            'relatedModels' => [],
            'risk' => RiskLevel::Medium,
            'riskCause' => 'tier 2 `contract` hazard, reach gated',
            'hazards' => [],
            'verification' => [],
            'lowConfidence' => false,
            'findings' => [],
        ];

        $html = HtmlFormatter::detectChanges($result, [], 'origin/main');

        $this->assertStringContainsString('via middleware group &#039;web&#039;', $html);
        $this->assertStringContainsString('applied directly', $html);
    }
}
