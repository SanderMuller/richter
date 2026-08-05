<?php declare(strict_types=1);

namespace SanderMuller\Richter\Mcp\Resources;

use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Resource;
use SanderMuller\Richter\Analysis\JsonPresenter;
use SanderMuller\Richter\Support\RichterConfig;

final class ConfigResource extends Resource
{
    protected string $uri = 'richter://config';

    protected string $mimeType = 'application/json';

    protected string $description = 'The effective richter configuration subset that shapes analysis: the diff base, root namespace override, entry-point roots, dispatch helpers, feature-gate wrappers, the frontend bridge, and the cache/parallel build switches. Paths and names only — richter config carries no secrets.';

    public function handle(): Response
    {
        return Response::text(JsonPresenter::encode([
            'default_base' => RichterConfig::baseRef(),
            'root_namespace' => RichterConfig::rootNamespace(),
            'entry_point_roots' => RichterConfig::entryPointRoots(),
            'dispatch_helpers' => RichterConfig::dispatchHelpers(),
            'feature_gate_methods' => RichterConfig::featureGateMethods(),
            'frontend' => [
                'roots' => RichterConfig::frontendRoots(),
                'pages_path' => RichterConfig::frontendPagesPath(),
                'generated_paths' => RichterConfig::frontendGeneratedPaths(),
                'test_paths' => RichterConfig::frontendTestPaths(),
                'http_callees' => RichterConfig::frontendHttpCallees(),
            ],
            'payload_parity' => [
                'enabled' => RichterConfig::payloadParityEnabled(),
                'mirror_threshold' => RichterConfig::payloadParityMirrorThreshold(),
                'ignore' => RichterConfig::payloadParityIgnore(),
            ],
            'cache' => ['enabled' => RichterConfig::cacheEnabled()],
            'parallel' => RichterConfig::parallel(),
        ]));
    }
}
