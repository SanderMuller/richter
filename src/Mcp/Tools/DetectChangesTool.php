<?php declare(strict_types=1);

namespace SanderMuller\Richter\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Override;
use RuntimeException;
use SanderMuller\Richter\Analysis\BoundedPresenter;
use SanderMuller\Richter\Analysis\ImpactAnalyzer;
use SanderMuller\Richter\Analysis\ImpactFormatter;
use SanderMuller\Richter\Analysis\JsonPresenter;
use SanderMuller\Richter\Analysis\TestReferenceIndex;
use SanderMuller\Richter\Changes\ChangedSymbols;
use SanderMuller\Richter\Graph\GraphCache;
use SanderMuller\Richter\Support\RichterConfig;

#[IsReadOnly]
final class DetectChangesTool extends Tool
{
    use Concerns\ResolvesBoundingArguments;

    protected string $name = 'detect-changes';

    protected string $description = 'Advisory change-impact for the current branch diff: which HTTP/CLI entry points and flows the changed PHP files reach, plus a coarse risk level. Diffs against the given base ref (defaults to the richter.default_base config value).';

    public function __construct(private readonly GraphCache $graphs) {}

    /** @return array<string, mixed> */
    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'base' => $schema->string()
                ->description('Git ref to diff the current branch against. Defaults to the richter.default_base config value.'),
            'head' => $schema->string()
                ->description('Git ref to diff UP TO. Defaults to HEAD, which also includes the uncommitted working tree; naming a commit analyses that committed state instead. An agent working mid-feature against a task parent needs this — without it the only readable range ends at the working tree.'),
            'full' => $schema->boolean()->description(self::fullArgumentDescription()),
            'entries' => $schema->array()->items($schema->string())->description(self::entriesArgumentDescription()),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        // Before the diff resolves: the empty-diff early return below must not skip validation.
        $bounding = $this->boundingArguments($request);

        if ($bounding instanceof Response) {
            return $bounding;
        }

        [$full, $entries] = $bounding;

        try {
            $base = RichterConfig::baseRef($request->get('base'));
            $head = RichterConfig::headRef($request->get('head'));
            $changed = ChangedSymbols::resolve($base, $head);
        } catch (InvalidArgumentException|RuntimeException $exception) {
            return Response::error($exception->getMessage());
        }

        if ($changed === []) {
            return new ResponseFactory(Response::text("No changed PHP files under app/ against {$base}."))
                ->withStructuredContent(BoundedPresenter::detectChanges(JsonPresenter::emptyDetectChanges($base), $full, $entries));
        }

        // The index goes to the ANALYZER, not only to the renderers. Where a change carries no hazard
        // the level is decided on what a test references, so handing it to the formatters alone made
        // the report contradict itself: per-row references rendered correctly beside a level computed
        // as though no test existed, and a cause line that named surfaces as unreferenced while the
        // row beside it called them referenced.
        $graph = $this->graphs->graph();
        $tests = TestReferenceIndex::fromTests(base_path('tests'));
        $tests->useGraph($graph);

        $result = new ImpactAnalyzer($graph)->detectChanges($changed, tests: $tests, runtimeEvidenceRoot: RichterConfig::runtimeEvidenceRoot($head));

