<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

use SanderMuller\Richter\Graph\NodeMetadata;

/**
 * Converts {@see ImpactAnalyzer} results into JSON-ready arrays for the `--json` command surface.
 * The machine payload is complete (uncapped, unlike {@see ImpactFormatter}'s text lists) and stable:
 * its shape is a semver-governed contract. detect-changes deliberately omits the raw caller/dependency
 * walk internals, exposing only the meaningful blast-radius summary.
 *
 * @phpstan-import-type SecurityShape from NodeMetadata
 */
final class JsonPresenter
{
    /**
     * @param  array{target: string, callers: list<array{depth: int, node: string, via: string, file?: string, line?: int}>, dependencies: list<array{depth: int, node: string, via: string, file?: string, line?: int}>, entryPoints: list<string>, associationEntryPoints: list<string>, entryPointPaths: array<string, list<array{node: string, via: string, file?: string, line?: int}>>, entryPointLocations: array<string, array{file: string, line?: int}>, entryPointSecurity: array<string, SecurityShape>, entryPointGates: array<string, list<string>>, entryPointAuthGates: array<string, list<string>>, ...}  $result
     * @return array{target: string, callers: list<array{depth: int, node: string, via: string, file?: string, line?: int}>, dependencies: list<array{depth: int, node: string, via: string, file?: string, line?: int}>, entryPoints: list<string>, associationEntryPoints: list<string>, entryPointPaths: array<string, list<array{node: string, via: string, file?: string, line?: int}>>, entryPointLocations: array<string, array{file: string, line?: int}>, entryPointSecurity: array<string, SecurityShape>, entryPointGates: array<string, list<string>>, entryPointAuthGates: array<string, list<string>>, entryPointTestReferences: array<string, 'referenced'|'referenced-no-behavioural-assertion'|'unreferenced'>}
     */
    public static function impact(array $result, ?TestReferenceIndex $tests = null): array
    {
        // Picked key by key, not passed through: the analyzer result also carries the miss diagnostics
        // (`suggestions`, `graphNodeCount`) the text report renders, and this document is a declared
        // contract — the MCP tool validates it against its own output schema, so an extra key here is
        // a schema violation, not a bonus. Widening the contract is a deliberate change, not a
        // side effect of adding a field for the human-readable report.
        return [
            'target' => $result['target'],
            'callers' => $result['callers'],
            'dependencies' => $result['dependencies'],
            'entryPoints' => $result['entryPoints'],
            'associationEntryPoints' => $result['associationEntryPoints'],
            'entryPointPaths' => $result['entryPointPaths'],
            'entryPointLocations' => $result['entryPointLocations'],
            'entryPointSecurity' => $result['entryPointSecurity'],
            'entryPointGates' => $result['entryPointGates'],
            'entryPointAuthGates' => $result['entryPointAuthGates'],
            'entryPointTestReferences' => self::entryPointTestReferences($result['entryPoints'], $tests),
        ];
    }

    /**
     * @param  array{from: string, to: string, resolvedFrom: list<string>, resolvedTo: list<string>, found: bool, path: list<array{node: string, via: string, file?: string, line?: int}>, furthestReached?: array{node: string, depth: int, file?: string, line?: int}}  $result
     * @return array{from: string, to: string, resolvedFrom: list<string>, resolvedTo: list<string>, found: bool, path: list<array{node: string, via: string, file?: string, line?: int}>, furthestReached?: array{node: string, depth: int, file?: string, line?: int}}
     */
    public static function trace(array $result): array
    {
        // Picked key by key like impact(): the document is a declared contract the MCP
        // tool validates against its output schema — widening it is a deliberate change.
        $document = [
            'from' => $result['from'],
            'to' => $result['to'],
            'resolvedFrom' => $result['resolvedFrom'],
            'resolvedTo' => $result['resolvedTo'],
            'found' => $result['found'],
            'path' => $result['path'],
        ];

        if (isset($result['furthestReached'])) {
            $document['furthestReached'] = $result['furthestReached'];
        }

        return $document;
    }

