<?php declare(strict_types=1);

namespace SanderMuller\Richter\Mcp;

use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Tool;
use SanderMuller\Richter\Mcp\Tools\DetectChangesTool;
use SanderMuller\Richter\Mcp\Tools\ImpactTool;

final class RichterServer extends Server
{
    protected string $name = 'Richter';

    protected string $version = '0.1.0';

    protected string $instructions = 'Static blast-radius analysis of this Laravel codebase, built from Laravel Brain. Use impact to see what a symbol affects, and detect-changes to triage the current branch diff before review. Advisory only — a low/empty result is not a guarantee of no impact.';

    /** @var array<int, class-string<Tool>|Tool> */
    protected array $tools = [
        ImpactTool::class,
        DetectChangesTool::class,
    ];
}