        // Bounded by default: the full document on a hub diff displaces an agent's context. The
        // CLI --json path never runs this step — a script has a disk, not a context window.
        return new ResponseFactory(Response::text(ImpactFormatter::detectChanges($result, $tests)))
            ->withStructuredContent(BoundedPresenter::detectChanges(JsonPresenter::detectChanges($result, $base, $tests), $full, $entries));
    }

    /** @return array<string, mixed> */
    #[Override]
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            // The three map-shaped fields are plain object() rather than an object|array anyOf:
            // anyOf() is missing from Illuminate\JsonSchema on this package's framework floor, and
            // an empty PHP map JSON-encodes as [] — the description carries that caveat instead.
            'base' => $schema->string()->description('The git ref the diff was taken against.'),
            'changed' => $schema->object()
                ->description('Changed file => resolved seed count. Empty map serializes as [].'),
            'coverage' => $schema->object()
                ->description('Changed file => "analyzed" or "unresolved". Empty map serializes as [].'),
            'entryPoints' => $schema->array()->items($schema->string())
                ->description('Entry surfaces the change reaches, in READING ORDER: the surfaces this diff explains most specifically come first, ranked on entryPointAttribution.ownReach with unattributed surfaces last. The same order the text, markdown and HTML reports render, so a prefix of this array is the prefix a human sees. Capped at 15 — entryPointsTotal carries the full count, entries: [...] keeps named nodes visible past the cap, full: true lifts it.'),
            'associationEntryPoints' => $schema->array()->items($schema->string())
                ->description('Entry surfaces connected to the change only by a model relation or a registry lookup. Associated with it, not callers of it — context, and excluded from the risk level. Capped at 15; associationEntryPointsTotal carries the full count, and full: true lifts the cap.'),
            'associationEntryPointsVia' => $schema->object()
                ->description('Association surface => the association edge types on the path the surface DEPENDS on; a fan-out is named only where it is required (model-relationship, model-to-policy, config-registry-fanout). Reads why each entry is listed: a model-relationship names ONE model and says something true of this change, while a config-registry-fanout names no single class, so the same surfaces answer for every class the registry lists. Prose reports keep the first group inline and collapse the second under that one shared cause. Empty map serializes as [].'),
            'entryPointPaths' => $schema->object()
                ->description('Entry-point node => call chain down to the changed code; each hop may carry a project-relative file/line. Empty map serializes as [].'),
            'entryPointLocations' => $schema->object()
                ->description('Entry-point node => {file, line?} defining location, when known. Empty map serializes as [].'),
            'entryPointSecurity' => $schema->object()
                ->description('Entry-point route => Brain security surface {exposure, riskLevel, issues[]}. Inherited from laravel-brain; routes only. A PUBLIC_WRITE issue here is what makes a hazard reachable through this route grade reach public-write, so it does reach the risk level. Empty map serializes as [].'),
            'entryPointGates' => $schema->object()
                ->description('Entry-point route => Pennant feature flags gating it (EnsureFeaturesAreActive middleware). Advisory annotation; never an input to risk or the gate. Empty map serializes as [].'),
            'entryPointRuntimeGuards' => $schema->object()
                ->description('Entry-point route => guards the BOOTED ROUTER proves on it, group expansion included: [{middleware, group}], group null when applied directly. The third cross-check: runtime evidence beside Brain\'s finding, never a suppression, and — through a hazard\'s reach class — an input to the level like the other two cross-check maps. Populated only when the analysis runs against the booted working tree (never under a named head). Empty map serializes as [].'),
            'entryPointTestReferences' => $schema->object()
                ->description('Entry-point node => "referenced" | "referenced-no-behavioural-assertion" | "unreferenced". A node whose reference state could not be determined is omitted here; the risk level reads that state as unverified. Where a change carries no hazard the level is decided on this, so it is not advisory-only — see `verification` for the exact set graded. Never an input to affected-tests selection. Empty map serializes as [].'),
            'entryPointAttribution' => $schema->object()
                ->description('Entry-point node => {via, ownReach}: the changed FILE PATH that explains this surface most specifically, and how many entry points that file reaches on its own. Reading order: the smaller ownReach is, the more the surface is about this diff rather than about something the diff happens to touch. The report orders rows on it. A surface no per-file walk explains — a self-listed entry class, a frontend surface — is absent here rather than carrying a null: there is no reach number for it. `via` is a path and never a class name, because a route file or a file declaring two classes has no single FQCN. Advisory: never an input to risk, the gate, or affected-tests selection, and a consumer that narrows its own test run on it does so at its own risk. Empty map serializes as [].'),
            'entryPointKeepSet' => $schema->object()
                ->description('{kept, droppedHub, keptTotal}: which entry surfaces this diff OWNS, and how many were reached only through a file the project lists as a hub in richter.task_slice. `kept` is a subset of the FULL entryPoints in the same reading order, and is capped at 15 (keptTotal carries the full count). The two lists cap independently, so under the default cap a kept row may lie past the visible entryPoints prefix; reading the dropped surfaces as the difference between kept and entryPoints only holds under full: true, where neither list is capped. With no hub configured the feature is off — `kept` equals entryPoints and droppedHub is 0 — because no measurement produced a rule for hub-ness and a shipped default would be a guess. A surface with no attribution is always kept: the routes a changed frontend file references are never attributed, so dropping the unexplained would drop what a frontend change owns. Advisory: never an input to risk, the gate, or affected-tests selection.'),
            'impacted' => $schema->integer()->description('Distinct impacted graph nodes.'),
            'relatedModels' => $schema->array()->items($schema->string())->description('Models reached only via association edges (context, not risk). Capped at 15; relatedModelsTotal carries the full count, and full: true lifts the cap.'),
            'traitAndOverrideReach' => $schema->array()->items($schema->string())->description('Capped at 15 (traitAndOverrideReachTotal carries the full count). What the change is related to through the hierarchy rather than through a call: a class using the trait that declares the changed member, and the other end of an override relationship. Deliberately not counted toward impacted or risk (a hub trait would saturate the level on breadth), and reported because excluding them from the count is not a reason to exclude them from the report. Entries are ungrouped (the prose reports group the override lane); full: true lifts the cap.'),
            'traitAndOverrideReachVia' => $schema->object()->description("Why each entry of traitAndOverrideReach is listed, keyed by node: the edge types that reached it (`uses-trait`, `override`). Read off the walk's own via-type map, so it cannot disagree with the list it annotates. The two are different claims: a `uses-trait` entry has the changed method copied into it and runs it, while an `override` entry declares its own version of the member."),
            'risk' => $schema->string()->description('low, medium or high. Decided by the HAZARD the change carries and, where it carries none, by whether anything would catch a regression in what it reaches — never by how many nodes it touches. impacted and the entryPoints count describe the change; they do not grade it.'),
            'riskCause' => $schema->string()->description('Why the level is what it is, in one line. Always present: a level without its cause is not a usable verdict.'),
            'hazards' => $schema->array()->items($schema->object())->description('Tiered properties of the diff that say it may break something: {lane, tier, cwe, member, reach, evidence}. Tier 3 is a guard removed or a disclosure widened and is HIGH at every reach; tier 2 is a broken contract or payload; tier 1 is a signature change a default may absorb. reach is one of four: two findings, public-write and gated, and two admissions, no-guard-found (reached, no guard visible on at least one reaching route) and no-known-path (nothing reaching it found). Neither admission is evidence of safety, and both score exactly as gated does except no-known-path at tier 1.'),
            'verification' => $schema->object()->description('What the level graded => whether a test references it. Entry-point nodes, plus a changed class itself where it reached no entry point (a runnable test importing that class counts). A state that could not be checked reads false: "could not check" must never open the LOW path. Empty map serializes as [].'),
            'lowConfidence' => $schema->boolean(),
            'findings' => $schema->array()->items($schema->string()),
            'unresolved' => $schema->boolean()->description('True when any changed file could not be placed in the graph.'),
            'bounded' => $schema->boolean()
                ->description('True when any list or map was held back by the cap. A capped list is never the complete answer — read the totals, and pass full: true or entries: [...] for the rest. Every per-entry map is restricted to the entry points still shown.'),
            'entryPointsTotal' => $schema->integer()->description('Full entry-point count before the cap; equals the array length when nothing was capped.'),
            'associationEntryPointsTotal' => $schema->integer()->description('Full association-surface count before the cap.'),
            'relatedModelsTotal' => $schema->integer()->description('Full related-model count before the cap.'),
            'traitAndOverrideReachTotal' => $schema->integer()->description('Full inheritance-reach count before the cap.'),
        ];
    }
}
