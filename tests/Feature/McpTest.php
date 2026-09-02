<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Feature;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Process;
use Illuminate\Testing\Fluent\AssertableJson;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\McpServiceProvider;
use Override;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Analysis\BoundedPresenter;
use SanderMuller\Richter\Analysis\JsonPresenter;
use SanderMuller\Richter\Mcp\Resources\ConfigResource;
use SanderMuller\Richter\Mcp\Resources\EntryPointsResource;
use SanderMuller\Richter\Mcp\Resources\GraphStatsResource;
use SanderMuller\Richter\Mcp\RichterServer;
use SanderMuller\Richter\Mcp\Tools\AffectedTestsTool;
use SanderMuller\Richter\Mcp\Tools\DetectChangesTool;
use SanderMuller\Richter\Mcp\Tools\ImpactTool;
use SanderMuller\Richter\Mcp\Tools\LocateTool;
use SanderMuller\Richter\Mcp\Tools\TaskSliceTool;
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
        $this->assertSame('locate', resolve(LocateTool::class)->name());
        $this->assertSame('impact', resolve(ImpactTool::class)->name());
        $this->assertSame('trace', resolve(TraceTool::class)->name());
        $this->assertSame('detect-changes', resolve(DetectChangesTool::class)->name());
        $this->assertSame('task-slice', resolve(TaskSliceTool::class)->name());
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
                    // Advisory size fields, present on every path including this early return:
                    // a consumer branching on testsTotal must never meet an undefined key.
                    // JSON has one number type, so a whole share serialises without its fraction —
                    // 0.0 arrives as 0. The schema declares `number` for exactly that reason.
                    ->where('testsShare', 0)
                    ->has('testsTotal')
                    ->has('testsExcluded')
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
    public function the_bounded_tools_reject_invalid_drilldown_arguments(): void
    {
        // The values reach handle() as mixed via Request::get(), so the input schema alone proves
        // nothing about the direct path.
        foreach ([
            ['symbol' => 'User', 'full' => 'yes'],
            ['symbol' => 'User', 'entries' => 'route::GET::/x'],
            ['symbol' => 'User', 'entries' => ['route::GET::/x', 42]],
            ['symbol' => 'User', 'entries' => ['named' => 'route::GET::/x']],
        ] as $arguments) {
            $response = resolve(ImpactTool::class)->handle(new Request($arguments));

            $this->assertInstanceOf(Response::class, $response);
            $this->assertTrue($response->isError());
        }
    }

    #[Test]
    public function the_bounded_tools_accept_valid_drilldown_arguments_end_to_end(): void
    {
        // The unit tests prove the presenter's full/entries semantics; this proves the tools
        // actually thread the arguments through — a swapped parameter would fail here at runtime.
        Process::fake([
            '*merge-base*' => Process::result("abc123\n"),
            '*diff*' => Process::result(''),
        ]);

        $detectChanges = resolve(DetectChangesTool::class)->handle(new Request(['full' => true, 'entries' => ['route::GET::/x']]));
        $this->assertInstanceOf(ResponseFactory::class, $detectChanges);
        $this->assertSame(
            BoundedPresenter::detectChanges(JsonPresenter::emptyDetectChanges('origin/main'), full: true),
            $detectChanges->getStructuredContent(),
        );

        $impact = resolve(ImpactTool::class)->handle(new Request(['symbol' => 'User', 'full' => true, 'entries' => ['route::GET::/x']]));
        $this->assertInstanceOf(ResponseFactory::class, $impact);
        $structured = $impact->getStructuredContent();
        $this->assertIsArray($structured);
        $this->assertFalse($structured['bounded']);
    }

    #[Test]
    public function the_detect_changes_tool_rejects_invalid_drilldown_arguments_even_on_an_empty_diff(): void
    {
        // Validation must run BEFORE the diff resolves: the empty-diff early return would
        // otherwise silently accept invalid input whenever the diff is empty.
        Process::fake([
            '*merge-base*' => Process::result("abc123\n"),
            '*diff*' => Process::result(''),
        ]);

        $response = resolve(DetectChangesTool::class)->handle(new Request(['full' => 'yes']));

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
    public function the_detect_changes_tool_accepts_a_head_ref(): void
    {
        // The CLI has always had `--head`; the tool did not, so an agent analysing a task parent
        // against a committed state could not use it at all. A broken head must fail the same way a
        // broken base does — as a tool error, not as a silently different range.
        $schema = resolve(DetectChangesTool::class)->toArray()['inputSchema'] ?? [];
        $this->assertIsArray($schema);
        $properties = $schema['properties'] ?? [];
        $this->assertIsArray($properties);
        $this->assertArrayHasKey('head', $properties);
        $this->assertArrayHasKey('full', $properties);
        $this->assertArrayHasKey('entries', $properties);

        $impactSchema = resolve(ImpactTool::class)->toArray()['inputSchema'] ?? [];
        $this->assertIsArray($impactSchema);
        $impactInputProperties = $impactSchema['properties'] ?? [];
        $this->assertIsArray($impactInputProperties);
        $this->assertArrayHasKey('full', $impactInputProperties);
        $this->assertArrayHasKey('entries', $impactInputProperties);

        $response = resolve(DetectChangesTool::class)->handle(new Request(['head' => 'this-ref-does-not-exist-zzz']));

        $this->assertInstanceOf(Response::class, $response);
        $this->assertTrue($response->isError());
    }

    #[Test]
    public function the_detect_changes_tool_defaults_head_to_the_working_tree(): void
    {
        // Git is faked because the claim is about the DEFAULT, not about this checkout. An earlier
        // version of this test ran real git and passed on a branch, where the configured
        // `origin/main` resolves — then failed on the tag-ref checkout of its own release, where it
        // does not. A test that reads the repository it happens to run in is testing the runner.
        Process::fake([
            '*merge-base*' => Process::result("abc123\n"),
            '*show-prefix*' => Process::result(),
            '*^{commit}*' => Process::result("abc123\n"),
            '*status*' => Process::result(''),
            '*diff*' => Process::result(''),
        ]);

        // Absent argument must behave exactly as an explicit HEAD.
        $default = resolve(DetectChangesTool::class)->handle(new Request(['base' => 'some-base']));
        $explicit = resolve(DetectChangesTool::class)->handle(new Request(['base' => 'some-base', 'head' => 'HEAD']));

        $this->assertInstanceOf(ResponseFactory::class, $default);
        $this->assertInstanceOf(ResponseFactory::class, $explicit);
        $this->assertSame($explicit->getStructuredContent(), $default->getStructuredContent());
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
                    ->has('entryPointRuntimeGuards')
                    // The skeleton graph is a leaf-sized result: nothing crosses the cap, so the
                    // bounded marker must read false while the totals are still present.
                    ->where('bounded', false)
                    ->has('callersTotal')
                    ->has('dependenciesTotal')
                    ->has('entryPointsTotal')
                    ->has('associationEntryPointsTotal')
                    ->etc();

                return true;
            });
    }

    #[Test]
    public function the_locate_tool_reports_a_symbol_with_structured_content(): void
    {
        $this->useFixtureProject();

        RichterServer::tool(LocateTool::class, ['symbol' => 'App\\Models\\Post'])
            ->assertOk()
            ->assertSee('app/Models/Post.php')
            ->assertStructuredContent(function (AssertableJson $json): bool {
                $json->where('by', 'symbol')
                    ->where('query', 'App\\Models\\Post')
                    ->has('total')
                    ->has('bounded')
                    ->has('matches')
                    ->etc();

                return true;
            });
    }

    #[Test]
    public function the_locate_tool_lists_what_a_file_defines(): void
    {
        $this->useFixtureProject();

        RichterServer::tool(LocateTool::class, ['file' => 'app/Models/Post.php'])
            ->assertOk()
            ->assertSee('defined in "app/Models/Post.php"')
            ->assertStructuredContent(function (AssertableJson $json): bool {
                $json->where('by', 'file')->etc();

                return true;
            });
    }

    #[Test]
    public function the_locate_tool_caps_by_default_because_a_tool_response_lands_in_a_context_window(): void
    {
        // The mirror of LocateCommandTest's uncapped `--json` assertion. Same document, two
        // defaults — the split BoundedPresenter's docblock draws between the surfaces.
        $this->useFixtureProject();

        RichterServer::tool(LocateTool::class, ['symbol' => 'Post'])
            ->assertOk()
            ->assertStructuredContent(function (AssertableJson $json): bool {
                $json->where('limit', BoundedPresenter::LIST_CAP)
                    ->where('bounded', true)
                    ->has('matches', BoundedPresenter::LIST_CAP)
                    ->etc();

                return true;
            });
    }

    #[Test]
    public function a_locate_miss_is_a_result_carrying_its_lead_rather_than_an_error(): void
    {
        $this->useFixtureProject();

        RichterServer::tool(LocateTool::class, ['symbol' => 'App\\Models\\Pots'])
            ->assertOk()
            ->assertSee('Nearest graph nodes:')
            ->assertStructuredContent(function (AssertableJson $json): bool {
                $json->where('total', 0)
                    ->where('matches', [])
                    ->has('suggestions')
                    ->etc();

                return true;
            });

        RichterServer::tool(LocateTool::class, ['symbol' => 'Zzz'])
            ->assertOk()
            ->assertStructuredContent(function (AssertableJson $json): bool {
                $json->has('graphNodeCount')->missing('suggestions')->etc();

                return true;
            });

        // The file lane's own miss line — never the node-shaped sentences, which are false of a path.
        RichterServer::tool(LocateTool::class, ['file' => 'app/Modles/Post.php'])
            ->assertOk()
            ->assertSee('same file name')
            ->assertStructuredContent(function (AssertableJson $json): bool {
                $json->missing('graphNodeCount')->has('suggestions')->etc();

                return true;
            });
    }

    #[Test]
    public function the_locate_tool_refuses_a_bad_argument_before_building_a_graph(): void
    {
        foreach ([[], ['symbol' => 'Post', 'file' => 'app/Models/Post.php'], ['symbol' => '  ']] as $arguments) {
            $response = resolve(LocateTool::class)->handle(new Request($arguments));

            $this->assertInstanceOf(Response::class, $response);
            $this->assertTrue($response->isError());
        }

        $badLimit = resolve(LocateTool::class)->handle(new Request(['symbol' => 'Post', 'limit' => 0]));

        $this->assertInstanceOf(Response::class, $badLimit);
        $this->assertTrue($badLimit->isError());
    }

    #[Test]
    public function a_project_with_nothing_in_it_reaches_the_locate_client_as_an_empty_answer(): void
    {
        // The mirror of LocateCommandTest's empty-project case. A missing project root yields an
        // empty graph rather than a throw, so the honest answer is "scanned 0 nodes", never an error.
        // The tool's Throwable catch is defence in depth and has no forcing seam — GraphCache and
        // CodeGraphBuilder are both final, so nothing can be injected to make a build throw.
        $app = $this->app;
        $this->assertInstanceOf(Application::class, $app);
        $app->setBasePath('/definitely-not-a-project-zzz');

        RichterServer::tool(LocateTool::class, ['symbol' => 'Post'])
            ->assertOk()
            ->assertStructuredContent(function (AssertableJson $json): bool {
                $json->where('total', 0)->where('graphNodeCount', 0)->etc();

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
        // field of the zero contract. Routed through the bounding step on both sides, exactly as
        // the tool routes it — which also pins bounded:false and zero totals on an empty result.
        RichterServer::tool(DetectChangesTool::class)
            ->assertOk()
            ->assertSee('No changed PHP files under app/')
            ->assertStructuredContent(BoundedPresenter::detectChanges(JsonPresenter::emptyDetectChanges('origin/main')));
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
        $locateOutputSchema = resolve(LocateTool::class)->toArray()['outputSchema'] ?? [];
        $impactOutputSchema = resolve(ImpactTool::class)->toArray()['outputSchema'] ?? [];
        $traceOutputSchema = resolve(TraceTool::class)->toArray()['outputSchema'] ?? [];
        $affectedTestsOutputSchema = resolve(AffectedTestsTool::class)->toArray()['outputSchema'] ?? [];
        $detectChangesOutputSchema = resolve(DetectChangesTool::class)->toArray()['outputSchema'] ?? [];

        $this->assertIsArray($affectedTestsOutputSchema);
        $affectedTestsProperties = $affectedTestsOutputSchema['properties'] ?? [];
        $this->assertIsArray($affectedTestsProperties);
        // The tool passes the selection through wholesale, so every document field is an MCP field:
        // the three size fields have to be declared here or the schema describes a shape the tool
        // does not emit.
        $this->assertSame(['base', 'determinable', 'reasons', 'tests', 'testsTotal', 'testsShare', 'testsExcluded', 'frontendTests', 'unreferencedEntryPoints', 'unresolvedDispatchSites'], array_keys($affectedTestsProperties));

        $this->assertIsArray($locateOutputSchema);
        $locateProperties = $locateOutputSchema['properties'] ?? [];
        $this->assertIsArray($locateProperties);
        // Property order mirrors the document JsonPresenter::locate() emits, `limit` included.
        $this->assertSame(['query', 'by', 'total', 'limit', 'bounded', 'matches', 'suggestions', 'graphNodeCount', 'graphFileCount'], array_keys($locateProperties));
        // The five keys every document carries are required; the four conditional ones are not, and
        // each is ABSENT rather than null when it does not apply. A schema that required the
        // conditional keys would make an honest sparse document look malformed — and one that
        // required none would let a malformed response read as an empty answer.
        $this->assertSame(['query', 'by', 'total', 'bounded', 'matches'], $locateOutputSchema['required'] ?? []);

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
            'associationEntryPointsVia',
            'entryPointPaths',
            'entryPointLocations',
            'entryPointSecurity',
            'entryPointGates',
            'entryPointAuthGates',
            'entryPointRuntimeGuards',
            'entryPointTestReferences',
            'bounded',
            'callersTotal',
            'dependenciesTotal',
            'entryPointsTotal',
            'associationEntryPointsTotal',
        ], array_keys($impactProperties));
        $this->assertSame(['from', 'to', 'resolvedFrom', 'resolvedTo', 'found', 'path', 'furthestReached'], array_keys($traceProperties));
        $this->assertSame([
            'base',
            'changed',
            'coverage',
            'entryPoints',
            'associationEntryPoints',
            'associationEntryPointsVia',
            'entryPointPaths',
            'entryPointLocations',
            'entryPointSecurity',
            'entryPointGates',
            'entryPointRuntimeGuards',
            'entryPointTestReferences',
            'entryPointAttribution',
            'entryPointKeepSet',
            'impacted',
            'relatedModels',
            'traitAndOverrideReach',
            'traitAndOverrideReachVia',
            'risk',
            'riskCause',
            'hazards',
            'verification',
            'lowConfidence',
            'findings',
            'unresolved',
            'bounded',
            'entryPointsTotal',
            'associationEntryPointsTotal',
            'relatedModelsTotal',
            'traitAndOverrideReachTotal',
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

    #[Test]
    public function the_impact_tool_reads_a_route_as_referenced_through_its_handler_class(): void
    {
        // The MCP half of the contract `CommandsTest::impact_reads_a_route_as_referenced_through_its_handler_class`
        // covers for the console. The stub names neither the route nor its URI — only the controller —
        // so the surface resolves solely through the graph. Naming either would destroy the point.
        $this->useFixtureProject();
        $testsDir = self::fixtureProjectPath() . '/tests';
        // The cleanup below removes this tree. Fail loudly rather than delete a real fixture dir if
        // one is ever added.
        $this->assertDirectoryDoesNotExist($testsDir);
        $test = $testsDir . '/Feature/SocialAuthTest.php';
        @mkdir(dirname($test), 0777, true);
        file_put_contents($test, "<?php\n\nuse App\\Http\\Controllers\\Auth\\SocialAuthController;\n");

        try {
            RichterServer::tool(ImpactTool::class, ['symbol' => 'App\\Http\\Controllers\\Auth\\SocialAuthController'])
                ->assertOk()
                ->assertStructuredContent(function (AssertableJson $json): bool {
                    // The weak sub-tag, not plain `referenced`: the stub asserts nothing. What this
                    // pins is that the route is referenced at all — without the graph it reads
                    // `unreferenced`.
                    $json->where('entryPointTestReferences', ['route::GET::/auth/login' => 'referenced-no-behavioural-assertion'])
                        ->etc();

                    return true;
                });
        } finally {
            $this->deleteTree($testsDir);
        }
    }

    private function useFixtureProject(): void
    {
        $app = $this->app;
        $this->assertInstanceOf(Application::class, $app);
        $app->setBasePath(self::fixtureProjectPath());
    }
}
