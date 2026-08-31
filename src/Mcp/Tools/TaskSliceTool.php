<?php declare(strict_types=1);

namespace SanderMuller\Richter\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use Override;
use SanderMuller\Richter\Analysis\AffectedTests;
use SanderMuller\Richter\Analysis\HtmlFormatter;
use SanderMuller\Richter\Analysis\JsonPresenter;
use SanderMuller\Richter\Analysis\TaskSlice;
use SanderMuller\Richter\Graph\GraphCache;

/**
 * @phpstan-import-type DetectChangesResult from HtmlFormatter
 */
#[IsReadOnly]
final class TaskSliceTool extends Tool
{
    protected string $name = 'task-slice';

    protected string $description = 'One document for work in progress: the entry surfaces THIS TASK owns, which of them no test proves, the hazards on the diff, the findings in the changed source, and the tests to run. Read kept and act on unreferencedKept, hazards and findings; treat droppedHubCount as a count only and do NOT open the hub files behind it. When kept is empty and runImpact is true, the change owns no entry surface (a loader, a data object) — call the impact tool on each runImpactOn class instead. THE TEST CONTRACT is unchanged: affectedTestsDeterminable false means run the full suite. Requires richter.task_slice hub paths to be configured; without them nothing is folded and kept is every reached surface.';

    public function __construct(private readonly GraphCache $graphs) {}

    /** @return array<string, mixed> */
    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'base' => $schema->string()
                ->description('Git ref to diff against; omit for the configured default_base. Mid-feature this is the TASK parent, not the branch base.'),
            'head' => $schema->string()
                ->description('Analyse the COMMITTED tree of this ref instead of the working tree. Defaults to HEAD, which includes uncommitted work.'),
        ];
    }

    public function handle(Request $request): ResponseFactory
    {
        $base = $request->get('base');
        $head = $request->get('head');
        /** @var DetectChangesResult|null $analysis */
        $analysis = null;

        $selection = AffectedTests::selectForCurrentDiff(
            $this->graphs,
            is_string($base) && $base !== '' ? $base : null,
            false,
            is_string($head) && $head !== '' ? $head : null,
            fullAnalysis: true,
            analysisUsed: $analysis,
        );

        unset($selection['untrackedFiles']);

        $document = $analysis === null
            ? JsonPresenter::emptyDetectChanges($selection['base'])
            : JsonPresenter::detectChanges($analysis, $selection['base']);

        $slice = TaskSlice::compose($document, $selection);

        return new ResponseFactory(Response::text($this->summary($slice)))
            ->withStructuredContent($slice);
    }

    /** @param  array<string, mixed>  $slice */
    private function summary(array $slice): string
    {
        $kept = is_array($slice['kept'] ?? null) ? count($slice['kept']) : 0;
        $dropped = is_int($slice['droppedHubCount'] ?? null) ? $slice['droppedHubCount'] : 0;
        $unreferenced = is_array($slice['unreferencedKept'] ?? null) ? count($slice['unreferencedKept']) : 0;

        return sprintf(
            '%d surface(s) this task owns, %d unproven by a test, %d folded as hub reach. Risk: %s.',
            $kept,
            $unreferenced,
            $dropped,
            is_string($slice['risk'] ?? null) ? $slice['risk'] : 'unknown',
        );
    }

    /** @return array<string, mixed> */
    #[Override]
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'base' => $schema->string()->description('The git ref the diff was taken against.'),
            'kept' => $schema->array()->items($schema->string())->description('The entry surfaces this task owns, in reading order — the ones whose own file is in the diff, or whose most specific explaining file is not a configured hub. A surface nothing explains is kept.'),
            'unreferencedKept' => $schema->array()->items($schema->string())->description('The subset of kept that no test PROVES: unreferenced, or referenced only by a test with no behavioural assertion the scan recognises. Act on these.'),
            'hazards' => $schema->array()->items($schema->object())->description('The diff\'s change hazards, worst tier first — same shape as detect-changes.'),
            'findings' => $schema->array()->items($schema->string())->description('Advisory notes about the changed source itself.'),
            'verificationFalse' => $schema->array()->items($schema->string())->description('What the risk level graded and did NOT find verified, including states that could not be checked.'),
            'runImpact' => $schema->boolean()->description('True when the diff changed something but owns no entry surface — a loader, a data object, a builder. Call the impact tool on runImpactOn instead of concluding the change reaches nothing.'),
            'runImpactOn' => $schema->array()->items($schema->string())->description('The classes worth an impact call when runImpact is true.'),
            'affectedTestsDeterminable' => $schema->boolean()->description('False means run the FULL suite. Also false whenever the keep set folded hub surfaces away, because the selection was computed for the whole diff and is not complete for the keep set.'),
            'affectedTests' => $schema->array()->items($schema->string())->description('The test selection, unchanged from affected-tests — never narrowed by the hub list.'),
            'affectedFrontendTests' => $schema->array()->items($schema->string())->description('Frontend specs the diff can reach; advisory, never a determinability input.'),
            'affectedTestsReasons' => $schema->array()->items($schema->string())->description('Why the selection is not determinable, when it is not.'),
            'droppedHubCount' => $schema->integer()->description('How many surfaces were folded as hub reach. A COUNT to read, not a list to open.'),
            'entryPointCount' => $schema->integer()->description('How many surfaces the diff reaches in total, folded ones included.'),
            'changedFiles' => $schema->array()->items($schema->string())->description('The files the diff changed.'),
            'risk' => $schema->string()->description('low, medium or high — unchanged from detect-changes, and never influenced by the hub list.'),
            'riskCause' => $schema->string()->description('Why the level is what it is, in one line.'),
            'unresolved' => $schema->boolean()->description('At least one changed file could not be fully placed.'),
            'lowConfidence' => $schema->boolean()->description('At least one changed member could not be pinned to a graph node.'),
        ];
    }
}
