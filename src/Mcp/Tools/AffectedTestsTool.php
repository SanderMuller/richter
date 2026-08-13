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
use SanderMuller\Richter\Graph\GraphCache;

#[IsReadOnly]
final class AffectedTestsTool extends Tool
{
    protected string $name = 'affected-tests';

    protected string $description = 'The test files exercising the surface the current branch diff can reach — the selection to run instead of the full suite. THE CONTRACT: when determinable is false, run the FULL suite; the reasons list says why the selection could not vouch for completeness. Selection is reference-based recall, not proof of coverage — unreferencedEntryPoints counts reached entry points no test references. Optional base overrides the configured diff ref.';

    public function __construct(private readonly GraphCache $graphs) {}

    /** @return array<string, mixed> */
    #[Override]
    public function schema(JsonSchema $schema): array
    {
        return [
            'base' => $schema->string()
                ->description('Git ref to diff the current branch against; omit for the configured default_base.'),
        ];
    }

    public function handle(Request $request): ResponseFactory
    {
        $base = $request->get('base');

        // Every non-determinable cause — an unresolvable base included — is
        // `determinable: false` + reasons, mirroring the CLI's exit-2 fail-safe: one
        // result shape, and "run the full suite" is always the actionable answer. This
        // deliberately diverges from DetectChangesTool, which maps the same base
        // exception to an MCP error — for a test SELECTION, an error response would
        // hide the fail-safe instruction the caller must act on.
        $selection = AffectedTests::selectForCurrentDiff(
            $this->graphs,
            is_string($base) && $base !== '' ? $base : null,
        );

        // `untrackedFiles` feeds the CLI's stderr note; the structured document keeps
        // the declared CLI --json shape.
        unset($selection['untrackedFiles']);

        return new ResponseFactory(Response::text($this->summary($selection)))
            ->withStructuredContent($selection);
    }

    /** @param  array{base: string, determinable: bool, reasons: list<string>, tests: list<string>, frontendTests: list<string>, unreferencedEntryPoints: int, unresolvedDispatchSites: list<array{file: string, line: int, dispatcher: string}>}  $selection */
    private function summary(array $selection): string
    {
        if (! $selection['determinable']) {
            return implode("\n", [
                'Affected tests could not be determined — run the full suite.',
                ...array_map(static fn (string $reason): string => "  ! {$reason}", $selection['reasons']),
            ]);
        }

        $lines = ['Affected tests: ' . count($selection['tests'])];

        foreach ($selection['tests'] as $test) {
            $lines[] = "  - {$test}";
        }

        if ($selection['frontendTests'] !== []) {
            $lines[] = 'Frontend specs referencing the touched routes (run with your JS runner): ' . count($selection['frontendTests']);

            foreach ($selection['frontendTests'] as $test) {
                $lines[] = "  - {$test}";
            }
        }

        if ($selection['unreferencedEntryPoints'] > 0) {
            $lines[] = "Note: {$selection['unreferencedEntryPoints']} reached entry point(s) have no referencing test — the selection cannot cover them.";
        }

        return implode("\n", $lines);
    }

    /** @return array<string, mixed> */
    #[Override]
    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'base' => $schema->string()->description('The git ref the diff was taken against.'),
            'determinable' => $schema->boolean()->description('False means: run the full suite — the selection cannot vouch for completeness.'),
            'reasons' => $schema->array()->items($schema->string())->description('Why the selection is not determinable; empty when it is.'),
            'tests' => $schema->array()->items($schema->string())->description('PHP test files to run, project-relative.'),
            'frontendTests' => $schema->array()->items($schema->string())->description('Advisory: frontend spec files referencing a touched route, for the JS runner.'),
            'unreferencedEntryPoints' => $schema->integer()->description('Reached entry points no test references — the selection cannot cover them.'),
            'unresolvedDispatchSites' => $schema->array()->items($schema->object([
                'file' => $schema->string(),
                'line' => $schema->integer(),
                'dispatcher' => $schema->string(),
            ]))->description('The dispatches that made THIS selection undeterminable, in full; the reason above names only the first few. Empty when the selection is determinable. Each is a place to restructure the dispatch into a followable form. For the project-wide list regardless of the diff, read richter://graph/stats.'),
        ];
    }
}
