<?php declare(strict_types=1);

namespace SanderMuller\Richter\Mcp;

use Laravel\Mcp\Server;
use SanderMuller\Richter\Mcp\Resources\ConfigResource;
use SanderMuller\Richter\Mcp\Resources\EntryPointsResource;
use SanderMuller\Richter\Mcp\Resources\GraphStatsResource;
use SanderMuller\Richter\Mcp\Tools\AffectedTestsTool;
use SanderMuller\Richter\Mcp\Tools\DetectChangesTool;
use SanderMuller\Richter\Mcp\Tools\ImpactTool;
use SanderMuller\Richter\Mcp\Tools\TaskSliceTool;
use SanderMuller\Richter\Mcp\Tools\TraceTool;

final class RichterServer extends Server
{
    protected string $name = 'Richter';

    protected string $version = '0.1.0';

    protected string $instructions = 'Static blast-radius analysis of this Laravel codebase, built from Laravel Brain. Use impact to see what a symbol affects, trace for the shortest call-direction path between two symbols, detect-changes to triage the current branch diff before review, and affected-tests for the test selection the diff warrants (determinable: false means run the full suite). Resources give orientation without a tool call: the entry-point inventory, graph completeness stats, and the effective richter config. Advisory only — a low/empty result is not a guarantee of no impact.';

    // No `@var` on purpose. The parent declares this property as accepting string keys and nested
    // arrays (its tool-group form), so a narrower override is unsound — the parent may write a
    // shape the narrower type forbids. Restating the parent's type here instead would duplicate a
    // vendor type, and re-break whenever that type changes.
    protected array $tools = [
        ImpactTool::class,
        TraceTool::class,
        DetectChangesTool::class,
        AffectedTestsTool::class,
        TaskSliceTool::class,
    ];

    /** @var array<int, class-string<Server\Resource>|Server\Resource> */
    protected array $resources = [
        EntryPointsResource::class,
        GraphStatsResource::class,
        ConfigResource::class,
    ];
}
