<?php

declare(strict_types=1);

use Boundwize\StructArmed\Architecture;
use Boundwize\StructArmed\Preset\Preset;
use Boundwize\StructArmed\Preset\Presets\Psr4Preset;

return Architecture::define()
    ->skip([
        Psr4Preset::CLASSES_MUST_MATCH_COMPOSER => [
            // multiple classes on single file
            __DIR__ . '/tests/Unit/RuntimeRouterGuardsTest.php',
        ],
    ])
    ->withPresets(Preset::PSR4(), Preset::CODEQUALITY())
    ->layer('Support', 'src/Support')
    ->layer('Changes', 'src/Changes')
    ->layer('Graph', ['src/Graph', 'src/Console/InternalTracerBranchCommand.php'])
    ->layer('TracerConcerns', 'src/Tracers/Concerns')
    ->layer('Tracers', 'src/Tracers', 'src/Tracers/Concerns')
    ->layer('Hazards', 'src/Analysis/Hazards')
    ->layer('Analysis', 'src/Analysis', 'src/Analysis/Hazards')
    ->layer('ConsoleConcerns', 'src/Console/Concerns')
    ->layer('Console', 'src/Console', ['src/Console/Concerns', 'src/Console/InternalTracerBranchCommand.php'])
    ->layer('McpToolConcerns', 'src/Mcp/Tools/Concerns')
    ->layer('McpTools', 'src/Mcp/Tools', 'src/Mcp/Tools/Concerns')
    ->layer('McpResources', 'src/Mcp/Resources')
    ->layer('Mcp', 'src/Mcp', ['src/Mcp/Tools', 'src/Mcp/Resources'])
    ->layer('ServiceProvider', 'src/RichterServiceProvider.php')
    ->ruleset([
        'Support' => ['Analysis', 'Graph', 'Tracers'],
        'Changes' => ['+Hazards'],
        'Graph' => ['+Support', 'Changes'],
        'TracerConcerns' => ['Tracers'],
        'Tracers' => ['+Support', 'Changes', 'TracerConcerns'],
        'Hazards' => ['+Support', 'Changes'],
        'Analysis' => ['+Support', 'Changes', 'Hazards'],
        'ConsoleConcerns' => ['Analysis', 'Graph', 'Support'],
        'Console' => ['+ConsoleConcerns', 'Changes'],
        'McpToolConcerns' => ['Analysis'],
        'McpTools' => ['+McpToolConcerns', 'Changes', 'Graph', 'Support'],
        'McpResources' => ['Analysis', 'Graph', 'Support'],
        'Mcp' => ['McpTools', 'McpResources'],
        'ServiceProvider' => ['Console', 'Graph', 'Mcp'],
    ]);
