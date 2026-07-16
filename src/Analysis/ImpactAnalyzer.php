<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

use Illuminate\Support\Str;
use SanderMuller\Richter\Changes\ChangedFileSymbols;
use SanderMuller\Richter\Graph\CodeGraph;
use SanderMuller\Richter\Support\Fqcn;

/** Over a {@see CodeGraph}: impact(symbol) blast radius + detectChanges(files) reached entry points/risk. Advisory only: risk is a coarse signal, not a gate. */
final readonly class ImpactAnalyzer
{
    private const array ENTRY_POINT_PREFIXES = ['route::', 'command::', 'schedule::'];

    private const array ENTRY_POINT_NAMESPACES = ['\\Jobs\\', '\\Console\\Commands\\', '\\Listeners\\', '\\Livewire\\', '\\Observers\\', '\\Http\\Middleware\\'];

    /**
     * Edge types that associate rather than invoke — reach through them is not risk. `uses-trait`
     * is excluded deliberately: a hub trait with dozens of using classes would otherwise
     * saturate the impacted count for any one-method change — the classic over-reporting shape.
     * Reach, coverage, and entry-point discovery still flow through these edges.
     */
    private const array RISK_EXCLUDED_EDGE_TYPES = ['model-relationship', 'declares', 'uses-trait'];

    public function __construct(private CodeGraph $graph) {}

    /**
     * @return array{
     *     target: string,
     *     callers: list<array{depth: int, node: string, via: string}>,
     *     dependencies: list<array{depth: int, node: string, via: string}>,
     * }
     */
    public function impact(string $symbol, int $maxDepth = 6): array
    {
        $seeds = $this->seedsFor($symbol);

        return [
            'target' => $symbol,
            'callers' => $this->graph->callersOf($seeds, $maxDepth),
            'dependencies' => $this->graph->dependenciesOf($seeds, $maxDepth),
        ];
    }

    /**
     * @param  list<ChangedFileSymbols>  $changed  the member-level change set per file (see ChangedSymbols)
     * @return array{
     *     changed: array<string, int>,
     *     coverage: array<string, 'analyzed'|'unresolved'>,
     *     callers: list<array{depth: int, node: string, via: string}>,
     *     dependencies: list<array{depth: int, node: string, via: string}>,
     *     entryPoints: list<string>,
     *     impacted: int,
     *     relatedModels: list<string>,
     *     risk: RiskLevel,
     *     lowConfidence: bool,
     *     coarseCapApplied: bool,
     *     findings: list<string>,
     * }
     */
    public function detectChanges(array $changed, int $maxDepth = 6): array
    {
        $preciseSeeds = [];
        $coarseSeeds = [];
        $perFileSeeds = [];
        $summary = [];
        $coverage = [];
        $touchesEntryClass = false;

        foreach ($changed as $file) {
            // Additive (new member) or cosmetic (whitespace/import-reorder) change has no existing callers — seeds nothing, raises no risk floor (even on jobs).
            if ($file->hasOnlyAdditiveOrCosmeticChanges()) {
                $summary[$file->file] = 0;
                $coverage[$file->file] = 'analyzed';

                continue;
            }

            $fileSeeds = [];

            foreach ($file->resolvableMembers() as $member) {
                $fileSeeds = [...$fileSeeds, ...$this->memberSeeds($file->fqcn, $member->name)];
            }

            // A changed Blade view seeds its own node directly (no members to pin) — a precise seed,
            // so it raises no low-confidence flag; it resolves to nothing only when the view is
            // unreachable, which then reads as UNRESOLVED below, not "no impact".
            foreach ($file->directSeeds as $directSeed) {
                $fileSeeds = [...$fileSeeds, ...$this->seedsFor($directSeed)];
            }

            $preciseSeeds = [...$preciseSeeds, ...$fileSeeds];

            // A non-resolvable change (enum case body, constant value, $fillable/casts(), class modifier) can't pin to a member — coarse class seed instead; its HIGH is untrustworthy (cap catches it), so track apart from precise seeds.
            if ($file->needsCoarseSeed()) {
                $coarse = $this->seedsFor($file->fqcn);
                $coarseSeeds = [...$coarseSeeds, ...$coarse];
                $fileSeeds = [...$fileSeeds, ...$coarse];
            }

            // Only a real change to an uncharted entry-point class keeps the MEDIUM floor; the additive/cosmetic case returned LOW above.
            if ($this->isEntryPointClass($file->file)) {
                $touchesEntryClass = true;
            }

            $fileSeeds = array_values(array_unique($fileSeeds));
            $perFileSeeds[$file->file] = $fileSeeds;
            $summary[$file->file] = count($fileSeeds);
            // A non-additive change that resolves to no graph node at all can't be placed — that reads
            // "couldn't determine", never a falsely-reassuring "no impact". A change that does resolve
            // to a node but reaches nothing is a real leaf and stays "analyzed".
            $coverage[$file->file] = $fileSeeds === [] ? 'unresolved' : 'analyzed';
        }

        $preciseSeeds = array_values(array_unique($preciseSeeds));
        $coarseSeeds = array_values(array_unique($coarseSeeds));
        $seeds = array_values(array_unique([...$preciseSeeds, ...$coarseSeeds]));
        // Low confidence only when a coarse seed actually resolved to a node — a coarse change to a class absent from the graph seeds nothing.
        $lowConfidence = $coarseSeeds !== [];

        $callers = $this->graph->callersOf($seeds, $maxDepth);
        $dependencies = $this->graph->dependenciesOf($seeds, $maxDepth);

        $entryPoints = $this->entryPointsAmong($callers);
        $riskEntryPointCount = count($entryPoints);

        // A changed listener/job/command/Livewire class IS an entry surface even when nothing app-side
        // calls it (a vendor-fired event, an unfollowable dispatch) — "Entry points reached: 0"
        // under-communicates "this runs on every SAML login". List the class itself, but only when its
        // own seeds reached no entry point (a job whose dispatcher resolved to a route needs no echo).
        // Excluded from the risk inputs: the risk floor for entry classes is touchesEntryClass, and
        // counting self-listings would rate touching three jobs as HIGH by count alone.
        foreach ($changed as $file) {
            // The in_array is a duplicate-append guard for two changed files of one class — graph
            // entry points are prefix-keyed and never collide with a bare FQCN.
            if ($file->hasOnlyAdditiveOrCosmeticChanges()) {
                continue;
            }

            if (! $this->isEntryPointClass($file->file)) {
                continue;
            }

            if (in_array($file->fqcn, $entryPoints, strict: true)) {
                continue;
            }

            [$ownEntryPoints] = $this->riskInputs($perFileSeeds[$file->file] ?? [], $maxDepth);

            if ($ownEntryPoints === 0) {
                $entryPoints[] = $file->fqcn;
            }
        }

        // A changed job reaching no entry point of its own is genuinely-empty only if every dispatcher resolved — an unfollowable dispatch means it could still be reached (UNRESOLVED). Decided per job on its own seeds so a sibling can't mask it.
        if ($this->graph->hasUnresolvedDispatches()) {
            foreach ($changed as $file) {
                // Additive/cosmetic-only changes seeded nothing on purpose (raise no floor, even on jobs) — exempt them from the unresolved-dispatch flip.
                if ($file->hasOnlyAdditiveOrCosmeticChanges()) {
                    continue;
                }

                if (($coverage[$file->file] ?? null) !== 'analyzed') {
                    continue;
                }

                if (! Str::contains($file->fqcn, '\\Jobs\\')) {
                    continue;
                }

                [$jobEntryPoints] = $this->riskInputs($perFileSeeds[$file->file] ?? [], $maxDepth);

                if ($jobEntryPoints === 0) {
                    $coverage[$file->file] = 'unresolved';
                }
            }
        }

        // A node only reachable through `model-relationship` is context, not risk — counting it lets touching a hub model saturate to HIGH on relation breadth alone. Any behavioural edge still counts.
        $reach = $this->graph->reachedViaTypes($seeds, $maxDepth);
        $impacted = count(array_filter($reach, $this->isRiskBearing(...)));
        $relatedModels = array_keys(array_filter(
            $reach,
            fn (array $types): bool => ! $this->isRiskBearing($types) && isset($types['model-relationship']),
        ));

        [$risk, $coarseCapApplied] = $this->riskWithCoarseCap($impacted, $riskEntryPointCount, $touchesEntryClass, $preciseSeeds, $lowConfidence, $maxDepth);

        $findings = [];

        foreach ($changed as $file) {
            foreach ($file->findings as $finding) {
                $findings[] = "{$file->file}: {$finding}";
            }
        }

        return [
            'changed' => $summary,
            'coverage' => $coverage,
            'callers' => $callers,
            'dependencies' => $dependencies,
            'entryPoints' => $entryPoints,
            'impacted' => $impacted,
            'relatedModels' => $this->readableModelLabels($relatedModels),
            'risk' => $risk,
            'lowConfidence' => $lowConfidence,
            'coarseCapApplied' => $coarseCapApplied,
            'findings' => $findings,
        ];
    }

    /**
     * Cap a coarse low-confidence HIGH to MEDIUM only when precise seeds alone don't already justify HIGH (so a genuine method change isn't masked by a co-touched $fillable). Second element: whether the cap actually downgraded.
     *
     * @param  list<string>  $preciseSeeds
     * @return array{0: RiskLevel, 1: bool}
     */
    private function riskWithCoarseCap(int $impacted, int $entryPoints, bool $touchesEntryClass, array $preciseSeeds, bool $lowConfidence, int $maxDepth): array
    {
        $risk = $this->risk($impacted, $entryPoints, $touchesEntryClass);

        if (! $lowConfidence || $risk !== RiskLevel::High) {
            return [$risk, false];
        }

        [$preciseEntryPoints, $preciseImpacted] = $this->riskInputs($preciseSeeds, $maxDepth);

        return $this->risk($preciseImpacted, $preciseEntryPoints, $touchesEntryClass) === RiskLevel::High
            ? [RiskLevel::High, false]
            : [RiskLevel::Medium, true];
    }

    /**
     * @param  list<string>  $modelNodes
     * @return list<string>
     */
    private function readableModelLabels(array $modelNodes): array
    {
        $labels = [];

        foreach (array_unique($modelNodes) as $node) {
            // A Brain node whose fqcn didn't normalise keeps its `model::ShortName` id — render the
            // short name, and collapse it into the FQCN label when exactly one FQCN carries that
            // basename. Two-plus candidates (App\Models\Theme vs App\Models\Playlist\Theme) are
            // ambiguous: keep the short label, failing toward showing more.
            $labels[] = str_starts_with($node, 'model::') ? substr($node, strlen('model::')) : $node;
        }

        $basenameCounts = [];

        foreach ($labels as $label) {
            if (str_contains($label, '\\')) {
                $basename = substr($label, (int) strrpos($label, '\\') + 1);
                $basenameCounts[$basename] = ($basenameCounts[$basename] ?? 0) + 1;
            }
        }

        return array_values(array_unique(array_filter(
            $labels,
            static fn (string $label): bool => str_contains($label, '\\') || ($basenameCounts[$label] ?? 0) !== 1,
        )));
    }

    /**
     * @param  list<string>  $seeds
     * @return array{0: int, 1: int} [entryPointCount, impactedCount]
     */
    private function riskInputs(array $seeds, int $maxDepth): array
    {
        if ($seeds === []) {
            return [0, 0];
        }

        $entryPoints = $this->entryPointsAmong($this->graph->callersOf($seeds, $maxDepth));
        $impacted = count(array_filter($this->graph->reachedViaTypes($seeds, $maxDepth), $this->isRiskBearing(...)));

        return [count($entryPoints), $impacted];
    }

    /**
     * @param  list<array{depth: int, node: string, via: string}>  $callers
     * @return list<string>
     */
    private function entryPointsAmong(array $callers): array
    {
        return array_values(array_unique(array_filter(
            array_map(static fn (array $hop): string => $hop['node'], $callers),
            $this->isEntryPointNode(...),
        )));
    }

    /**
     * Counts toward risk if any edge reaching it is behavioural — not an excluded association edge (`model-relationship`, `alias` bridge).
     *
     * @param  array<string, true>  $viaTypes
     */
    private function isRiskBearing(array $viaTypes): bool
    {
        return array_diff_key($viaTypes, array_flip(self::RISK_EXCLUDED_EDGE_TYPES)) !== [];
    }

    /**
     * Exact member-level seed (`{class}::{method}`), exact on the method segment so `publish` never matches `publishNow`.
     *
     * @return list<string>
     */
    private function memberSeeds(string $fqcn, string $method): array
    {
        $suffix = '::' . $method;

        return array_values(array_filter(
            $this->candidateNodes(ltrim($fqcn, '\\')),
            static fn (string $node): bool => str_ends_with($node, $suffix),
        ));
    }

    /**
     * Seed nodes matching the FQCN — both class-level (`App\Models\Video`) and member-level (`App\Models\Video::query`) nodes.
     *
     * @return list<string>
     */
    private function seedsFor(string $symbol): array
    {
        return $this->candidateNodes(ltrim($symbol, '\\'));
    }

    /**
     * Graph nodes whose FQCN-keyed id contains the given FQCN.
     *
     * @return list<string>
     */
    private function candidateNodes(string $fqcn): array
    {
        return $this->graph->nodesContaining($fqcn);
    }

    private function isEntryPointNode(string $node): bool
    {
        return Str::startsWith($node, self::ENTRY_POINT_PREFIXES);
    }

    private function isEntryPointClass(string $file): bool
    {
        return Str::contains(Fqcn::fromPath($file), self::ENTRY_POINT_NAMESPACES);
    }

    private function risk(int $impacted, int $entryPoints, bool $touchesEntryClass): RiskLevel
    {
        return match (true) {
            $entryPoints >= 3 || $impacted >= 20 => RiskLevel::High,
            $entryPoints >= 1 || $impacted >= 5 || $touchesEntryClass => RiskLevel::Medium,
            default => RiskLevel::Low,
        };
    }
}
