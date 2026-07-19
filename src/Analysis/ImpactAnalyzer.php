<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

use Illuminate\Support\Str;
use SanderMuller\Richter\Changes\ChangedFileSymbols;
use SanderMuller\Richter\Graph\CodeGraph;
use SanderMuller\Richter\Graph\NodeMetadata;
use SanderMuller\Richter\Support\Fqcn;

/**
 * Over a {@see CodeGraph}: impact(symbol) blast radius + detectChanges(files) reached entry points/risk.
 * Advisory only: risk is a coarse signal, not a gate. Node locations and Brain's per-route security
 * surface are carried through as annotation — they inform the reader, never the risk model.
 *
 * @phpstan-import-type SecurityShape from NodeMetadata
 */
final readonly class ImpactAnalyzer
{
    private const array ENTRY_POINT_PREFIXES = ['route::', 'command::', 'schedule::'];

    private const array ENTRY_POINT_NAMESPACES = ['\\Jobs\\', '\\Console\\Commands\\', '\\Listeners\\', '\\Livewire\\', '\\Filament\\', '\\Observers\\', '\\Http\\Middleware\\'];

    /**
     * Namespaces whose classes are user-facing UI surfaces the way a route is: a Livewire component
     * or Filament resource/page/widget reached UPSTREAM of a change is an entry point in its own
     * right — Blade-mounted components and Filament table/bulk actions have no `route::` node, so
     * without this they would read as plain callers. Deliberately narrower than
     * {@see ENTRY_POINT_NAMESPACES}: an upstream job or listener is reach toward its own dispatcher,
     * not a user surface.
     */
    private const array UI_COMPONENT_NAMESPACES = ['\\Livewire\\', '\\Filament\\'];

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
     *     callers: list<array{depth: int, node: string, via: string, file?: string, line?: int}>,
     *     dependencies: list<array{depth: int, node: string, via: string, file?: string, line?: int}>,
     * }
     */
    public function impact(string $symbol, int $maxDepth = 6): array
    {
        $seeds = $this->seedsFor($symbol);

        return [
            'target' => $symbol,
            'callers' => $this->withHopLocations($this->graph->callersOf($seeds, $maxDepth)),
            'dependencies' => $this->withHopLocations($this->graph->dependenciesOf($seeds, $maxDepth)),
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
     *     entryPointPaths: array<string, list<array{node: string, via: string, file?: string, line?: int}>>,
     *     entryPointLocations: array<string, array{file: string, line?: int}>,
     *     entryPointSecurity: array<string, SecurityShape>,
     *     entryPointGates: array<string, list<string>>,
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
        $frontendSeeds = [];
        $summary = [];
        $coverage = [];
        $touchesEntryClass = false;
        // Scoped to this single detectChanges() run (never an instance property — this class is
        // readonly) — see riskInputs()'s docblock.
        $riskInputsMemo = [];

        foreach ($changed as $file) {
            // Additive (new member) or cosmetic (whitespace/import-reorder) change has no existing callers — seeds nothing, raises no risk floor (even on jobs).
            if ($file->hasOnlyAdditiveOrCosmeticChanges()) {
                $summary[$file->file] = 0;
                $coverage[$file->file] = 'analyzed';

                continue;
            }

            // A frontend file (never `.php`; Blade is `.blade.php`) takes the annotation lane:
            // its routes join the entry-point list but never the walk seeds.
            if (! str_ends_with($file->file, '.php')) {
                [$frontendSeeds[$file->file], $summary[$file->file], $coverage[$file->file]] = $this->frontendLane($file);

                continue;
            }

            $fileSeeds = [];

            foreach ($file->resolvableMembers() as $member) {
                $fileSeeds = [...$fileSeeds, ...$this->memberSeeds($file->fqcn, $member->name)];
            }

            // A changed Blade view seeds its own node directly (no members to pin) — a precise seed,
            // so it raises no low-confidence flag; it resolves to nothing only when the view is
            // unreachable, which then reads as UNRESOLVED below, not "no impact". An entry-prefixed
            // direct seed (a route an inline `fetch()` calls) instead takes the same annotation
            // lane as frontend files: a touched surface, never a walk seed.
            $fileSeeds = [...$fileSeeds, ...$this->directWalkSeeds($file, $frontendSeeds)];

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

        // Paths exist only for graph-reached entry points — computed before the self-listing below,
        // so a self-listed entry class (which IS the entry surface, not reached from the change)
        // deliberately carries no chain.
        $entryPointPaths = $this->entryPointPathsFor($entryPoints, $callers, $seeds, $maxDepth);

        $entryPoints = $this->withSelfListedEntryClasses($entryPoints, $changed, $perFileSeeds, $maxDepth, $riskInputsMemo);
        $entryPoints = $this->withFrontendEntryPoints($entryPoints, $frontendSeeds);

        $coverage = $this->withUnresolvedJobFlips($coverage, $changed, $perFileSeeds, $maxDepth, $riskInputsMemo);

        // A node only reachable through `model-relationship` is context, not risk — counting it lets touching a hub model saturate to HIGH on relation breadth alone. Any behavioural edge still counts.
        $reach = $this->graph->reachedViaTypes($seeds, $maxDepth);
        $impacted = count(array_filter($reach, $this->isRiskBearing(...)));
        $relatedModels = array_keys(array_filter(
            $reach,
            fn (array $types): bool => ! $this->isRiskBearing($types) && isset($types['model-relationship']),
        ));

        [$risk, $coarseCapApplied] = $this->riskWithCoarseCap($impacted, $riskEntryPointCount, $touchesEntryClass, $preciseSeeds, $lowConfidence, $maxDepth, $riskInputsMemo);

        $findings = [];

        foreach ($changed as $file) {
            foreach ($file->findings as $finding) {
                $findings[] = "{$file->file}: {$finding}";
            }
        }

        [$entryPointLocations, $entryPointSecurity, $entryPointGates] = $this->entryPointAnnotations($entryPoints);

        return [
            'changed' => $summary,
            'coverage' => $coverage,
            'callers' => $callers,
            'dependencies' => $dependencies,
            'entryPoints' => $entryPoints,
            'entryPointPaths' => $entryPointPaths,
            'entryPointLocations' => $entryPointLocations,
            'entryPointSecurity' => $entryPointSecurity,
            'entryPointGates' => $entryPointGates,
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
     * @param  array<string, array{0: int, 1: int}>  $riskInputsMemo
     * @return array{0: RiskLevel, 1: bool}
     */
    private function riskWithCoarseCap(int $impacted, int $entryPoints, bool $touchesEntryClass, array $preciseSeeds, bool $lowConfidence, int $maxDepth, array &$riskInputsMemo): array
    {
        $risk = $this->risk($impacted, $entryPoints, $touchesEntryClass);

        if (! $lowConfidence || $risk !== RiskLevel::High) {
            return [$risk, false];
        }

        [$preciseEntryPoints, $preciseImpacted] = $this->riskInputs($preciseSeeds, $maxDepth, $riskInputsMemo);

        return $this->risk($preciseImpacted, $preciseEntryPoints, $touchesEntryClass) === RiskLevel::High
            ? [RiskLevel::High, false]
            : [RiskLevel::Medium, true];
    }

    /**
     * The annotation lane for a changed frontend file: its pre-mapped route seeds filtered to
     * exact graph membership — not {@see CodeGraph::nodesContaining()}, since a shorter route id
     * is a boundary-clean substring of a longer one. Unresolvable references, or mapped routes the
     * graph doesn't know, read "couldn't fully place this file", never a falsely-reassuring
     * "no impact".
     *
     * @return array{0: list<string>, 1: int, 2: 'analyzed'|'unresolved'}
     */
    private function frontendLane(ChangedFileSymbols $file): array
    {
        $resolved = array_values(array_filter($file->directSeeds, $this->graph->hasNode(...)));
        $unplaced = $file->unresolvedFrontendReferences || ($resolved === [] && $file->directSeeds !== []);

        return [$resolved, count($resolved), $unplaced ? 'unresolved' : 'analyzed'];
    }

    /**
     * A changed file's directSeeds, split by kind: entry-prefixed seeds (a route an inline `fetch()`
     * calls) are appended to `$frontendSeeds` by reference and never walked, matching frontendLane's
     * annotation-only treatment. A view node id (`view::`) is exact graph membership too — the same
     * reason as above: `components.card` is a boundary-clean substring of `components.card.header`,
     * and a sibling view that didn't change must never seed. An absent view node seeds nothing,
     * which reads UNRESOLVED at the caller, exactly as before. Everything else (a pure-rename's old
     * FQCN) falls through to substring matching via {@see seedsFor()} — intentional, so both the
     * class node and its member nodes seed.
     *
     * @param  array<string, list<string>>  $frontendSeeds
     * @return list<string>
     */
    private function directWalkSeeds(ChangedFileSymbols $file, array &$frontendSeeds): array
    {
        $fileSeeds = [];

        foreach ($file->directSeeds as $directSeed) {
            if (Str::startsWith($directSeed, self::ENTRY_POINT_PREFIXES)) {
                if ($this->graph->hasNode($directSeed)) {
                    $frontendSeeds[$file->file] = [...$frontendSeeds[$file->file] ?? [], $directSeed];
                }

                continue;
            }

            if (str_starts_with($directSeed, 'view::')) {
                if ($this->graph->hasNode($directSeed)) {
                    $fileSeeds[] = $directSeed;
                }

                continue;
            }

            $fileSeeds = [...$fileSeeds, ...$this->seedsFor($directSeed)];
        }

        return $fileSeeds;
    }

    /**
     * A changed listener/job/command/Livewire class IS an entry surface even when nothing app-side
     * calls it (a vendor-fired event, an unfollowable dispatch) — "Entry points reached: 0"
     * under-communicates "this runs on every SAML login". List the class itself, but only when its
     * own seeds reached no entry point (a job whose dispatcher resolved to a route needs no echo).
     * Excluded from the risk inputs: the risk floor for entry classes is touchesEntryClass, and
     * counting self-listings would rate touching three jobs as HIGH by count alone.
     *
     * @param  list<string>  $entryPoints
     * @param  list<ChangedFileSymbols>  $changed
     * @param  array<string, list<string>>  $perFileSeeds
     * @param  array<string, array{0: int, 1: int}>  $riskInputsMemo
     * @return list<string>
     */
    private function withSelfListedEntryClasses(array $entryPoints, array $changed, array $perFileSeeds, int $maxDepth, array &$riskInputsMemo): array
    {
        foreach ($changed as $file) {
            if ($file->hasOnlyAdditiveOrCosmeticChanges()) {
                continue;
            }

            if (! $this->isEntryPointClass($file->file)) {
                continue;
            }

            // Duplicate-append guard for two changed files of one class — graph entry points are
            // prefix-keyed and never collide with a bare FQCN.
            if (in_array($file->fqcn, $entryPoints, strict: true)) {
                continue;
            }

            [$ownEntryPoints] = $this->riskInputs($perFileSeeds[$file->file] ?? [], $maxDepth, $riskInputsMemo);

            if ($ownEntryPoints === 0) {
                $entryPoints[] = $file->fqcn;
            }
        }

        return $entryPoints;
    }

    /**
     * Frontend-referenced routes are entry surfaces the change touches directly — appended after
     * the risk inputs are frozen (like the self-listing) so they carry their annotations and feed
     * test selection without ever moving `risk`: the backend behaviour behind them did not change.
     *
     * @param  list<string>  $entryPoints
     * @param  array<string, list<string>>  $frontendSeeds
     * @return list<string>
     */
    private function withFrontendEntryPoints(array $entryPoints, array $frontendSeeds): array
    {
        foreach ($frontendSeeds as $nodes) {
            foreach ($nodes as $node) {
                if (! in_array($node, $entryPoints, strict: true)) {
                    $entryPoints[] = $node;
                }
            }
        }

        return $entryPoints;
    }

    /**
     * A changed job reaching no entry point of its own is genuinely-empty only if every dispatcher
     * resolved — an unfollowable dispatch means it could still be reached (UNRESOLVED). Decided per
     * job on its own seeds so a sibling can't mask it. Additive/cosmetic-only changes seeded
     * nothing on purpose (raise no floor, even on jobs) and are exempt from the flip.
     *
     * @param  array<string, 'analyzed'|'unresolved'>  $coverage
     * @param  list<ChangedFileSymbols>  $changed
     * @param  array<string, list<string>>  $perFileSeeds
     * @param  array<string, array{0: int, 1: int}>  $riskInputsMemo
     * @return array<string, 'analyzed'|'unresolved'>
     */
    private function withUnresolvedJobFlips(array $coverage, array $changed, array $perFileSeeds, int $maxDepth, array &$riskInputsMemo): array
    {
        if (! $this->graph->hasUnresolvedDispatches()) {
            return $coverage;
        }

        foreach ($changed as $file) {
            if ($file->hasOnlyAdditiveOrCosmeticChanges()) {
                continue;
            }

            if (($coverage[$file->file] ?? null) !== 'analyzed') {
                continue;
            }

            if (! Str::contains($file->fqcn, '\\Jobs\\')) {
                continue;
            }

            [$jobEntryPoints] = $this->riskInputs($perFileSeeds[$file->file] ?? [], $maxDepth, $riskInputsMemo);

            if ($jobEntryPoints === 0) {
                $coverage[$file->file] = 'unresolved';
            }
        }

        return $coverage;
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
     * Two graph walks (`callersOf` + `reachedViaTypes`) over one seed set — called once per
     * changed entry-point-class file and once per changed job file, so the same seed set (an
     * identical member change, or two files resolving to the same node) recurs often within one
     * {@see detectChanges()} run. `$memo` is a plain local array threaded by reference from
     * `detectChanges()` for that single run's lifetime (never an instance property: this class is
     * `readonly`, so an instance property could not be reassigned after construction — the memo has
     * to live in the call stack instead), keyed on maxDepth + the seed set sorted on a COPY so the
     * caller's array order is never disturbed.
     *
     * @param  list<string>  $seeds
     * @param  array<string, array{0: int, 1: int}>  $memo
     * @return array{0: int, 1: int} [entryPointCount, impactedCount]
     */
    private function riskInputs(array $seeds, int $maxDepth, array &$memo): array
    {
        if ($seeds === []) {
            return [0, 0];
        }

        $sortedSeeds = $seeds;
        sort($sortedSeeds);
        $key = $maxDepth . '|' . implode(',', $sortedSeeds);

        if (isset($memo[$key])) {
            return $memo[$key];
        }

        $entryPoints = $this->entryPointsAmong($this->graph->callersOf($seeds, $maxDepth));
        $impacted = count(array_filter($this->graph->reachedViaTypes($seeds, $maxDepth), $this->isRiskBearing(...)));

        return $memo[$key] = [count($entryPoints), $impacted];
    }

    /**
     * @param  list<array{depth: int, node: string, via: string}>  $callers
     * @return list<string>
     */
    private function entryPointsAmong(array $callers): array
    {
        $entryPoints = [];

        foreach ($callers as $hop) {
            if ($this->isEntryPointNode($hop['node'])) {
                $entryPoints[] = $hop['node'];

                continue;
            }

            $component = $this->uiComponentClassOf($hop['node']);

            if ($component !== null) {
                $entryPoints[] = $component;
            }
        }

        return array_values(array_unique($entryPoints));
    }

    /**
     * The shortest chain per reached entry point. A UI-component entry is class-normalised while
     * the walk reached its member, so the shallowest member's chain stands in when the class node
     * itself has none.
     *
     * @param  list<string>  $entryPoints
     * @param  list<array{depth: int, node: string, via: string}>  $callers
     * @param  list<string>  $seeds
     * @return array<string, list<array{node: string, via: string, file?: string, line?: int}>>
     */
    private function entryPointPathsFor(array $entryPoints, array $callers, array $seeds, int $maxDepth): array
    {
        $uiMemberByClass = $this->uiMembersAmong($callers);
        $rawPaths = $this->graph->callerPathsTo(
            $seeds,
            array_values(array_unique([...$entryPoints, ...array_values($uiMemberByClass)])),
            $maxDepth,
        );
        $paths = [];

        foreach ($entryPoints as $entryPoint) {
            $path = $rawPaths[$entryPoint] ?? $rawPaths[$uiMemberByClass[$entryPoint] ?? ''] ?? null;

            if ($path !== null) {
                $paths[$entryPoint] = $this->withPathLocations($path);
            }
        }

        return $paths;
    }

    /**
     * The first (shallowest, BFS order) reached member per UI-component class — the chain donor
     * for a class-normalised entry point whose class node the walk never visited directly.
     *
     * @param  list<array{depth: int, node: string, via: string}>  $callers
     * @return array<string, string>
     */
    private function uiMembersAmong(array $callers): array
    {
        $members = [];

        foreach ($callers as $hop) {
            $component = $this->uiComponentClassOf($hop['node']);

            if ($component !== null && $component !== $hop['node'] && ! isset($members[$component])) {
                $members[$component] = $hop['node'];
            }
        }

        return $members;
    }

    /**
     * The class of a caller inside a UI-component namespace ({@see UI_COMPONENT_NAMESPACES}), or
     * null. Represented class-level — `App\Livewire\Settings::save` and `::render` are one entry
     * surface, so members collapse onto the class and never double-count toward risk.
     */
    private function uiComponentClassOf(string $node): ?string
    {
        $class = explode('::', $node, 2)[0];

        if (preg_match('/^App\\\\[\w\\\\]+$/', $class) !== 1) {
            return null;
        }

        return Str::contains($class, self::UI_COMPONENT_NAMESPACES) ? $class : null;
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
     * Locations and security cover the FINAL entry-point list: a self-listed entry class gets its
     * defining file too (no chain, but still a place to click through to), and security exists only
     * for route nodes — Brain classifies nothing else.
     *
     * @param  list<string>  $entryPoints
     * @return array{0: array<string, array{file: string, line?: int}>, 1: array<string, SecurityShape>, 2: array<string, list<string>>}
     */
    private function entryPointAnnotations(array $entryPoints): array
    {
        $locations = [];
        $security = [];
        $gates = [];

        foreach ($entryPoints as $entryPoint) {
            $location = $this->graph->locationOf($entryPoint);

            if ($location !== null) {
                $locations[$entryPoint] = $location;
            }

            $surface = $this->graph->securityOf($entryPoint);

            if ($surface !== null) {
                $security[$entryPoint] = $surface;
            }

            $flags = $this->graph->gatesOf($entryPoint);

            if ($flags !== []) {
                $gates[$entryPoint] = $flags;
            }
        }

        return [$locations, $security, $gates];
    }

    /**
     * @param  list<array{depth: int, node: string, via: string}>  $hops
     * @return list<array{depth: int, node: string, via: string, file?: string, line?: int}>
     */
    private function withHopLocations(array $hops): array
    {
        return array_map(fn (array $hop): array => $hop + $this->locationExtras($hop['node']), $hops);
    }

    /**
     * @param  list<array{node: string, via: string}>  $path
     * @return list<array{node: string, via: string, file?: string, line?: int}>
     */
    private function withPathLocations(array $path): array
    {
        return array_map(fn (array $hop): array => $hop + $this->locationExtras($hop['node']), $path);
    }

    /**
     * The sparse location keys for a node — `[]` when the graph knows none, so `$hop + extras`
     * annotates without ever disturbing the hop's own shape.
     *
     * @return array{}|array{file: string, line?: int}
     */
    private function locationExtras(string $node): array
    {
        return $this->graph->locationOf($node) ?? [];
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
