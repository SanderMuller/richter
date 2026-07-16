<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

/**
 * Converts {@see ImpactAnalyzer} results into JSON-ready arrays for the `--json` command surface.
 * The machine payload is complete (uncapped, unlike {@see ImpactFormatter}'s text lists) and stable:
 * its shape is a semver-governed contract. detect-changes deliberately omits the raw caller/dependency
 * walk internals, exposing only the meaningful blast-radius summary.
 */
final class JsonPresenter
{
    /**
     * @param  array{target: string, callers: list<array{depth: int, node: string, via: string}>, dependencies: list<array{depth: int, node: string, via: string}>}  $result
     * @return array{target: string, callers: list<array{depth: int, node: string, via: string}>, dependencies: list<array{depth: int, node: string, via: string}>}
     */
    public static function impact(array $result): array
    {
        // Already JSON-ready: a no-match result is target + empty callers/dependencies, never prose.
        return $result;
    }

    /**
     * @param  array{changed: array<string, int>, coverage: array<string, 'analyzed'|'unresolved'>, entryPoints: list<string>, impacted: int, relatedModels: list<string>, risk: RiskLevel, lowConfidence: bool, coarseCapApplied: bool, findings: list<string>, ...}  $result  the full {@see ImpactAnalyzer::detectChanges()} result; the caller/dependency walk internals it also carries are ignored here
     * @return array{base: string, changed: array<string, int>, coverage: array<string, 'analyzed'|'unresolved'>, entryPoints: list<string>, impacted: int, relatedModels: list<string>, risk: string, lowConfidence: bool, coarseCapApplied: bool, findings: list<string>, unresolved: bool}
     */
    public static function detectChanges(array $result, string $base): array
    {
        return [
            'base' => $base,
            'changed' => $result['changed'],
            'coverage' => $result['coverage'],
            'entryPoints' => $result['entryPoints'],
            'impacted' => $result['impacted'],
            'relatedModels' => $result['relatedModels'],
            'risk' => $result['risk']->value,
            'lowConfidence' => $result['lowConfidence'],
            'coarseCapApplied' => $result['coarseCapApplied'],
            'findings' => $result['findings'],
            'unresolved' => in_array('unresolved', $result['coverage'], strict: true),
        ];
    }

    /**
     * The canonical zero-result for an empty diff — built without touching the graph, so the command's
     * no-build fast path stays intact. Same shape as {@see detectChanges()} minus the analyzer run.
     *
     * @return array{base: string, changed: array<string, int>, coverage: array<string, 'analyzed'|'unresolved'>, entryPoints: list<string>, impacted: int, relatedModels: list<string>, risk: string, lowConfidence: bool, coarseCapApplied: bool, findings: list<string>, unresolved: bool}
     */
    public static function emptyDetectChanges(string $base): array
    {
        return [
            'base' => $base,
            'changed' => [],
            'coverage' => [],
            'entryPoints' => [],
            'impacted' => 0,
            'relatedModels' => [],
            'risk' => RiskLevel::Low->value,
            'lowConfidence' => false,
            'coarseCapApplied' => false,
            'findings' => [],
            'unresolved' => false,
        ];
    }

    /** @param  array<string, mixed>  $data */
    public static function encode(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