    /**
     * @param  array{changed: array<string, int>, coverage: array<string, 'analyzed'|'unresolved'>, entryPoints: list<string>, associationEntryPoints?: list<string>, entryPointPaths: array<string, list<array{node: string, via: string, file?: string, line?: int}>>, entryPointLocations: array<string, array{file: string, line?: int}>, entryPointSecurity: array<string, SecurityShape>, entryPointGates: array<string, list<string>>, impacted: int, relatedModels: list<string>, traitAndOverrideReach?: list<string>, traitAndOverrideReachVia?: array<string, list<string>>, risk: RiskLevel, riskCause?: string, hazards?: list<Hazard>, verification?: array<string, bool>, lowConfidence: bool, findings: list<string>, ...}  $result  the full {@see ImpactAnalyzer::detectChanges()} result; the caller/dependency walk internals it also carries are ignored here
     * @return array{base: string, changed: array<string, int>, coverage: array<string, 'analyzed'|'unresolved'>, entryPoints: list<string>, associationEntryPoints: list<string>, entryPointPaths: array<string, list<array{node: string, via: string, file?: string, line?: int}>>, entryPointLocations: array<string, array{file: string, line?: int}>, entryPointSecurity: array<string, SecurityShape>, entryPointGates: array<string, list<string>>, entryPointTestReferences: array<string, 'referenced'|'referenced-no-behavioural-assertion'|'unreferenced'>, impacted: int, relatedModels: list<string>, traitAndOverrideReach: list<string>, traitAndOverrideReachVia: array<string, list<string>>, risk: string, riskCause: string, hazards: list<array{lane: string, tier: int, cwe: string|null, member: string, reach: string, evidence: string}>, verification: array<string, bool>, lowConfidence: bool, findings: list<string>, unresolved: bool}
     */
    public static function detectChanges(array $result, string $base, ?TestReferenceIndex $tests = null): array
    {
        return [
            'base' => $base,
            'changed' => $result['changed'],
            'coverage' => $result['coverage'],
            'entryPoints' => $result['entryPoints'],
            // Chains are keyed by entry-point node; a self-listed entry class carries no chain, so
            // consumers can tell "reached from the change" apart from "is itself the entry surface".
            'entryPointPaths' => $result['entryPointPaths'],
            'entryPointLocations' => $result['entryPointLocations'],
            // Brain's per-route security surface and Pennant gating, routes only. The gates stay
            // pure annotation. The SECURITY surface no longer is: a hazard's reach class reads
            // `PUBLIC_WRITE` off it, so it reaches the level through {@see HazardReach} — see
            // `hazards[].reach`. It still seeds nothing and still never gates on its own.
            'entryPointSecurity' => $result['entryPointSecurity'],
            'entryPointGates' => $result['entryPointGates'],
            // No longer advisory-only: where a change carries no hazard, the level is decided by
            // whether a test references what it reaches. This map is the per-entry-point rendering of
            // that; `verification` is the exact set the level graded, which is narrower. Still never
            // an input to affected-tests selection or determinability. A node whose tri-state is null
            // (uncheckable) is omitted here rather than guessed — the level reads it as unverified.
            'entryPointTestReferences' => self::entryPointTestReferences($result['entryPoints'], $tests),
            'impacted' => $result['impacted'],
            'associationEntryPoints' => $result['associationEntryPoints'] ?? [],
            'relatedModels' => $result['relatedModels'],
            'traitAndOverrideReach' => $result['traitAndOverrideReach'] ?? [],
            'traitAndOverrideReachVia' => $result['traitAndOverrideReachVia'] ?? [],
            'risk' => $result['risk']->value,
            // Every level carries its cause. A bare level is not a renderable result — the machine
            // contract says so too, or a consumer surfacing `risk` alone reproduces the bare render.
            'riskCause' => $result['riskCause'] ?? '',
            'hazards' => self::hazards($result['hazards'] ?? []),
            'verification' => $result['verification'] ?? [],
            'lowConfidence' => $result['lowConfidence'],
            'findings' => $result['findings'],
            'unresolved' => in_array('unresolved', $result['coverage'], strict: true),
        ];
    }

    /**
     * The hazards flattened for the machine contract. This is the ONLY place a hazard becomes plain
     * data — the record stays an object everywhere else, so nothing in the pipeline has to parse its
     * own output.
     *
     * @param  list<Hazard>  $hazards
     * @return list<array{lane: string, tier: int, cwe: string|null, member: string, reach: string, evidence: string}>
     */
    private static function hazards(array $hazards): array
    {
        return array_map(static fn (Hazard $hazard): array => [
            'lane' => $hazard->lane,
            'tier' => $hazard->tier,
            'cwe' => $hazard->cwe,
            'member' => $hazard->member,
            'reach' => $hazard->reach ?? Hazard::REACH_NO_KNOWN_PATH,
            'evidence' => $hazard->evidence,
        ], $hazards);
    }

    /**
     * @param  list<string>  $entryPoints
     * @return array<string, 'referenced'|'referenced-no-behavioural-assertion'|'unreferenced'>
     */
    private static function entryPointTestReferences(array $entryPoints, ?TestReferenceIndex $tests): array
    {
        if (! $tests instanceof TestReferenceIndex) {
            return [];
        }

        $map = [];

        foreach ($entryPoints as $node) {
            $referenced = $tests->hasReference($node);

            if ($referenced === null) {
                continue;
            }

            $map[$node] = match (true) {
                $referenced && $tests->referencedWithoutBehaviouralAssertion($node) => 'referenced-no-behavioural-assertion',
                $referenced => 'referenced',
                default => 'unreferenced',
            };
        }

        return $map;
    }

    /**
     * The canonical zero-result for an empty diff — built without touching the graph, so the command's
     * no-build fast path stays intact. Same shape as {@see detectChanges()} minus the analyzer run.
     *
     * @return array{base: string, changed: array<string, int>, coverage: array<string, 'analyzed'|'unresolved'>, entryPoints: list<string>, associationEntryPoints: list<string>, entryPointPaths: array<string, list<array{node: string, via: string, file?: string, line?: int}>>, entryPointLocations: array<string, array{file: string, line?: int}>, entryPointSecurity: array<string, SecurityShape>, entryPointGates: array<string, list<string>>, entryPointTestReferences: array<string, 'referenced'|'referenced-no-behavioural-assertion'|'unreferenced'>, impacted: int, relatedModels: list<string>, traitAndOverrideReach: list<string>, traitAndOverrideReachVia: array<string, list<string>>, risk: string, riskCause: string, hazards: list<array{lane: string, tier: int, cwe: string|null, member: string, reach: string, evidence: string}>, verification: array<string, bool>, lowConfidence: bool, findings: list<string>, unresolved: bool}
     */
    public static function emptyDetectChanges(string $base): array
    {
        return [
            'base' => $base,
            'changed' => [],
            'coverage' => [],
            'entryPoints' => [],
            'entryPointPaths' => [],
            'entryPointLocations' => [],
            'entryPointSecurity' => [],
            'entryPointGates' => [],
            'entryPointTestReferences' => [],
            'impacted' => 0,
            'associationEntryPoints' => [],
            'relatedModels' => [],
            'traitAndOverrideReach' => [],
            'traitAndOverrideReachVia' => [],
            'risk' => RiskLevel::Low->value,
            'riskCause' => 'no analysable change: this diff touches nothing richter analyses',
            'hazards' => [],
            'verification' => [],
            'lowConfidence' => false,
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
