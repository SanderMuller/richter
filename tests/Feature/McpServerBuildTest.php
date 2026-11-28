<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Iterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Analysis\AffectedTests;
use SanderMuller\Richter\Analysis\ImpactAnalyzer;
use SanderMuller\Richter\Analysis\TestReferenceIndex;
use SanderMuller\Richter\Changes\ChangedFileSymbols;
use SanderMuller\Richter\Changes\ChangedSymbols;
use SanderMuller\Richter\Graph\CodeGraph;
use SanderMuller\Richter\Graph\CodeGraphBuilder;
use SanderMuller\Richter\Tests\TestCase;
use SanderMuller\Richter\Tracers\ClassHierarchyTracer;

/**
 * A `laravel/mcp` server as an entry surface: the registration in a route file, joined to the
 * primitives the server class lists. Over a temp project and through the build, because the two
 * halves live in different files.
 */
final class McpServerBuildTest extends TestCase
{
    private const string SERVER = 'App\Mcp\Servers\OrderServer';

    private const string NODE = 'mcp::' . self::SERVER;

    private const string TOOL = 'App\Mcp\Tools\FindOrderTool';

    private string $projectRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projectRoot = sys_get_temp_dir() . '/richter-mcp-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        new Filesystem()->deleteDirectory($this->projectRoot);
        parent::tearDown();
    }

    private static function server(string $body = 'protected array $tools = [FindOrderTool::class, ListOrdersTool::class];', string $extends = 'Server', string $class = 'OrderServer'): string
    {
        return "<?php\nnamespace App\\Mcp\\Servers;\nuse App\\Mcp\\Prompts\\SummarisePrompt;\nuse App\\Mcp\\Resources\\OrderResource;\nuse App\\Mcp\\Tools\\FindOrderTool;\nuse App\\Mcp\\Tools\\ListOrdersTool;\nuse Laravel\\Mcp\\Server;\nclass {$class} extends {$extends}\n{\n    {$body}\n}\n";
    }

    private function routes(string $body): string
    {
        return "<?php\nuse App\\Mcp\\Servers\\OrderServer;\nuse Illuminate\\Support\\Facades\\Route;\nuse Laravel\\Mcp\\Facades\\Mcp;\n{$body}\n";
    }

    /**
     * @param  array<string, string>  $files  project-relative path => source
     * @return list<array{source: string, target: string, type: string}>
     */
    private function mcpEdges(array $files): array
    {
        $files += [
            'app/Mcp/Tools/FindOrderTool.php' => "<?php\nnamespace App\\Mcp\\Tools;\nuse App\\Services\\OrderFinder;\nuse Laravel\\Mcp\\Server\\Tool;\nfinal class FindOrderTool extends Tool\n{\n    public function handle(): string { return new OrderFinder()->find(); }\n}\n",
            'app/Services/OrderFinder.php' => "<?php\nnamespace App\\Services;\nfinal class OrderFinder\n{\n    public function find(): string { return 'order'; }\n}\n",
        ];

        foreach ($files as $path => $source) {
            File::ensureDirectoryExists(dirname("{$this->projectRoot}/{$path}"));
            File::put("{$this->projectRoot}/{$path}", $source);
        }

        return array_values(array_filter(
            new CodeGraphBuilder()->buildTracerBranch($this->projectRoot)['edges'],
            static fn (array $edge): bool => str_starts_with($edge['type'], 'mcp-'),
        ));
    }

    /**
     * @param  list<array{source: string, target: string, type: string}>  $edges
     * @return list<string>
     */
    private function rendered(array $edges): array
    {
        $lines = array_map(static fn (array $edge): string => "{$edge['source']} -[{$edge['type']}]-> {$edge['target']}", $edges);
        sort($lines);

        return $lines;
    }

    #[Test]
    public function a_web_server_links_its_route_to_every_primitive_it_lists(): void
    {
        $edges = $this->mcpEdges([
            'routes/ai.php' => $this->routes("Mcp::web('/mcp', OrderServer::class)->middleware(['auth:api']);"),
            'app/Mcp/Servers/OrderServer.php' => self::server('protected array $tools = [FindOrderTool::class, ListOrdersTool::class]; protected array $resources = [OrderResource::class]; protected array $prompts = [SummarisePrompt::class];'),
        ]);

        $this->assertSame([
            self::NODE . ' -[mcp-server-to-primitive]-> App\Mcp\Prompts\SummarisePrompt::handle',
            self::NODE . ' -[mcp-server-to-primitive]-> App\Mcp\Resources\OrderResource::handle',
            self::NODE . ' -[mcp-server-to-primitive]-> App\Mcp\Tools\FindOrderTool::handle',
            self::NODE . ' -[mcp-server-to-primitive]-> App\Mcp\Tools\ListOrdersTool::handle',
            'route::POST::/mcp -[mcp-route-to-server]-> ' . self::NODE,
        ], $this->rendered($edges));
    }

    #[Test]
    public function code_behind_a_tool_reaches_the_server_and_its_route_as_entry_points(): void
    {
        $this->mcpEdges([
            'routes/ai.php' => $this->routes("Mcp::web('/mcp', OrderServer::class);"),
            'app/Mcp/Servers/OrderServer.php' => self::server(),
        ]);

        $branch = new CodeGraphBuilder()->buildTracerBranch($this->projectRoot);
        $graph = new CodeGraph($branch['edges'], hasUnparseableFiles: false);
        $callers = array_column($graph->callersOf(['App\Services\OrderFinder::find']), 'node');

        $this->assertContains(self::NODE, $callers);
        $this->assertContains('route::POST::/mcp', $callers);

        $entryPoints = array_values(array_filter($callers, static fn (string $node): bool => array_any(ImpactAnalyzer::ENTRY_POINT_PREFIXES, static fn (string $prefix): bool => str_starts_with($node, $prefix))));
        sort($entryPoints);

        $this->assertSame([self::NODE, 'route::POST::/mcp'], $entryPoints);
    }

    #[Test]
    public function a_local_server_gets_a_node_and_no_route(): void
    {
        $edges = $this->mcpEdges([
            'routes/ai.php' => $this->routes("Mcp::local('orders', OrderServer::class);"),
            'app/Mcp/Servers/OrderServer.php' => self::server('protected array $tools = [FindOrderTool::class];'),
        ]);

        $this->assertSame([self::NODE . ' -[mcp-server-to-primitive]-> ' . self::TOOL . '::handle'], $this->rendered($edges));
    }

    #[Test]
    public function a_server_registered_both_ways_has_one_node_and_one_route(): void
    {
        $edges = $this->mcpEdges([
            'routes/ai.php' => $this->routes("Mcp::local('orders', OrderServer::class);\nMcp::web('mcp', OrderServer::class);"),
            'app/Mcp/Servers/OrderServer.php' => self::server('protected array $tools = [FindOrderTool::class];'),
        ]);

        $this->assertSame([
            self::NODE . ' -[mcp-server-to-primitive]-> ' . self::TOOL . '::handle',
            'route::POST::/mcp -[mcp-route-to-server]-> ' . self::NODE,
        ], $this->rendered($edges));
    }

    /** @return Iterator<string, array{string, string}> */
    public static function registrationsWithAReadableUri(): Iterator
    {
        yield 'no leading slash' => ["Mcp::web('mcp', OrderServer::class);", '/mcp'];
        yield 'a nested path' => ["Mcp::web('/help/mcp/', OrderServer::class);", '/help/mcp'];
        yield 'a literal prefix on the group' => ["Route::prefix('api')->group(function (): void { Mcp::web('/mcp', OrderServer::class); });", '/api/mcp'];
        yield 'a prefix among other group calls' => ["Route::middleware('auth')->prefix('/api/')->domain('example.test')->group(function (): void { Mcp::web('mcp', OrderServer::class); });", '/api/mcp'];
        yield 'a prefix in the attribute array' => ["Route::group(['prefix' => 'v2', 'middleware' => 'auth'], function (): void { Mcp::web('/mcp', OrderServer::class); });", '/v2/mcp'];
        yield 'nested literal prefixes' => ["Route::prefix('api')->group(function (): void { Route::prefix('v2')->group(function (): void { Mcp::web('/mcp', OrderServer::class); }); });", '/api/v2/mcp'];
        yield 'a group with no prefix' => ["Route::middleware('auth')->group(function (): void { Mcp::web('/mcp', OrderServer::class)->middleware('throttle'); });", '/mcp'];
        yield 'after a group with a prefix has closed' => ["Route::prefix('api')->group(function (): void { Route::get('/ping', fn () => 'pong'); });\nMcp::web('/mcp', OrderServer::class);", '/mcp'];
        yield 'a group written as an arrow function' => ["Route::prefix('api')->group(fn () => Mcp::web('/mcp', OrderServer::class));", '/api/mcp'];
        yield 'inside a condition' => ["if (config('mcp.enabled')) { Mcp::web('/mcp', OrderServer::class); }", '/mcp'];
    }

    #[Test]
    #[DataProvider('registrationsWithAReadableUri')]
    public function the_route_node_carries_the_uri_the_router_will(string $registration, string $uri): void
    {
        $edges = $this->mcpEdges([
            'routes/ai.php' => $this->routes($registration),
            'app/Mcp/Servers/OrderServer.php' => self::server('protected array $tools = [FindOrderTool::class];'),
        ]);

        $this->assertContains("route::POST::{$uri} -[mcp-route-to-server]-> " . self::NODE, $this->rendered($edges));
        $this->assertNotContains('mcp-uri-unread', array_column($edges, 'type'));
    }

    /** @return Iterator<string, array{string, string}> */
    public static function registrationsWithoutAReadableUri(): Iterator
    {
        yield 'a computed route' => ["Mcp::web(config('mcp.path'), OrderServer::class);", 'routes/ai.php'];
        yield 'a computed prefix' => ["Route::prefix(config('mcp.prefix'))->group(function (): void { Mcp::web('/mcp', OrderServer::class); });", 'routes/ai.php'];
        yield 'a computed prefix in the attribute array' => ["Route::group(['prefix' => \$prefix], function (): void { Mcp::web('/mcp', OrderServer::class); });", 'routes/ai.php'];
        yield 'an attribute array that is not written out' => ["Route::group(\$attributes, function (): void { Mcp::web('/mcp', OrderServer::class); });", 'routes/ai.php'];
        yield 'a prefix on the returned route' => ["Mcp::web('/mcp', OrderServer::class)->prefix('api');", 'routes/ai.php'];
        yield 'two prefixes on one group' => ["Route::prefix('a')->prefix('b')->group(function (): void { Mcp::web('/mcp', OrderServer::class); });", 'routes/ai.php'];
        yield 'a closure something else runs' => ["when(true, function (): void { Mcp::web('/mcp', OrderServer::class); });", 'routes/ai.php'];
        yield 'a route file a provider loads' => ["Mcp::web('/mcp', OrderServer::class);", 'routes/api.php'];
    }

    #[Test]
    #[DataProvider('registrationsWithoutAReadableUri')]
    public function a_uri_that_cannot_be_read_draws_no_route_and_says_so(string $registration, string $file): void
    {
        $edges = $this->mcpEdges([
            $file => $this->routes($registration),
            'app/Mcp/Servers/OrderServer.php' => self::server('protected array $tools = [FindOrderTool::class];'),
        ]);

        $this->assertSame([
            self::NODE . ' -[mcp-server-to-primitive]-> ' . self::TOOL . '::handle',
            self::NODE . ' -[mcp-uri-unread]-> mcp-unread::' . self::SERVER,
        ], $this->rendered($edges));
    }

    /** @return Iterator<string, array{array<string, string>}> */
    public static function serversWhosePrimitivesCannotBeRead(): Iterator
    {
        yield 'a spread in the list' => [['app/Mcp/Servers/OrderServer.php' => self::server('protected array $tools = [FindOrderTool::class, ...self::EXTRA];')]];
        yield 'a string in the list' => [['app/Mcp/Servers/OrderServer.php' => self::server('protected array $tools = [FindOrderTool::class, "App\\\\Mcp\\\\Tools\\\\ListOrdersTool"];')]];
        yield 'a list with no default' => [['app/Mcp/Servers/OrderServer.php' => self::server('protected array $tools;')]];
        yield 'a boot override' => [['app/Mcp/Servers/OrderServer.php' => self::server('protected array $tools = [FindOrderTool::class]; protected function boot(): void {}')]];
        yield 'a boot override in the parent' => [[
            'app/Mcp/Servers/OrderServer.php' => self::server('protected array $tools = [FindOrderTool::class];', 'BaseServer'),
            'app/Mcp/Servers/BaseServer.php' => self::server('protected function boot(): void {}', class: 'BaseServer'),
        ]];
        yield 'a listed skill' => [['app/Mcp/Servers/OrderServer.php' => self::server('protected array $tools = [FindOrderTool::class]; protected array $skills = ["resources/skills/orders"];')]];
        yield 'a list filled in the constructor' => [['app/Mcp/Servers/OrderServer.php' => self::server('protected array $tools = [FindOrderTool::class]; public function __construct() { $this->tools[] = ListOrdersTool::class; }')]];
        yield 'a trait on the server' => [['app/Mcp/Servers/OrderServer.php' => self::server('use \App\Mcp\Servers\Concerns\RegistersTools; protected array $tools = [FindOrderTool::class];')]];
        yield 'a parent outside the scanned tree' => [['app/Mcp/Servers/OrderServer.php' => self::server('protected array $tools = [FindOrderTool::class];', '\Vendor\Package\SharedServer')]];
        yield 'a server class outside the scanned tree' => [[]];
    }

    /** @param  array<string, string>  $files */
    #[Test]
    #[DataProvider('serversWhosePrimitivesCannotBeRead')]
    public function a_primitive_list_that_cannot_be_read_draws_no_primitive_and_says_so(array $files): void
    {
        $edges = $this->mcpEdges(['routes/ai.php' => $this->routes("Mcp::local('orders', OrderServer::class);")] + $files);

        $this->assertSame([self::NODE . ' -[mcp-primitives-unread]-> mcp-unread::' . self::SERVER], $this->rendered($edges));
    }

    #[Test]
    public function a_server_named_by_a_variable_is_marked_as_one_this_cannot_read(): void
    {
        $edges = $this->mcpEdges(['routes/ai.php' => $this->routes("Mcp::web('/mcp', \$server);")]);

        $this->assertSame(
            ['mcp::(unknown) -[mcp-primitives-unread]-> mcp-unread::(unknown)'],
            array_values(array_filter($this->rendered($edges), static fn (string $edge): bool => str_contains($edge, 'primitives-unread'))),
        );
    }

    #[Test]
    public function a_list_the_subclass_declares_replaces_the_parents_and_the_rest_is_inherited(): void
    {
        $edges = $this->mcpEdges([
            'routes/ai.php' => $this->routes("Mcp::local('orders', OrderServer::class);"),
            'app/Mcp/Servers/OrderServer.php' => self::server('protected array $tools = [FindOrderTool::class];', 'BaseServer'),
            'app/Mcp/Servers/BaseServer.php' => self::server('protected array $tools = [ListOrdersTool::class]; protected array $prompts = [SummarisePrompt::class];', class: 'BaseServer'),
        ]);

        $this->assertSame([
            self::NODE . ' -[mcp-server-to-primitive]-> App\Mcp\Prompts\SummarisePrompt::handle',
            self::NODE . ' -[mcp-server-to-primitive]-> ' . self::TOOL . '::handle',
        ], $this->rendered($edges));
    }

    #[Test]
    public function a_tool_whose_handle_lives_in_an_abstract_parent_is_still_reached(): void
    {
        // The template-method shape: a final `handle()` in a shared base that calls `run()` on the
        // tool. The server edge names the listed class, and the override edge carries it to `run()`.
        $this->mcpEdges([
            'routes/ai.php' => $this->routes("Mcp::web('/mcp', OrderServer::class);"),
            'app/Mcp/Servers/OrderServer.php' => self::server('protected array $tools = [FindOrderTool::class];'),
            'app/Mcp/Tools/StaffTool.php' => "<?php\nnamespace App\\Mcp\\Tools;\nuse Laravel\\Mcp\\Server\\Tool;\nabstract class StaffTool extends Tool\n{\n    final public function handle(): string { return \$this->run(); }\n    abstract protected function run(): string;\n}\n",
            'app/Mcp/Tools/FindOrderTool.php' => "<?php\nnamespace App\\Mcp\\Tools;\nuse App\\Services\\OrderFinder;\nfinal class FindOrderTool extends StaffTool\n{\n    protected function run(): string { return new OrderFinder()->find(); }\n}\n",
        ]);

        $built = new CodeGraphBuilder()->buildTracerBranch($this->projectRoot);
        $edges = [...$built['edges'], ...ClassHierarchyTracer::inheritedEdgesFor($built['inheritance'], $built['edges'])];
        $callers = array_column(new CodeGraph($edges, hasUnparseableFiles: false)->callersOf(['App\Services\OrderFinder::find']), 'node');

        $this->assertContains(self::NODE, $callers);
        $this->assertContains('route::POST::/mcp', $callers);
    }

    #[Test]
    public function a_project_without_mcp_draws_nothing(): void
    {
        $this->assertSame([], $this->mcpEdges(['routes/web.php' => "<?php\nuse Illuminate\\Support\\Facades\\Route;\nRoute::get('/', fn () => 'home');\n"]));
        $this->assertSame([], $this->mcpEdges([]));
    }

    #[Test]
    public function a_test_that_names_the_server_references_it_and_one_that_posts_to_it_references_the_route(): void
    {
        $index = new TestReferenceIndex();
        $index->addSource("<?php\nuse App\\Mcp\\Servers\\OrderServer;\nuse App\\Mcp\\Tools\\FindOrderTool;\nOrderServer::tool(FindOrderTool::class);\n", 'tests/Feature/Mcp/ToolsTest.php');
        $index->addSource("<?php\n\$this->postJson('/mcp', []);\n", 'tests/Feature/Mcp/EndpointTest.php');
        $index->addSource("<?php\nuse App\\Models\\Post;\n", 'tests/Feature/PostTest.php');

        $this->assertSame(['tests/Feature/Mcp/ToolsTest.php'], $index->testsReferencing(self::NODE));
        $this->assertSame(['tests/Feature/Mcp/EndpointTest.php'], $index->testsReferencing('route::POST::/mcp'));
        $this->assertSame([], $index->testsReferencing('mcp::App\Mcp\Servers\OtherServer'));
    }

    #[Test]
    public function a_change_behind_a_tool_selects_both_kinds_of_test(): void
    {
        $index = new TestReferenceIndex();
        $index->addSource("<?php\nuse App\\Mcp\\Servers\\OrderServer;\nOrderServer::tool('find-order');\n", 'tests/Feature/Mcp/ToolsTest.php');
        $index->addSource("<?php\n\$this->postJson('/mcp', []);\n", 'tests/Feature/Mcp/EndpointTest.php');
        $index->addSource("<?php\nuse App\\Models\\Post;\n", 'tests/Feature/PostTest.php');

        $selection = AffectedTests::select(
            ['coverage' => ['app/Services/OrderFinder.php' => 'analyzed'], 'entryPoints' => [self::NODE, 'route::POST::/mcp'], 'lowConfidence' => false],
            [new ChangedFileSymbols('app/Services/OrderFinder.php', 'App\Services\OrderFinder', [], cosmeticOnly: false)],
            $index,
            unresolvedDispatchSites: [],
        );

        $this->assertTrue($selection['determinable']);
        $this->assertSame(['tests/Feature/Mcp/EndpointTest.php', 'tests/Feature/Mcp/ToolsTest.php'], $selection['tests']);
    }

    /**
     * The whole path for a metadata edit: the classified change, the analysis over the built graph,
     * and the selection. A coarse class seed withdrew it at low confidence.
     */
    #[Test]
    public function a_metadata_edit_on_a_listed_tool_selects_the_tests_behind_its_server(): void
    {
        $tool = static fn (string $description): string => "<?php\nnamespace App\\Mcp\\Tools;\nuse App\\Services\\OrderFinder;\nuse Laravel\\Mcp\\Server\\Attributes\\Description;\nuse Laravel\\Mcp\\Server\\Tool;\n#[Description('{$description}')]\nfinal class FindOrderTool extends Tool\n{\n    public function handle(): string { return new OrderFinder()->find(); }\n}\n";
        $this->mcpEdges([
            'routes/ai.php' => $this->routes("Mcp::web('/mcp', OrderServer::class);"),
            'app/Mcp/Servers/OrderServer.php' => self::server(),
            'app/Mcp/Tools/FindOrderTool.php' => $tool('After'),
        ]);
        $graph = new CodeGraph(new CodeGraphBuilder()->buildTracerBranch($this->projectRoot)['edges'], hasUnparseableFiles: false);
        $changed = [ChangedSymbols::classifyFile('app/Mcp/Tools/FindOrderTool.php', $tool('After'), $tool('Before'), [
            'added' => [['line' => 6, 'text' => "#[Description('After')]"]],
            'removed' => [['line' => 6, 'text' => "#[Description('Before')]"]],
        ])];
        $index = new TestReferenceIndex();
        $index->addSource("<?php\n\$this->postJson('/mcp', []);\n", 'tests/Feature/Mcp/EndpointTest.php');
        $index->addSource("<?php\nuse App\\Models\\Post;\n", 'tests/Feature/PostTest.php');

        $result = new ImpactAnalyzer($graph)->detectChanges($changed);
        $selection = AffectedTests::select($result, $changed, $index, [], $graph);

        $this->assertFalse($result['lowConfidence']);
        $this->assertSame([], $selection['reasons']);
        $this->assertTrue($selection['determinable']);
        $this->assertSame(['tests/Feature/Mcp/EndpointTest.php'], $selection['tests']);
    }

    #[Test]
    public function a_reached_server_without_a_readable_uri_withdraws_the_selection(): void
    {
        $graph = new CodeGraph([
            ['source' => self::NODE, 'target' => self::TOOL . '::handle', 'type' => 'mcp-server-to-primitive'],
            ['source' => self::NODE, 'target' => 'mcp-unread::' . self::SERVER, 'type' => 'mcp-uri-unread'],
        ], hasUnparseableFiles: false);
        $changed = [new ChangedFileSymbols('app/Services/OrderFinder.php', 'App\Services\OrderFinder', [], cosmeticOnly: false)];

        $reached = AffectedTests::select(['coverage' => [], 'entryPoints' => [self::NODE], 'lowConfidence' => false], $changed, new TestReferenceIndex(), [], $graph);

        $this->assertFalse($reached['determinable']);
        $this->assertStringContainsString('whose URL could not be read', $reached['reasons'][0]);
        $this->assertStringContainsString(self::SERVER, $reached['reasons'][0]);

        $elsewhere = AffectedTests::select(['coverage' => [], 'entryPoints' => ['route::GET::/posts'], 'lowConfidence' => false], $changed, new TestReferenceIndex(), [], $graph);

        $this->assertSame([], array_values(array_filter($elsewhere['reasons'], static fn (string $reason): bool => str_contains($reason, 'MCP'))));
    }

    #[Test]
    public function an_unread_primitive_list_withdraws_the_selection_only_for_a_change_a_primitive_can_run(): void
    {
        $graph = new CodeGraph([
            ['source' => self::NODE, 'target' => 'mcp-unread::' . self::SERVER, 'type' => 'mcp-primitives-unread'],
        ], hasUnparseableFiles: false);
        $result = ['coverage' => [], 'entryPoints' => [], 'lowConfidence' => false];

        // `Post` is a loadable class that is no MCP primitive, so no hidden server edge can lead to it.
        $model = AffectedTests::select($result, [new ChangedFileSymbols('app/Models/Post.php', 'App\Models\Post', [], cosmeticOnly: false)], new TestReferenceIndex(), [], $graph);
        $this->assertTrue($model['determinable']);

        // A class that cannot be loaded cannot be shown not to be one.
        $unknown = AffectedTests::select($result, [new ChangedFileSymbols('app/Mcp/Tools/HiddenTool.php', 'App\Mcp\Tools\HiddenTool', [], cosmeticOnly: false)], new TestReferenceIndex(), [], $graph);
        $this->assertFalse($unknown['determinable']);
        $this->assertStringContainsString('could not be read, and one of them can run the change: ' . self::SERVER, $unknown['reasons'][0]);

        // The same class reached as a caller of the change, not changed itself.
        $caller = AffectedTests::select(
            $result + ['callers' => [['depth' => 1, 'node' => 'App\Mcp\Tools\HiddenTool::handle', 'via' => 'instance-call']]],
            [new ChangedFileSymbols('app/Models/Post.php', 'App\Models\Post', [], cosmeticOnly: false)],
            new TestReferenceIndex(),
            [],
            $graph,
        );
        $this->assertFalse($caller['determinable']);
    }

    #[Test]
    public function the_worker_process_draws_the_same_mcp_edges_as_the_in_process_build(): void
    {
        $this->mcpEdges([
            'routes/ai.php' => $this->routes("Route::prefix('api')->group(function (): void { Mcp::web('/mcp', OrderServer::class); });\nMcp::local('orders', OrderServer::class);"),
            'app/Mcp/Servers/OrderServer.php' => self::server(),
        ]);

        $out = (string) tempnam(sys_get_temp_dir(), 'richter-test-');

        try {
            $this->assertSame(0, Artisan::call('richter:internal-tracer-branch', ['--project' => $this->projectRoot, '--out' => $out]));
            $decoded = json_decode((string) file_get_contents($out), associative: true);
        } finally {
            File::delete($out);
        }

        $this->assertEquals(new CodeGraphBuilder()->buildTracerBranch($this->projectRoot), $decoded);
    }
}
