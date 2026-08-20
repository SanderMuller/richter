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
use SanderMuller\Richter\Mcp\Resources\ConfigResource;
use SanderMuller\Richter\Mcp\Resources\EntryPointsResource;
use SanderMuller\Richter\Mcp\Resources\GraphStatsResource;
use SanderMuller\Richter\Mcp\RichterServer;
use SanderMuller\Richter\Mcp\Tools\AffectedTestsTool;
use SanderMuller\Richter\Mcp\Tools\DetectChangesTool;
use SanderMuller\Richter\Mcp\Tools\ImpactTool;
use SanderMuller\Richter\Mcp\Tools\TraceTool;
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
        $this->assertSame('trace', resolve(TraceTool::class)->name());
        $this->assertSame('detect-changes', resolve(DetectChangesTool::class)->name());
        $this->assertSame('affected-tests', resolve(AffectedTestsTool::class)->name());
    }

    #[Test]
    public function the_affected_tests_tool_reports_an_empty_diff_as_a_determinable_empty_selection(): void
    {
        Process::fake([
            '*merge-base*' => Process::result("abc123\n"),
            '*diff*' => Process::result(''),
            '*status*' => Process::result(''),
        ]);

        RichterServer::tool(AffectedTestsTool::class)
            ->assertOk()
            ->assertSee('Affected tests: 0')
            ->assertStructuredContent(function (AssertableJson $json): bool {
                $json->where('determinable', true)
                    ->where('base', 'origin/main')
                    ->where('unreferencedEntryPoints', 0)
                    ->has('reasons')
                    ->has('tests')
                    ->has('frontendTests')
                    ->has('unresolvedDispatchSites');

                return true;
            });
    }

    #[Test]
    public function the_affected_tests_tool_reports_an_untracked_file_as_not_determinable(): void
    {
        Process::fake([
            '*merge-base*' => Process::result("abc123\n"),
            '*rev-parse*' => Process::result(),
            '*diff*' => Process::result(''),
            '*status*' => Process::result("?? app/Models/Report.php\n"),
        ]);

        RichterServer::tool(AffectedTestsTool::class, ['base' => 'some-base'])
            ->assertOk()
            ->assertSee('run the full suite')
            ->assertStructuredContent(function (AssertableJson $json): bool {
                $json->where('determinable', false)
                    ->etc();

                return true;
            });
    }

    #[Test]
    public function the_affected_tests_tool_reports_an_unresolvable_base_as_not_determinable_where_detect_changes_errors(): void
    {
        // The Resolved Question 2 contrast, pinned side by side: the SAME unresolvable
        // ref is a tool error for detect-changes but determinable:false for
        // affected-tests, whose fail-safe answer ("run the full suite") must stay
        // visible in the result shape.
        Process::fake([
            '*merge-base*' => Process::result(errorOutput: 'fatal: bad revision', exitCode: 128),
            '*rev-parse*' => Process::result(),
            '*status*' => Process::result(''),
        ]);

        $detectChanges = resolve(DetectChangesTool::class)->handle(new Request(['base' => 'this-ref-does-not-exist-zzz']));
        $this->assertInstanceOf(Response::class, $detectChanges);
        $this->assertTrue($detectChanges->isError());

        RichterServer::tool(AffectedTestsTool::class, ['base' => 'this-ref-does-not-exist-zzz'])
            ->assertOk()
            ->assertSee('run the full suite')
            ->assertStructuredContent(function (AssertableJson $json): bool {
                $json->where('determinable', false)
                    ->etc();

                return true;
            });
    }

    #[Test]
    public function the_affected_tests_tool_reports_a_malformed_base_as_not_determinable_never_an_error(): void
    {
        // Resolved Question 2: every non-determinable cause is determinable:false +
        // reasons — never Response::error like DetectChangesTool maps the same
        // exception. "Run the full suite" must stay the visible, actionable answer.
        Process::fake([
            '*status*' => Process::result(''),
        ]);

        RichterServer::tool(AffectedTestsTool::class, ['base' => '--upload-pack=x'])
            ->assertOk()
            ->assertSee('run the full suite')
            ->assertStructuredContent(function (AssertableJson $json): bool {
                $json->where('determinable', false)
                    ->where('base', '--upload-pack=x')
                    ->etc();

                return true;
            });
    }

    #[Test]
    public function the_trace_tool_rejects_missing_arguments(): void
    {
        $response = resolve(TraceTool::class)->handle(new Request(['from' => 'App\\Models\\User']));

        $this->assertInstanceOf(Response::class, $response);
        $this->assertTrue($response->isError());
    }

    #[Test]
    public function the_trace_tool_reports_an_unresolvable_symbol_as_an_error(): void
    {
        // Deliberately stricter than impact: an empty trace would read as "no path".
        $this->useFixtureProject();

        $response = resolve(TraceTool::class)->handle(new Request(['from' => 'Zzz\\Nonexistent\\Symbol', 'to' => 'App\\Models\\Post']));

        $this->assertInstanceOf(Response::class, $response);
        $this->assertTrue($response->isError());
    }

    #[Test]
    public function the_trace_tool_reports_a_found_path_with_structured_content(): void
    {
        $this->useFixtureProject();

        RichterServer::tool(TraceTool::class, ['from' => 'ReviewController', 'to' => 'App\\Jobs\\ProcessPostJob'])
            ->assertOk()
            ->assertSee('Path from')
            ->assertStructuredContent(function (AssertableJson $json): bool {
                $json->where('found', true)
                    ->has('resolvedFrom')
                    ->has('resolvedTo')
                    ->has('path')
                    ->etc();

                return true;
            });
    }

    #[Test]
    public function the_trace_tool_reports_the_upstream_extent_on_a_miss(): void
    {
        // Strict direction: the job never reaches the controller, and the controller's
        // callers (the route) give the furthest-reached diagnostic.
        $this->useFixtureProject();

        RichterServer::tool(TraceTool::class, ['from' => 'App\\Jobs\\ProcessPostJob', 'to' => 'ReviewController'])
            ->assertOk()
            ->assertSee('Swap the arguments')
            ->assertStructuredContent(function (AssertableJson $json): bool {
                $json->where('found', false)
                    ->has('furthestReached')
                    ->etc();

                return true;
            });
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
                    ->has('dependencies')
                    ->has('entryPoints')
                    ->has('entryPointTestReferences')
                    ->etc();

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
                    ->has('entryPointTestReferences')
                    ->etc();

                return true;
            });
    }

    #[Test]
    public function the_tools_advertise_output_schemas_matching_their_json_presenter_shapes(): void
    {
        $impactOutputSchema = resolve(ImpactTool::class)->toArray()['outputSchema'] ?? [];
        $traceOutputSchema = resolve(TraceTool::class)->toArray()['outputSchema'] ?? [];
        $affectedTestsOutputSchema = resolve(AffectedTestsTool::class)->toArray()['outputSchema'] ?? [];
        $detectChangesOutputSchema = resolve(DetectChangesTool::class)->toArray()['outputSchema'] ?? [];

        $this->assertIsArray($affectedTestsOutputSchema);
        $affectedTestsProperties = $affectedTestsOutputSchema['properties'] ?? [];
        $this->assertIsArray($affectedTestsProperties);
        $this->assertSame(['base', 'determinable', 'reasons', 'tests', 'frontendTests', 'unreferencedEntryPoints', 'unresolvedDispatchSites'], array_keys($affectedTestsProperties));

        $this->assertIsArray($impactOutputSchema);
        $this->assertIsArray($traceOutputSchema);
        $this->assertIsArray($detectChangesOutputSchema);

        $impactProperties = $impactOutputSchema['properties'] ?? [];
        $traceProperties = $traceOutputSchema['properties'] ?? [];
        $detectChangesProperties = $detectChangesOutputSchema['properties'] ?? [];

        $this->assertIsArray($impactProperties);
        $this->assertIsArray($traceProperties);
        $this->assertIsArray($detectChangesProperties);

        $this->assertSame([
            'target',
            'callers',
            'dependencies',
            'entryPoints',
            'associationEntryPoints',
            'entryPointPaths',
            'entryPointLocations',
            'entryPointSecurity',
            'entryPointGates',
            'entryPointAuthGates',
            'entryPointTestReferences',
        ], array_keys($impactProperties));
        $this->assertSame(['from', 'to', 'resolvedFrom', 'resolvedTo', 'found', 'path', 'furthestReached'], array_keys($traceProperties));
        $this->assertSame([
            'base',
            'changed',
            'coverage',
            'entryPoints',
            'associationEntryPoints',
            'entryPointPaths',
            'entryPointLocations',
            'entryPointSecurity',
            'entryPointGates',
            'entryPointTestReferences',
            'impacted',
            'relatedModels',
            'traitAndOverrideReach',
            'traitAndOverrideReachVia',
            'risk',
            'lowConfidence',
            'coarseCapApplied',
            'scoredEntryPoints',
            'scoredImpacted',
            'findings',
            'unresolved',
        ], array_keys($detectChangesProperties));
    }

    #[Test]
    public function the_entry_points_resource_lists_the_fixture_surfaces_with_kind_and_location(): void
    {
        $this->useFixtureProject();

        RichterServer::resource(EntryPointsResource::class)
            ->assertOk()
            ->assertSee('route::GET::/posts/{post}/reviews')
            ->assertSee('"kind": "route"')
            ->assertSee('ui-component')
            ->assertSee('"count":');
    }

    #[Test]
    public function the_graph_stats_resource_reports_counts_and_honesty_flags(): void
    {
        $this->useFixtureProject();

        RichterServer::resource(GraphStatsResource::class)
            ->assertOk()
            ->assertSee('"nodes":')
            ->assertSee('"edgesByType":')
            ->assertSee('"hasUnparseableFiles":')
            ->assertSee('"hasUnresolvedDispatches":');
    }

    #[Test]
    public function the_config_resource_reports_the_effective_analysis_config(): void
    {
        RichterServer::resource(ConfigResource::class)
            ->assertOk()
            ->assertSee('"default_base": "origin/main"')
            ->assertSee('"entry_point_roots":')
            ->assertSee('"frontend":')
            ->assertSee('"parallel": false');
    }

    private function useFixtureProject(): void
    {
        $app = $this->app;
        $this->assertInstanceOf(Application::class, $app);
        $app->setBasePath(self::fixtureProjectPath());
    }
}
