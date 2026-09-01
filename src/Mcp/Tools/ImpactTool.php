<?php declare(strict_types=1);

namespace SanderMuller\Richter\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Override;
use SanderMuller\Richter\Analysis\BoundedPresenter;
use SanderMuller\Richter\Analysis\ImpactAnalyzer;
use SanderMuller\Richter\Analysis\ImpactFormatter;
use SanderMuller\Richter\Analysis\JsonPresenter;
use SanderMuller\Richter\Analysis\TestReferenceIndex;
use SanderMuller\Richter\Graph\GraphCache;
use SanderMuller\Richter\Mcp\Tools\Concerns\ResolvesBoundingArguments;

#[IsReadOnly]
final class ImpactTool extends Tool
{
    use ResolvesBoundingArguments;

    protected string $name = 'impact';

    protected string $description = 'Static blast radius of a PHP symbol in this Laravel app: its callers (what breaks if you change it), its dependencies (what it reaches), and the entry surfaces the callers walk reaches — routes, commands, schedules, and Livewire/Filament/Nova components — annotated with locations, security exposure (advisory, routes only; absence means NOT CLASSIFIED, never "public"), feature gates, and test references. Annotations are advisory orientation, never a risk verdict. Pass an FQCN or substring, e.g. App\\Models\\User.';

    public function __construct(private readonly GraphCache $graphs) {}

    /** @return array<string, mixed> */
    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'symbol' => $schema->string()
                ->description('FQCN or substring to analyse, e.g. "App\\Models\\User".')
                ->required(),
            'full' => $schema->boolean()->description(self::fullArgumentDescription()),
            'entries' => $schema->array()->items($schema->string())->description(self::entriesArgumentDescription()),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $bounding = $this->boundingArguments($request);

        if ($bounding instanceof Response) {
            return $bounding;
        }

        [$full, $entries] = $bounding;
        $symbol = $request->get('symbol');

        if (! is_string($symbol) || $symbol === '') {
            return Response::error('The symbol argument must be a non-empty string.');
        }

        $graph = $this->graphs->graph();
        $result = new ImpactAnalyzer($graph)->impact($symbol, runtimeEvidenceRoot: base_path());
        // Lazy: the tests/ scan only runs when the walk actually reached an entry surface.
        $tests = $result['entryPoints'] === [] ? null : TestReferenceIndex::fromTests(base_path('tests'), base_path());
        // Reused, not re-fetched: without it a class-driven route reads unreferenced.
        $tests?->useGraph($graph);

        // Bounded by default: the full document on a hub symbol displaces an agent's context. The
        // CLI --json path never runs this step — a script has a disk, not a context window.
        return new ResponseFactory(Response::text(ImpactFormatter::impact($result, $tests)))
            ->withStructuredContent(BoundedPresenter::impact(JsonPresenter::impact($result, $tests), $full, $entries));
    }

    /** @return array<string, mixed> */
    #[Override]
    public function outputSchema(JsonSchema $schema): array
    {
        $edge = $schema->object([
            'depth' => $schema->integer(),
            'node' => $schema->string(),
            'via' => $schema->string(),
            'file' => $schema->string()->description('Project-relative defining file, when known.'),
            'line' => $schema->integer()->description('Defining line, when known.'),
        ]);

        return [
            'target' => $schema->string()->description('The symbol as analysed.'),
            'callers' => $schema->array()->items($edge)->description('What breaks if the target changes; depth 1 is a direct caller. Capped at 15 in BFS depth order (nearest hops survive); callersTotal carries the full count, and full: true lifts the cap.'),
            'dependencies' => $schema->array()->items($edge)->description('What the target reaches. Capped at 15 in BFS depth order; dependenciesTotal carries the full count, and full: true lifts the cap.'),
            // The map-shaped fields are plain object() rather than an object|array anyOf:
            // anyOf() is missing from Illuminate\JsonSchema on this package's framework floor, and
            // an empty PHP map JSON-encodes as [] — the description carries that caveat instead.
            'entryPoints' => $schema->array()->items($schema->string())->description('Entry surfaces among the callers: route::/command::/schedule:: nodes and Livewire/Filament/Nova component classes. Capped at 15; entryPointsTotal carries the full count, entries: [...] keeps named nodes visible past the cap, full: true lifts it.'),
            'associationEntryPoints' => $schema->array()->items($schema->string())
                ->description('Entry surfaces connected to the symbol only by a model relation or a registry lookup. Associated with it, not callers of it. Capped at 15; associationEntryPointsTotal carries the full count, and full: true lifts the cap.'),
            'associationEntryPointsVia' => $schema->object()
                ->description('Association surface => the association edge types on the path the surface DEPENDS on; a fan-out is named only where it is required (model-relationship, model-to-policy, config-registry-fanout). Reads why each entry is listed: a model-relationship names ONE model and says something true of this change, while a config-registry-fanout names no single class, so the same surfaces answer for every class the registry lists. Prose reports keep the first group inline and collapse the second under that one shared cause. Empty map serializes as [].'),
            'entryPointPaths' => $schema->object()
                ->description('Entry-point node => shortest call chain down to the symbol; each hop may carry a project-relative file/line. Empty map serializes as [].'),
            'entryPointLocations' => $schema->object()
                ->description('Entry-point node => {file, line?} defining location, when known. Empty map serializes as [].'),
            'entryPointSecurity' => $schema->object()
                ->description('Entry-point route => Brain security surface {exposure, riskLevel, issues[]}. Advisory annotation, routes only — a missing key means NOT CLASSIFIED, never "public". Empty map serializes as [].'),
            'entryPointGates' => $schema->object()
                ->description('Entry-point route => Pennant feature flags gating it. Advisory annotation. Empty map serializes as [].'),
            'entryPointAuthGates' => $schema->object()
                ->description('Entry-point route => policy gates in its reach that contradict a PUBLIC_WRITE finding — evidence to verify, never a suppression. Empty map serializes as [].'),
            'entryPointRuntimeGuards' => $schema->object()
                ->description('Entry-point route => guards the BOOTED ROUTER proves on it, group expansion included: [{middleware, group}], group null when applied directly. The third cross-check — runtime evidence beside Brain\'s finding, never a suppression; populated only when the analysis runs against the booted working tree. Empty map serializes as [].'),
            'entryPointTestReferences' => $schema->object()
                ->description('Entry-point node => "referenced" | "referenced-no-behavioural-assertion" | "unreferenced". Advisory annotation. Empty map serializes as [].'),
            'bounded' => $schema->boolean()
                ->description('True when any list or map was held back by the cap. A capped list is never the complete answer — read the totals, and pass full: true or entries: [...] for the rest. Every per-entry map is restricted to the entry points still shown.'),
            'callersTotal' => $schema->integer()->description('Full caller count before the cap; equals the array length when nothing was capped.'),
            'dependenciesTotal' => $schema->integer()->description('Full dependency count before the cap.'),
            'entryPointsTotal' => $schema->integer()->description('Full entry-point count before the cap.'),
            'associationEntryPointsTotal' => $schema->integer()->description('Full association-surface count before the cap.'),
        ];
    }
}
