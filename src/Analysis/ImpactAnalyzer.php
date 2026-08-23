<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

use Illuminate\Support\Str;
use InvalidArgumentException;
use SanderMuller\Richter\Changes\ChangedFileSymbols;
use SanderMuller\Richter\Graph\CodeGraph;
use SanderMuller\Richter\Graph\NodeMetadata;
use SanderMuller\Richter\Support\AppNamespace;
use SanderMuller\Richter\Support\AssociationReasons;
use SanderMuller\Richter\Support\Fqcn;
use SanderMuller\Richter\Support\ReachReasons;

/**
 * Over a {@see CodeGraph}: impact(symbol) blast radius + detectChanges(files) reached entry points/risk.
 * Advisory by default: the level is a signal, not a verdict, and only a `--fail-on*` flag makes it
 * gate. It is decided by the HAZARD a change carries and, failing that, by whether a test references
 * what it reaches — never by how many nodes it touches. Node locations stay pure annotation; Brain's
 * per-route security surface feeds a hazard's reach class ({@see HazardReach}).
 *
 * @phpstan-import-type SecurityShape from NodeMetadata
 */
final readonly class ImpactAnalyzer
{
    /** @internal the prefix vocabulary {@see isEntryPointNode()} matches — shared with richter's own composition (e.g. the MCP entry-points resource's kind labels), not a consumer API */
    public const array ENTRY_POINT_PREFIXES = ['route::', 'command::', 'schedule::'];

    private const array ENTRY_POINT_NAMESPACES = ['\\Jobs\\', '\\Console\\Commands\\', '\\Listeners\\', '\\Livewire\\', '\\Filament\\', '\\Nova\\', '\\Observers\\', '\\Http\\Middleware\\'];

    /**
     * Namespaces whose classes are user-facing UI surfaces the way a route is: a Livewire component,
     * or an admin-panel resource/page/widget, reached UPSTREAM of a change is an entry point in its
     * own right — a Blade-mounted component, a Filament table action, a Nova resource field all have
     * no `route::` node of their own, so without this they would read as plain callers. Deliberately
     * narrower than {@see ENTRY_POINT_NAMESPACES}: an upstream job or listener is reach toward its own
     * dispatcher, not a user surface.
     *
     * Not gated on the panel package being installed, for the same reason Filament is not: this is a
     * substring test on an application's own `App\\Nova\\` namespace, and a project that names a
     * namespace after an admin panel it does not use is not a case worth carrying a runtime check for.
     */
    private const array UI_COMPONENT_NAMESPACES = ['\\Livewire\\', '\\Filament\\', '\\Nova\\'];

    /**
     * Edge types that associate rather than invoke — reach through them is not risk. `uses-trait`
     * is excluded deliberately: a hub trait with dozens of using classes would otherwise
     * saturate the impacted count for any one-method change — the classic over-reporting shape.
     * `override` (CHA, plan cha-risk) is excluded for the same reason: it is an over-approximated
     * ancestor→concrete link (an interface with many implementors fans out widely), so it carries
     * reachability but must not inflate the risk level.
     * `model-to-policy` (Brain, v2.4.0) says which policy governs a model — a governs-relation, not
     * a call. Changing a policy does not break the model, so the model must not count; excluding it
     * also keeps the risk level stable across the v2.4.0 bump, since before it the edge did not
     * exist. Note the shared limit of every entry here: exclusion is judged per reached node from
     * the edge it arrived by, so a node BEHIND an excluded one still counts on its own edge type —
     * a route reaching a governed model by `action-to-model` is impact, even though the policy that
     * governs the model is not. Propagating association-only status along a whole branch would
     * change the risk level for every existing app and belongs behind the benchmark corpus.
     * `config-registry` (0.25) is the same over-approximation as `override`, in a different shape: a
     * `config('calculators…')` lookup can return any class the registry names, so the edge fans out to
     * all of them. Counting that would let one edit to the resolver saturate the level on breadth,
     * while the reach it carries — which is what gives a registry-dispatched class any caller at all
     * — is exactly what must keep flowing.
     * Reach, coverage, and entry-point discovery still flow through these edges.
     */
    public const array RISK_EXCLUDED_EDGE_TYPES = ['model-relationship', 'declares', 'uses-trait', 'override', 'model-to-policy', 'config-registry', 'config-registry-fanout'];

    /**
     * Edges that carry CONTEXT rather than a call, for the purpose of finding entry points.
     *
     * A strict subset of {@see RISK_EXCLUDED_EDGE_TYPES}, and the difference matters. `override` and
     * an ENUMERATED `config-registry` read are over-approximated CALLS — the dispatch is real, only
     * the target is uncertain — so an entry point behind one genuinely runs the changed code and
     * belongs in the list. A model relation and a model→policy link are not calls in any direction:
     * they say two things are associated, and walking them to an entry point invents a caller.
     *
     * `config-registry-fanout` sits with them: a registry read whose key cannot be enumerated links
     * every class its config file names, so the surfaces behind it are identical for every one of
     * those classes and cannot tell one change from another.
     *
     * `declares` and `uses-trait` stay traversable here on purpose: `declares` is how a changed
     * member reaches its own class node (removing it would cut real reach at the first hop), and a
     * trait's users do run its code.
     *
     * @var list<string>
     */
    public const array ASSOCIATION_EDGE_TYPES = ['model-relationship', 'model-to-policy', 'config-registry-fanout'];

    public function __construct(private CodeGraph $graph) {}

    /**
     * The entry-point annotations reuse {@see detectChanges()}'s own composition — same definition,
     * same routes-only security surface. `impact()` analyses a symbol rather than a diff, so it has no
     * base side, carries no hazards, and reports no level.
     *
     * @return array{
     *     target: string,
     *     callers: list<array{depth: int, node: string, via: string, file?: string, line?: int}>,
     *     dependencies: list<array{depth: int, node: string, via: string, file?: string, line?: int}>,
     *     entryPoints: list<string>,
     *     associationEntryPoints: list<string>,
     *     associationEntryPointsVia: array<string, list<string>>,
     *     entryPointPaths: array<string, list<array{node: string, via: string, file?: string, line?: int}>>,
     *     entryPointLocations: array<string, array{file: string, line?: int}>,
     *     entryPointSecurity: array<string, SecurityShape>,
     *     entryPointGates: array<string, list<string>>,
     *     entryPointAuthGates: array<string, list<string>>,
     *     entryPointAuthMiddleware: array<string, list<string>>,
     *     suggestions: list<string>,
     *     graphNodeCount: int,
     * }
     */
    public function impact(string $symbol, int $maxDepth = 6): array
    {
        $seeds = $this->seedsFor($symbol);
        $callers = $this->withHopLocations($this->graph->callersOf($seeds, $maxDepth));

        // Same split as detectChanges(): a surface reached only through a model relation is context,
        // not something that calls the symbol, and mixing the two makes a long list unreadable.
        $entryPoints = $this->entryPointsAmong($this->graph->callersOf($seeds, $maxDepth, self::ASSOCIATION_EDGE_TYPES));
        $associationEntryPoints = array_values(array_diff($this->entryPointsAmong($callers), $entryPoints));
        [$entryPointLocations, $entryPointSecurity, $entryPointGates] = $this->entryPointAnnotations($entryPoints);
        $crossCheck = new PublicWriteAuthCrossCheck($this->graph);

        return [
            'target' => $symbol,
            'callers' => $callers,
            'dependencies' => $this->withHopLocations($this->graph->dependenciesOf($seeds, $maxDepth)),
            'entryPoints' => $entryPoints,
            'associationEntryPoints' => $associationEntryPoints,
            'associationEntryPointsVia' => $this->associationReasons($associationEntryPoints, $seeds, $maxDepth, $callers),
            'entryPointPaths' => $this->entryPointPathsFor($entryPoints, $callers, $seeds, $maxDepth),
            'entryPointLocations' => $entryPointLocations,
            'entryPointSecurity' => $entryPointSecurity,
            'entryPointGates' => $entryPointGates,
            'entryPointAuthGates' => $crossCheck->gatesByEntryPoint($entryPointSecurity, $maxDepth),
            'entryPointAuthMiddleware' => $crossCheck->authMiddlewareByEntryPoint($entryPointSecurity),
            // Only on a miss: a hit needs no lead, and the token scan is not free.
            'suggestions' => $seeds === [] ? $this->graph->nearestNodes($symbol) : [],
            'graphNodeCount' => $this->graph->nodeCount(),
        ];
    }

    /**
     * Shortest directed path from `$from` to `$to` in call direction — "does FROM reach
     * TO, and through which chain?". Delegates to the {@see SymbolTracer} beside-class
     * (complexity budget); see there for the strict-direction and `furthestReached`
     * semantics.
     *
     * @return array{from: string, to: string, resolvedFrom: list<string>, resolvedTo: list<string>, found: bool, path: list<array{node: string, via: string, file?: string, line?: int}>, furthestReached?: array{node: string, depth: int, file?: string, line?: int}}
     *
     * @throws InvalidArgumentException when either symbol matches no graph node
     */
    public function trace(string $from, string $to, int $maxDepth = 6): array
    {
        return new SymbolTracer($this->graph)->trace($from, $to, $maxDepth);
    }

    /**
     * The zero result for an empty diff, in {@see detectChanges()}'s own shape — so a renderer can
     * draw "nothing changed" without a graph build. {@see JsonPresenter::emptyDetectChanges()} is
     * the separate JSON-shaped equivalent; the two differ in `risk` (enum here, string there).
     *
     * @return array{changed: array<string, int>, coverage: array<string, 'analyzed'|'unresolved'>, newFiles: list<string>, fqcns: array<string, string>, callers: list<array{depth: int, node: string, via: string}>, dependencies: list<array{depth: int, node: string, via: string}>, seeds: list<string>, reach: array<string, array<string, true>>, edges: list<array{source: string, target: string, via: string, depth: int}>, entryPoints: list<string>, associationEntryPoints: list<string>, associationEntryPointsVia: array<string, list<string>>, registryEntryPoints: list<string>, entryPointPaths: array<string, list<array{node: string, via: string, file?: string, line?: int}>>, entryPointLocations: array<string, array{file: string, line?: int}>, entryPointSecurity: array<string, SecurityShape>, entryPointGates: array<string, list<string>>, entryPointAuthGates: array<string, list<string>>, entryPointAuthMiddleware: array<string, list<string>>, impacted: int, relatedModels: list<string>, traitAndOverrideReach: list<string>, traitAndOverrideReachVia: array<string, list<string>>, risk: RiskLevel, riskCause: string, hazards: list<Hazard>, verification: array<string, bool|null>, lowConfidence: bool, findings: list<string>}
     */
    public static function emptyDetectChanges(): array
    {
        return [
            'changed' => [], 'coverage' => [], 'newFiles' => [], 'fqcns' => [], 'callers' => [], 'dependencies' => [],
            'seeds' => [], 'reach' => [], 'edges' => [], 'entryPoints' => [], 'associationEntryPoints' => [], 'associationEntryPointsVia' => [], 'entryPointPaths' => [],
            'entryPointLocations' => [], 'entryPointSecurity' => [], 'entryPointGates' => [],
            'entryPointAuthGates' => [], 'entryPointAuthMiddleware' => [],
            'impacted' => 0, 'relatedModels' => [], 'registryEntryPoints' => [], 'traitAndOverrideReach' => [], 'traitAndOverrideReachVia' => [], 'risk' => RiskLevel::Low,
            // Ladder step 0: nothing was analysed, which is not the same fact as "analysed and could
            // not be placed". An empty diff has always reported LOW and still does.
            'riskCause' => 'no analysable change: this diff touches nothing richter analyses',
            'hazards' => [], 'verification' => [],
            'lowConfidence' => false, 'findings' => [],
        ];
    }

    /**
     * @param  list<ChangedFileSymbols>  $changed  the member-level change set per file (see ChangedSymbols)
     *
     * @param  bool|null  $payloadParityEnabled  overrides `richter.payload_parity.enabled` (e.g. the
     *   command's `--no-payload-parity` flag); null defers to config
     * @param  TestReferenceIndex|null  $tests  the level's verification input. Absent behaves exactly
     *   as an empty index does — every surface grades unreferenced — rather than as a fourth state:
     *   "could not check" must never open the LOW path.
     * @param  bool|null  $hazardsEnabled  overrides `richter.hazards.enabled` (the command's
     *   `--no-hazards`); null defers to config
     * @return array{
     *     changed: array<string, int>,
     *     coverage: array<string, 'analyzed'|'unresolved'>,
     *     newFiles: list<string>,
     *     fqcns: array<string, string>,
     *     callers: list<array{depth: int, node: string, via: string}>,
     *     dependencies: list<array{depth: int, node: string, via: string}>,
     *     seeds: list<string>,
     *     reach: array<string, array<string, true>>,
     *     edges: list<array{source: string, target: string, via: string, depth: int}>,
     *     entryPoints: list<string>,
     *     associationEntryPoints: list<string>,
     *     associationEntryPointsVia: array<string, list<string>>,
     *     entryPointPaths: array<string, list<array{node: string, via: string, file?: string, line?: int}>>,
     *     entryPointLocations: array<string, array{file: string, line?: int}>,
     *     entryPointSecurity: array<string, SecurityShape>,
     *     entryPointGates: array<string, list<string>>,
     *     entryPointAuthGates: array<string, list<string>>,
     *     entryPointAuthMiddleware: array<string, list<string>>,
     *     impacted: int,
     *     relatedModels: list<string>,
     *     registryEntryPoints: list<string>,
     *     traitAndOverrideReach: list<string>,
     *     traitAndOverrideReachVia: array<string, list<string>>,
     *     risk: RiskLevel,
     *     riskCause: string,
     *     hazards: list<Hazard>,
     *     verification: array<string, bool|null>,
     *     lowConfidence: bool,
     *     findings: list<string>,
     * }
     */
    public function detectChanges(array $changed, int $maxDepth = 6, ?bool $payloadParityEnabled = null, ?TestReferenceIndex $tests = null, ?bool $hazardsEnabled = null): array
    {
        $preciseSeeds = [];
        $coarseSeeds = [];
        $coarseDependencySeeds = [];
        $perFileSeeds = [];
        $frontendSeeds = [];
        $summary = [];
        $coverage = [];
        /** @var list<string> $newFileFindings */
        $newFileFindings = [];
        $touchesEntryClass = false;
        // Scoped to this single detectChanges() run — see riskInputs()'s docblock.
        $riskInputsMemo = [];

        // Derived up front, not accumulated in the loop below: the loop returns early for the additive
        // and frontend lanes, which would silently drop those files from both maps.
        $fqcns = array_column(
            array_map(static fn (ChangedFileSymbols $file): array => ['file' => $file->file, 'fqcn' => $file->fqcn], $changed),
            'fqcn',
            'file',
        );

        $newFiles = array_values(array_map(
            static fn (ChangedFileSymbols $file): string => $file->file,
            array_filter($changed, static fn (ChangedFileSymbols $file): bool => $file->isNewFile),
        ));

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

            ['precise' => $precise, 'coarse' => $coarse, 'coarseDependencies' => $coarseDependencies, 'declared' => $declared] = $this->seedsForChangedFile($file, $frontendSeeds);

            $preciseSeeds = [...$preciseSeeds, ...$precise];
            $coarseSeeds = [...$coarseSeeds, ...$coarse];
            $coarseDependencySeeds = [...$coarseDependencySeeds, ...$coarseDependencies];
            $fileSeeds = [...$precise, ...$coarse];

            // Only a real change to an uncharted entry-point class keeps the MEDIUM floor; the additive/cosmetic case returned LOW above.
            if ($this->isEntryPointClass($file->file)) {
                $touchesEntryClass = true;
            }

            $fileSeeds = array_values(array_unique($fileSeeds));
            $perFileSeeds[$file->file] = $fileSeeds;
            $summary[$file->file] = count($fileSeeds) + count($declared);
            // A non-additive change that resolves to no graph node at all can't be placed — that reads
            // "couldn't determine", never a falsely-reassuring "no impact". A change that does resolve
            // to a node but reaches nothing is a real leaf and stays "analyzed". A NEW file is the one
            // exception: no node means nothing references the class yet, which is a determined answer
            // (and a new class cannot break an existing caller), so it stays "analyzed" with a finding
            // rather than failing every --fail-on-unresolved build that adds a not-yet-wired class.
            $coverage[$file->file] = $fileSeeds === [] && $declared === [] && ! $file->isNewFile ? 'unresolved' : 'analyzed';

            // `$declared` guards the same way it does for coverage: a new routes file declaring a
            // dozen routes IS placed, so "no traced edge reaches it" would be a false statement.
            if ($fileSeeds === [] && $declared === [] && $file->isNewFile) {
                $newFileFindings[] = "{$file->file} is new and no traced edge reaches it — either nothing calls it yet, or the call shape is one richter does not trace";
            }
        }

        $preciseSeeds = array_values(array_unique($preciseSeeds));
        $coarseSeeds = array_values(array_unique($coarseSeeds));
        $seeds = array_values(array_unique([...$preciseSeeds, ...$coarseSeeds]));
        // The dependency walk drops a coarse seed's bare CLASS node — see seedsForChangedFile(). The
        // caller walk keeps it, because "who uses this class" is the only reach a change that pins to
        // no member has.
        $dependencySeeds = array_values(array_unique([...$preciseSeeds, ...$coarseDependencySeeds]));
        // Low confidence only when a coarse seed actually resolved to a node — a coarse change to a class absent from the graph seeds nothing.
        $lowConfidence = $coarseSeeds !== [];

        $callers = $this->graph->callersOf($seeds, $maxDepth);
        $dependencies = $this->graph->dependenciesOf($dependencySeeds, $maxDepth);
        // The same two walks kept as edges rather than a flat hop list, for consumers that draw the
        // reached region. Merged, not deduplicated: the two walks keep independent seen-sets, so a
        // node reached both ways appears twice at possibly different depths. Collapsing it to the
        // minimum is a presentation decision, left to the renderer.
        $edges = [...$this->graph->callerEdgesOf($seeds, $maxDepth), ...$this->graph->dependencyEdgesOf($dependencySeeds, $maxDepth)];

        // Two walks, because "reached" is two different things. The full walk answers what the
        // change touches; the call-only walk answers what CALLS it. An entry point that exists only
        // because an Eloquent relation happens to chain to it is the first and not the second, and
        // listing it beside real callers is the most misleading line this report can print — an
        // admin resource named as a reached surface while the routes that actually run the changed
        // code report no path at all. The impacted count already draws this distinction (see
        // `relatedModels` below); the entry-point list now draws it too.
        $entryPoints = $this->entryPointsAmong($this->graph->callersOf($seeds, $maxDepth, self::ASSOCIATION_EDGE_TYPES));
        $associationEntryPoints = array_values(array_diff($this->entryPointsAmong($callers), $entryPoints));
        // A registry fan-out is context in the REPORT — it names every class its config file lists,
        // so it cannot tell one of them from another — but the dispatch behind it is real, and a
        // test that drives such a route does exercise the change. Carried separately so
        // {@see AffectedTests} can select over it without the entry-point list claiming it as a
        // caller. Internal, like the walk lists above: no output format reads it.
        $registryEntryPoints = array_values(array_diff(
            $this->entryPointsAmong($this->graph->callersOf($seeds, $maxDepth, ['model-relationship', 'model-to-policy'])),
            $entryPoints,
        ));
        // The set the LEVEL grades, frozen before the two joins below. A frontend file's routes and a
        // self-listed entry class are appended to the LIST afterwards so they carry their annotations
        // and feed test selection, and neither has ever fed the level: `withFrontendEntryPoints()` and
        // `config/richter.php`'s frontend note both promise that a frontend change does not move it.
        $reachedEntryPoints = $entryPoints;

        // Paths exist only for graph-reached entry points — computed before the self-listing below,
        // so a self-listed entry class (which IS the entry surface, not reached from the change)
        // deliberately carries no chain.
        $entryPointPaths = $this->entryPointPathsFor($entryPoints, $callers, $seeds, $maxDepth);

        $entryPoints = $this->withSelfListedEntryClasses($entryPoints, $changed, $perFileSeeds, $maxDepth, $riskInputsMemo);
        $entryPoints = $this->withFrontendEntryPoints($entryPoints, $frontendSeeds);

        $coverage = $this->withUnresolvedJobFlips($coverage, $changed, $perFileSeeds, $maxDepth, $riskInputsMemo);

        // A node only reachable through `model-relationship` is context, not risk — counting it lets touching a hub model saturate to HIGH on relation breadth alone. Any behavioural edge still counts.
        $reach = $this->graph->reachedViaTypes($seeds, $maxDepth, $dependencySeeds);
        $impacted = count(array_filter($reach, $this->isRiskBearing(...)));
        $relatedModels = $this->uncountedReachVia($reach, ['model-relationship']);
        // Classes that RUN the changed member without calling it: they use the trait declaring it, or
        // they implement the ancestor it overrides. Excluded from the count for the saturation reason
        // in {@see RISK_EXCLUDED_EDGE_TYPES} — fifty using classes must not decide a risk level — but
        // excluding them from the COUNT and excluding them from the REPORT are different decisions,
        // and only the first one is about saturation. Without this, a one-method change to a hub trait
        // printed `0 entry points, 0 impacted, LOW`: byte-identical to a change that does nothing,
        // while `richter:impact` on the same member listed every user. Shown, never counted — the same
        // bargain {@see $relatedModels} already strikes for association reach.
        $traitAndOverrideReach = $this->uncountedReachVia($reach, ['uses-trait', 'override']);
        // Sorted at the source rather than per format: the JSON list, the via map's key order and
        // every rendered list then read the same way round.
        sort($traitAndOverrideReach);
        // Why each of them is listed. Without it the section names classes and gives no way to tell a
        // trait user from an override implementor — the reader is left grepping the source to
        // classify what the walk already knew.
        $traitAndOverrideReachVia = ReachReasons::forNodes($traitAndOverrideReach, $reach, ['uses-trait', 'override']);

        $findings = $newFileFindings;
        [$modelParityLane, $consumerParityLane, $requestParityLane] = ParityFindings::checkers($this->graph, $payloadParityEnabled);
        $groupLane = new MiddlewareGroupFindings($this->graph);
        // The parity family's results are tier-2 hazards now, not findings. They join the lanes'
        // output before the reach class is attached, so every hazard is graded the same way.
        $parityHazards = [];

        foreach ($changed as $file) {
            foreach ($file->findings as $finding) {
                $findings[] = "{$file->file}: {$finding}";
            }

            $parityHazards = [...$parityHazards, ...ParityFindings::for($file, $modelParityLane, $consumerParityLane, $requestParityLane)];
            $findings = [...$findings, ...$groupLane->findingsFor($file->fqcn)];
        }

        [$entryPointLocations, $entryPointSecurity, $entryPointGates] = $this->entryPointAnnotations($entryPoints);
        $crossCheck = new PublicWriteAuthCrossCheck($this->graph);
        $entryPointAuthGates = $crossCheck->gatesByEntryPoint($entryPointSecurity, $maxDepth);
        $entryPointAuthMiddleware = $crossCheck->authMiddlewareByEntryPoint($entryPointSecurity);

        // The level is decided LAST, because it needs all three of the above: the hazards from the
        // findings pass, the reach class from the security annotations, and the entry-point set the
        // verification lane grades. The old breadth score could run early precisely because it needed
        // none of them — it only counted.
        $hazards = new HazardReach($this->graph, $entryPointPaths, $entryPointSecurity, $entryPointAuthGates, $entryPointAuthMiddleware, $maxDepth, $this->entryPointsAmong(...))
            ->attach(HazardFindings::for($changed, $hazardsEnabled, $parityHazards));

        // Runs on what survived suppression, so a silenced column costs no lookup. Evidence only: it
        // names what still refers to a dropped column and moves neither tier nor reach.
        $hazards = new ColumnReferences($this->graph)->attach($hazards);

        $graded = new VerificationSet($reachedEntryPoints, $changed, $perFileSeeds);
        $members = $graded->members(fn (array $seeds): int => $this->riskInputs($seeds, $maxDepth, $riskInputsMemo)[0]);
        [$risk, $riskCause, $verification] = RiskLadder::decide($hazards, $graded->analysesExistingCode(), $members, $tests);

        return [
            'changed' => $summary,
            'coverage' => $coverage,
            'newFiles' => $newFiles,
            'fqcns' => $fqcns,
            'callers' => $callers,
            'dependencies' => $dependencies,
            // Internal, like callers/dependencies above: consumed by renderers, never by
            // JsonPresenter — the machine contract deliberately carries no walk internals.
            'seeds' => $seeds,
            'reach' => $reach,
            'edges' => $edges,
            'entryPoints' => $entryPoints,
            'associationEntryPoints' => $associationEntryPoints,
            'associationEntryPointsVia' => $this->associationReasons($associationEntryPoints, $seeds, $maxDepth, $callers),
            'registryEntryPoints' => $registryEntryPoints,
            'entryPointPaths' => $entryPointPaths,
            'entryPointLocations' => $entryPointLocations,
            'entryPointSecurity' => $entryPointSecurity,
            'entryPointGates' => $entryPointGates,
            'entryPointAuthGates' => $entryPointAuthGates,
            'entryPointAuthMiddleware' => $entryPointAuthMiddleware,
            'impacted' => $impacted,
            'relatedModels' => $this->readableModelLabels($relatedModels),
            'traitAndOverrideReach' => $traitAndOverrideReach,
            'traitAndOverrideReachVia' => $traitAndOverrideReachVia,
            'risk' => $risk,
            'riskCause' => $riskCause,
            'hazards' => $hazards,
            'verification' => $verification,
            'lowConfidence' => $lowConfidence,
            'findings' => $findings,
        ];
    }

    /**
     * One changed PHP file's walk seeds, split by confidence — the whole per-file seeding decision, so
     * {@see detectChanges()} reads as bookkeeping around it rather than carrying four nested branches.
     *
     * `precise` pins the change as exactly as the graph allows: the changed members, a changed Blade
     * view's own node, and — for a brand-new file — its class node. A new file's members all read
     * CHANGE_ADDED (there is no base side to diff them against), so `memberSeeds()` yields nothing, yet
     * the changed unit is the whole class, which can be an entry surface itself and can reach existing
     * code. Class-level there is the exact granularity of a new class, not a fallback for a member the
     * graph couldn't resolve — hence precise, raising no low-confidence flag and never arming the
     * coarse HIGH cap.
     *
     * `coarse` is the fallback for a non-resolvable change (enum case body, constant value,
     * `$fillable`/`casts()`, a class modifier) that cannot pin to a member node: the class stands in,
     * its HIGH is untrustworthy (the cap catches it), so it is tracked apart. It can never coincide
     * with the new-file seed — that requires a non-additive member a new file cannot have.
     *
     * `$frontendSeeds` is threaded by reference for the annotation lane: an entry-prefixed direct seed
     * (a route an inline `fetch()` calls) is a touched surface, never a walk seed.
     *
     * `coarseDependencies` is the same coarse set with the bare CLASS node withheld — the half the
     * dependency walk gets. A coarse seed is a class standing in for a member the graph could not pin,
     * and walking that class node downstream leaves the change entirely: `implements` reaches an app
     * interface, `declares` reaches its method, and CHA's `override` edges reach every implementor in
     * the application. Upstream the same node answers the question the coarse lane exists for — who
     * uses this class — so it stays a caller seed and is dropped only here. A class with no member
     * nodes keeps it in both, since withholding it would leave that change unseeded.
     *
     * `declared` is the same idea reached the other way: surfaces the file itself defines, filled
     * only by the last-resort lane in {@see definedNodes()}. They place the file — so coverage reads
     * `analyzed` — without ever being walked.
     *
     * @param  array<string, list<string>>  $frontendSeeds
     * @return array{precise: list<string>, coarse: list<string>, coarseDependencies: list<string>, declared: list<string>}
     */
    private function seedsForChangedFile(ChangedFileSymbols $file, array &$frontendSeeds): array
    {
        $precise = [];

        foreach ($file->resolvableMembers() as $member) {
            $precise = [...$precise, ...$this->memberSeeds($file->fqcn, $member->name)];
        }

        $precise = [...$precise, ...$this->directWalkSeeds($file, $frontendSeeds)];

        if ($file->isNewFile) {
            $precise = [...$precise, ...$this->seedsFor($file->fqcn)];
        }

        $coarse = $file->needsCoarseSeed() ? $this->seedsFor($file->fqcn) : [];

        if ($precise !== [] || $coarse !== []) {
            return ['precise' => $precise, 'coarse' => $coarse, 'coarseDependencies' => Fqcn::memberNodesOf($coarse, $file->fqcn), 'declared' => []];
        }

        ['seeds' => $seeds, 'declared' => $declared] = $this->definedNodes($file, $frontendSeeds);

        return ['precise' => $seeds, 'coarse' => [], 'coarseDependencies' => [], 'declared' => $declared];
    }

    /**
     * Last resort before a file reads UNRESOLVED: the nodes the graph says this very file defines
     * ({@see CodeGraph::nodesDefinedIn()}), split by what they mean for the change.
     *
     * Gated by the caller on every other lane coming up empty, deliberately. A controller's class
     * file defines its `controller::`/`action::` nodes too, so running this lane unconditionally
     * would re-seed the whole class on a one-method change and undo the member-level precision the
     * lanes above exist for.
     *
     * The split is the load-bearing part. An entry-prefixed node is a surface the file *declares* —
     * a `$commands` entry, a `schedule()` call, a route definition — never something the change
     * calls into, so it is annotated like a frontend-referenced route: appended to the entry-point
     * list after the risk inputs freeze, and kept out of the walk entirely. Seeding those would rate
     * a one-line registry edit by how many surfaces happen to share the file: adding a command to a
     * legacy Console Kernel walked all ten of its siblings and every schedule, reaching 211 nodes
     * and reporting HIGH — enough to fail a `--fail-on=high` gate over an edit that cannot break any
     * of them. Everything else the file defines (a `middleware::` node IS the changed class) stays a
     * walk seed.
     *
     * @param  array<string, list<string>>  $frontendSeeds
     * @return array{seeds: list<string>, declared: list<string>}
     */
    private function definedNodes(ChangedFileSymbols $file, array &$frontendSeeds): array
    {
        $defined = $this->graph->nodesDefinedIn($file->file);
        $declared = array_values(array_filter($defined, static fn (string $node): bool => Str::startsWith($node, self::ENTRY_POINT_PREFIXES)));

        $frontendSeeds[$file->file] = [...$frontendSeeds[$file->file] ?? [], ...$declared];

        return ['seeds' => array_values(array_diff($defined, $declared)), 'declared' => $declared];
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
        // Preserve this flip's original trigger across plan 036's S1/S2 split: a changed job's
        // dispatcher could live in an unparseable file (S1) just as easily as in an unfollowable
        // dispatch (S2), so proceed when EITHER source is present.
        if (! $this->graph->hasUnparseableFiles() && ! $this->graph->hasUnresolvedDispatches()) {
            return $coverage;
        }

        foreach ($changed as $file) {
            if ($this->jobCoverageIsUnresolvable($file, $coverage[$file->file] ?? null, $perFileSeeds[$file->file] ?? [], $maxDepth, $riskInputsMemo)) {
                $coverage[$file->file] = 'unresolved';
            }
        }

        return $coverage;
    }

    /**
     * Whether one changed file is the shape {@see withUnresolvedJobFlips()} flips: a real (non-additive)
     * change to a job that currently reads `analyzed` yet reaches no entry point of its own. Its own
     * seeds decide, so a sibling change's reach can never mask it.
     *
     * @param  'analyzed'|'unresolved'|null  $coverage
     * @param  list<string>  $fileSeeds
     * @param  array<string, array{0: int, 1: int}>  $riskInputsMemo
     */
    private function jobCoverageIsUnresolvable(ChangedFileSymbols $file, ?string $coverage, array $fileSeeds, int $maxDepth, array &$riskInputsMemo): bool
    {
        if ($file->hasOnlyAdditiveOrCosmeticChanges() || $coverage !== 'analyzed') {
            return false;
        }

        if (! Str::contains($file->fqcn, '\\Jobs\\')) {
            return false;
        }

        [$jobEntryPoints] = $this->riskInputs($fileSeeds, $maxDepth, $riskInputsMemo);

        return $jobEntryPoints === 0;
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
            // basename. Two-plus candidates (App\Models\Tag vs App\Models\Category\Tag) are
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
     * The entry-point walk excludes {@see ASSOCIATION_EDGE_TYPES}, exactly as the reported list does.
     * Every caller here asks a question about callers, and a surface reached only through a model
     * relation answers none of them: counting it let association reach hold a coarse change at HIGH,
     * on the one path the split that removed it elsewhere did not cover.
     *
     * The edge-type exclusion is fixed rather than a parameter — the memo is keyed on maxDepth and
     * the seed set, so two callers asking with different exclusions would alias onto one entry.
     * `$alsoChanged` is parameterised, so it is folded into that key for the same reason.
     *
     * One seed set, both directions — unlike {@see detectChanges()}, which walks a coarse class node
     * upstream only. The single call site that consumes the impacted half is the coarse-cap rescore,
     * and it walks PRECISE seeds, which are symmetric by construction: member nodes for an ordinary
     * change, and a new file's class node that belongs in both walks. The other two call sites read
     * the entry-point half and discard the count. So no asymmetric set reaches here, and a parameter
     * for one would only add a memo-key branch nothing exercises.
     *
     * `$alsoChanged` is the rest of the change: nodes a caller scores a SUBSET of. A walk never
     * reports its own seeds as reached ({@see CodeGraph::reachedViaTypes()} unsets them; the BFS
     * marks them seen at depth 0), so without this the dropped nodes stop being seeds and read as
     * reached — an artifact of scoring, not reach, and how a scored count exceeded a printed one.
     *
     * @param  list<string>  $seeds
     * @param  array<string, array{0: int, 1: int}>  $memo
     * @param  list<string>  $alsoChanged  changed nodes outside `$seeds`, never counted as reached
     * @return array{0: int, 1: int} [entryPointCount, impactedCount]
     */
    private function riskInputs(array $seeds, int $maxDepth, array &$memo, array $alsoChanged = []): array
    {
        if ($seeds === []) {
            return [0, 0];
        }

        $sortedSeeds = $seeds;
        sort($sortedSeeds);
        $sortedAlsoChanged = $alsoChanged;
        sort($sortedAlsoChanged);
        // NUL-joined: no node-id shape carries a NUL byte, so two distinct sets can never alias one
        // key (a comma could, in principle, appear inside a future node id). The seed count pins
        // where one set ends and the other begins — a delimiter alone would not, since an empty id
        // in either set can shift the boundary and produce a colliding string.
        $key = implode("\0", [$maxDepth, count($sortedSeeds), ...$sortedSeeds, ...$sortedAlsoChanged]);

        if (isset($memo[$key])) {
            return $memo[$key];
        }

        $changed = array_fill_keys($alsoChanged, true);

        // Filtered on the hop, before the UI-component collapse, so this mirrors the full walk
        // exactly: there too a suppressed class node still reaches the list when a MEMBER of it is
        // walked, since the member is a node of its own and never a seed.
        $reachedCallers = array_filter(
            $this->graph->callersOf($seeds, $maxDepth, self::ASSOCIATION_EDGE_TYPES),
            static fn (array $hop): bool => ! isset($changed[$hop['node']]),
        );

        $entryPoints = $this->entryPointsAmong(array_values($reachedCallers));
        $reach = array_diff_key($this->graph->reachedViaTypes($seeds, $maxDepth), $changed);
        $impacted = count(array_filter($reach, $this->isRiskBearing(...)));

        return $memo[$key] = [count($entryPoints), $impacted];
    }

    /**
     * @param  list<array{depth: int, node: string, via: string, file?: string, line?: int}>  $callers
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
     * @param  list<array{depth: int, node: string, via: string, file?: string, line?: int}>  $callers
     * @param  list<string>  $seeds
     * @return array<string, list<array{node: string, via: string, file?: string, line?: int}>>
     */
    private function entryPointPathsFor(array $entryPoints, array $callers, array $seeds, int $maxDepth): array
    {
        $uiMemberByClass = $this->uiMembersAmong($callers);
        // Same exclusions the classification used. An entry point that qualified on a call-only walk
        // must not be EXPLAINED through a shorter model relation — the chain would then present an
        // association as the reason it calls the change, contradicting the list it appears in.
        $rawPaths = $this->graph->callerPathsTo(
            $seeds,
            array_values(array_unique([...$entryPoints, ...array_values($uiMemberByClass)])),
            $maxDepth,
            self::ASSOCIATION_EDGE_TYPES,
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
     * @param  list<array{depth: int, node: string, via: string, file?: string, line?: int}>  $callers
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
     *
     * @internal see {@see isEntryPointNode()} — the same shared vocabulary
     */
    public function uiComponentClassOf(string $node): ?string
    {
        $class = explode('::', $node, 2)[0];

        if (! AppNamespace::isAppClass($class)) {
            return null;
        }

        return Str::contains($class, self::UI_COMPONENT_NAMESPACES) ? $class : null;
    }

    /**
     * Nodes reached ONLY through the given excluded edge types — reach the report shows and the count
     * ignores.
     *
     * The "only" is what makes it safe to print beside the impacted number without double-counting: a
     * node that also arrived by a risk-bearing edge is already in that number, and listing it here as
     * uncounted context would contradict it.
     *
     * @param  array<string, array<string, true>>  $reach
     * @param  list<string>  $types
     * @return list<string>
     */
    private function uncountedReachVia(array $reach, array $types): array
    {
        return array_keys(array_filter(
            $reach,
            fn (array $viaTypes): bool => ! $this->isRiskBearing($viaTypes)
                && array_intersect_key($viaTypes, array_flip($types)) !== [],
        ));
    }

    /**
     * Why each association surface is listed. Delegated to {@see AssociationReasons} — the computation
     * has two subtleties worth explaining in one place (the UI-member stand-in, and asking whether a
     * fan-out is REQUIRED rather than merely on the shortest path), and this class is at its
     * cognitive-complexity ceiling.
     *
     * @param  list<string>  $associationEntryPoints
     * @param  list<string>  $seeds
     * @param  list<array{depth: int, node: string, via: string, file?: string, line?: int}>  $callers
     * @return array<string, list<string>>
     */
    private function associationReasons(array $associationEntryPoints, array $seeds, int $maxDepth, array $callers): array
    {
        return new AssociationReasons($this->graph)
            ->for($associationEntryPoints, $seeds, $maxDepth, $callers, $this->uiComponentClassOf(...));
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
     * Seed nodes matching the FQCN — both class-level (`App\Models\Post`) and member-level (`App\Models\Post::query`) nodes.
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

    /**
     * Whether the node id is an entry-point node by prefix (`route::`/`command::`/`schedule::`).
     *
     * @internal the entry-surface vocabulary richter's own composition shares (e.g. the MCP
     *   entry-points resource) — not a consumer API
     */
    public function isEntryPointNode(string $node): bool
    {
        return Str::startsWith($node, self::ENTRY_POINT_PREFIXES);
    }

    private function isEntryPointClass(string $file): bool
    {
        return Str::contains(Fqcn::fromPath($file), self::ENTRY_POINT_NAMESPACES);
    }
}
