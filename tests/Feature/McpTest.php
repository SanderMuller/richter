<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Feature;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Process;
use Illuminate\Testing\Fluent\AssertableJson;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\McpServiceProvider;
use Override;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Analysis\JsonPresenter;
use SanderMuller\Richter\Mcp\RichterServer;
use SanderMuller\Richter\Mcp\Tools\DetectChangesTool;
use SanderMuller\Richter\Mcp\Tools\ImpactTool;
use SanderMuller\Richter\Tests\TestCase;

#[Group('requires-mcp')]
final class McpTest extends TestCase
{
    /**
     * Orchestra\Testbench\TestCase disables package auto-discovery by default
     * ($enablesPackageDiscoveries = false), so laravel/mcp's own service provider never boots for
     * the shared TestCase. That provider is what wires the `resolving(Request::class, ...)`
     * container callback that populates a tool's Request with the caller's arguments — without
     * it, RichterServer::tool(...) calls a tool's handle() with an empty Request every time. Add
     * it here rather than in tests/TestCase.php so only this file's MCP-specific tests pay for it.
     *
     * @param  Application  $app
     * @return list<class-string>
     */
    #[Override]
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), McpServiceProvider::class];
    }

    #[Test]
    public function the_richter_mcp_server_is_registered(): void
    {
        $this->assertNotNull(Mcp::getLocalServer('richter'));
    }

    #[Test]
    public function the_tools_carry_the_names_the_server_instructions_promise(): void
    {
        $this->assertSame('impact', resolve(ImpactTool::class)->name());
        $this->assertSame('detect-changes', resolve(DetectChangesTool::class)->name());
    }

    #[Test]
    public function the_impact_tool_rejects_a_missing_symbol(): void
    {
        $response = resolve(ImpactTool::class)->handle(new Request([]));

        // handle() now returns Response|ResponseFactory (see the structured-content success paths
        // below); this error path always yields Response, so narrow before calling isError().
        $this->assertInstanceOf(Response::class, $response);
        $this->assertTrue($response->isError());
    }

    #[Test]
    public function the_detect_changes_tool_reports_a_broken_ref_as_an_error(): void
    {
        $response = resolve(DetectChangesTool::class)->handle(new Request(['base' => 'this-ref-does-not-exist-zzz']));

        $this->assertInstanceOf(Response::class, $response);
        $this->assertTrue($response->isError());
    }

    #[Test]
    public function the_detect_changes_tool_reports_an_option_shaped_ref_as_an_error(): void
    {
        $response = resolve(DetectChangesTool::class)->handle(new Request(['base' => '--upload-pack=x']));

        $this->assertInstanceOf(Response::class, $response);
        $this->assertTrue($response->isError());
    }

    #[Test]
    public function the_impact_tool_reports_the_blast_radius_of_a_symbol(): void
    {
        // Builds the real graph of the testbench skeleton. Both formatter branches (matched and
        // unmatched) quote the symbol, so the assertion holds regardless of what that graph contains.
        RichterServer::tool(ImpactTool::class, ['symbol' => 'User'])
            ->assertOk()
            ->assertSee('User')
            ->assertStructuredContent(function (AssertableJson $json): bool {
                $json->where('target', 'User')
                    ->has('callers')
                    ->has('dependencies');

                return true;
            });
    }

    #[Test]
    public function the_detect_changes_tool_reports_an_empty_diff_cleanly(): void
    {
        Process::fake([
            '*merge-base*' => Process::result("abc123\n"),
            '*diff*' => Process::result(''),
        ]);

        // The testbench config default base is origin/main; the exact-array form pins every
        // field of the zero contract.
        RichterServer::tool(DetectChangesTool::class)
            ->assertOk()
            ->assertSee('No changed PHP files under app/')
            ->assertStructuredContent(JsonPresenter::emptyDetectChanges('origin/main'));
    }

    #[Test]
    public function the_detect_changes_tool_reports_a_real_diff_with_structured_content(): void
    {
        // Same faked git plumbing as CommandsTest::detect_changes_reports_a_real_diff_end_to_end: the
        // changed file does not exist in the skeleton working tree, so this also covers the graph
        // returning an unresolved coverage entry rather than a falsely-empty "no impact".
        $diff = "diff --git a/app/Models/User.php b/app/Models/User.php\n--- a/app/Models/User.php\n+++ b/app/Models/User.php\n@@ -0,0 +1,1 @@\n+    public function added(): void {}\n";

        Process::fake([
            '*merge-base*' => Process::result("abc123\n"),
            '*show*' => Process::result(errorOutput: 'bad object', exitCode: 128),
            '*diff*' => Process::result($diff),
        ]);

        RichterServer::tool(DetectChangesTool::class, ['base' => 'some-base'])
            ->assertOk()
            ->assertStructuredContent(function (AssertableJson $json): bool {
                $json->has('base')
                    ->has('risk')
                    ->etc();

                return true;
            });
    }

    #[Test]
    public function the_tools_advertise_output_schemas_matching_their_json_presenter_shapes(): void
    {
        $impactOutputSchema = resolve(ImpactTool::class)->toArray()['outputSchema'] ?? [];
        $detectChangesOutputSchema = resolve(DetectChangesTool::class)->toArray()['outputSchema'] ?? [];

        $this->assertIsArray($impactOutputSchema);
        $this->assertIsArray($detectChangesOutputSchema);

        $impactProperties = $impactOutputSchema['properties'] ?? [];
        $detectChangesProperties = $detectChangesOutputSchema['properties'] ?? [];

        $this->assertIsArray($impactProperties);
        $this->assertIsArray($detectChangesProperties);

        $this->assertSame(['target', 'callers', 'dependencies'], array_keys($impactProperties));
        $this->assertSame([
            'base',
            'changed',
            'coverage',
            'entryPoints',
            'entryPointPaths',
            'entryPointLocations',
            'entryPointSecurity',
            'entryPointGates',
            'impacted',
            'relatedModels',
            'risk',
            'lowConfidence',
            'coarseCapApplied',
            'findings',
            'unresolved',
        ], array_keys($detectChangesProperties));
    }
}
