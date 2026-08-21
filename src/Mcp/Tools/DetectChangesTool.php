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
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        try {
            $base = RichterConfig::baseRef($request->get('base'));
            $changed = ChangedSymbols::resolve($base);
        } catch (InvalidArgumentException|RuntimeException $exception) {
            return Response::error($exception->getMessage());
        }

        if ($changed === []) {
            return new ResponseFactory(Response::text("No changed PHP files under app/ against {$base}."))
                ->withStructuredContent(JsonPresenter::emptyDetectChanges($base));
        }

        // The index goes to the ANALYZER, not only to the renderers. Where a change carries no hazard
        // the level is decided on what a test references, so handing it to the formatters alone made
        // the report contradict itself: per-row references rendered correctly beside a level computed
        // as though no test existed, and a cause line that named surfaces as unreferenced while the
        // row beside it called them referenced.
        $graph = $this->graphs->graph();
        $tests = TestReferenceIndex::fromTests(base_path('tests'));
        $tests->useGraph($graph);

        $result = new ImpactAnalyzer($graph)->detectChanges($changed, tests: $tests);

        return new ResponseFactory(Response::text(ImpactFormatter::detectChanges($result, $tests)))
            ->withStructuredContent(JsonPresenter::detectChanges($result, $base, $tests));
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
            'entryPoints' => $schema->array()->items($schema->string()),
            'associationEntryPoints' => $schema->array()->items($schema->string())
                ->description('Entry surfaces connected to the change only by a model relation. Associated with it, not callers of it — context, and excluded from the risk level.'),
            'entryPointPaths' => $schema->object()
                ->description('Entry-point node => call chain down to the changed code; each hop may carry a project-relative file/line. Empty map serializes as [].'),
            'entryPointLocations' => $schema->object()
                ->description('Entry-point node => {file, line?} defining location, when known. Empty map serializes as [].'),
            'entryPointSecurity' => $schema->object()
                ->description('Entry-point route => Brain security surface {exposure, riskLevel, issues[]}. Inherited from laravel-brain; routes only. A PUBLIC_WRITE issue here is what makes a hazard reachable through this route grade reach public-write, so it does reach the risk level. Empty map serializes as [].'),
            'entryPointGates' => $schema->object()
                ->description('Entry-point route => Pennant feature flags gating it (EnsureFeaturesAreActive middleware). Advisory annotation; never an input to risk or the gate. Empty map serializes as [].'),
            'entryPointTestReferences' => $schema->object()
                ->description('Entry-point node => "referenced" | "referenced-no-behavioural-assertion" | "unreferenced". A node whose reference state could not be determined is omitted here; the risk level reads that state as unverified. Where a change carries no hazard the level is decided on this, so it is not advisory-only — see `verification` for the exact set graded. Never an input to affected-tests selection. Empty map serializes as [].'),
            'impacted' => $schema->integer()->description('Distinct impacted graph nodes.'),
            'relatedModels' => $schema->array()->items($schema->string()),
            'traitAndOverrideReach' => $schema->array()->items($schema->string())->description('Classes that run the changed member without calling it — they use the trait declaring it, or implement the ancestor it overrides. Deliberately not counted toward impacted or risk (a hub trait would saturate the level on breadth), and reported because excluding them from the count is not a reason to exclude them from the report.'),
            'traitAndOverrideReachVia' => $schema->object()->description("Why each entry of traitAndOverrideReach is listed, keyed by node: the edge types that reached it (`uses-trait`, `override`). Read off the walk's own via-type map, so it cannot disagree with the list it annotates."),
            'risk' => $schema->string()->description('low, medium or high. Decided by the HAZARD the change carries and, where it carries none, by whether anything would catch a regression in what it reaches — never by how many nodes it touches. impacted and the entryPoints count describe the change; they do not grade it.'),
            'riskCause' => $schema->string()->description('Why the level is what it is, in one line. Always present: a level without its cause is not a usable verdict.'),
            'hazards' => $schema->array()->items($schema->object())->description('Tiered properties of the diff that say it may break something: {lane, tier, cwe, member, reach, evidence}. Tier 3 is a guard removed or a disclosure widened and is HIGH at every reach; tier 2 is a broken contract or payload; tier 1 is a signature change a default may absorb. reach is one of four: two findings, public-write and gated, and two admissions, no-guard-found (reached, no guard visible on at least one reaching route) and no-known-path (nothing reaching it found). Neither admission is evidence of safety, and both score exactly as gated does except no-known-path at tier 1.'),
            'verification' => $schema->object()->description('What the level graded => whether a test references it. Entry-point nodes, plus a changed class itself where it reached no entry point (a runnable test importing that class counts). A state that could not be checked reads false: "could not check" must never open the LOW path. Empty map serializes as [].'),
            'lowConfidence' => $schema->boolean(),
            'findings' => $schema->array()->items($schema->string()),
            'unresolved' => $schema->boolean()->description('True when any changed file could not be placed in the graph.'),
        ];
    }
}
