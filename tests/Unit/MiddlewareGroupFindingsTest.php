<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Analysis\Hazards\GuardMiddleware;
use SanderMuller\Richter\Analysis\MiddlewareGroupFindings;
use SanderMuller\Richter\Analysis\MiddlewareGroups;
use SanderMuller\Richter\Graph\CodeGraph;
use SanderMuller\Richter\Tests\TestCase;

/**
 * A middleware that reaches the graph only through a group is linked to nothing: route middleware is
 * resolved by alias upstream, so `->middleware('api')` arrives as a bare `middleware::api` node.
 * The class still self-lists as an entry point, so the report is wrongly sized rather than empty —
 * these cover the note that supplies the missing size, and the cases where guessing one would be
 * worse than staying quiet.
 */
final class MiddlewareGroupFindingsTest extends TestCase
{
    private const string TENANT = 'App\Http\Middleware\EnsureTenant';

    private string $projectRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->projectRoot = sys_get_temp_dir() . '/richter-mw-groups-' . bin2hex(random_bytes(8));
        mkdir("{$this->projectRoot}/app/Http", recursive: true);
    }

    protected function tearDown(): void
    {
        new Filesystem()->deleteDirectory($this->projectRoot);
        parent::tearDown();
    }

    #[Test]
    public function a_group_member_is_told_how_many_routes_the_group_guards(): void
    {
        $this->kernel("'api' => [\n            \\App\\Http\\Middleware\\EnsureTenant::class,\n            'throttle:api',\n        ],");

        $findings = $this->lane($this->graphWithRoutes(3))->findingsFor(self::TENANT);

        $this->assertSame(
            ["App\\Http\\Middleware\\EnsureTenant runs in middleware group 'api', which guards 3 routes; group membership is not drawn as edges, so those routes are not in the reach above"],
            $findings,
        );
    }

    #[Test]
    public function a_single_route_is_not_pluralised(): void
    {
        $this->kernel("'api' => [\\App\\Http\\Middleware\\EnsureTenant::class],");

        $this->assertStringContainsString('guards 1 route;', $this->lane($this->graphWithRoutes(1))->findingsFor(self::TENANT)[0]);
    }

    #[Test]
    public function a_member_written_as_an_alias_resolves_through_the_alias_map(): void
    {
        // Groups list what the app wrote. An alias entry has to reach the same FQCN a changed file
        // resolves to, or the note never fires for the class that changed.
        $this->kernel(
            "'api' => ['tenant'],",
            "'tenant' => \\App\\Http\\Middleware\\EnsureTenant::class,",
        );

        $this->assertCount(1, $this->lane($this->graphWithRoutes(2))->findingsFor(self::TENANT));
    }

    #[Test]
    public function a_member_carrying_parameters_still_resolves(): void
    {
        // `'tenant:strict'` is one alias with an argument, not an alias named `tenant:strict`. Keep
        // the parameters and the lookup misses, so the note never fires for the class that changed.
        $this->kernel(
            "'api' => ['tenant:strict'],",
            "'tenant' => \\App\\Http\\Middleware\\EnsureTenant::class,",
        );

        $this->assertCount(1, $this->lane($this->graphWithRoutes(2))->findingsFor(self::TENANT));
    }

    #[Test]
    public function a_nested_group_carries_its_members_into_the_outer_group(): void
    {
        // Laravel expands a group named inside another group, so a member of the inner one also runs
        // on the outer one's routes. Reporting only the inner group undercounts the size this note
        // exists to give.
        $this->kernel(
            "'api' => [\\App\\Http\\Middleware\\EnsureTenant::class],\n        'admin' => ['api'],",
        );

        $graph = new CodeGraph([
            ['source' => 'route::GET::/a', 'target' => 'middleware::api', 'type' => 'route-to-middleware'],
            ['source' => 'route::GET::/b', 'target' => 'middleware::admin', 'type' => 'route-to-middleware'],
            ['source' => 'route::GET::/c', 'target' => 'middleware::admin', 'type' => 'route-to-middleware'],
        ], hasUnparseableFiles: false);

        $findings = $this->lane($graph)->findingsFor(self::TENANT);

        $this->assertCount(2, $findings);
        $this->assertStringContainsString("group 'api', which guards 1 route;", $findings[0]);
        $this->assertStringContainsString("group 'admin', which guards 2 routes;", $findings[1]);
    }

    #[Test]
    public function a_cycle_between_two_groups_terminates(): void
    {
        $this->kernel(
            "'api' => [\\App\\Http\\Middleware\\EnsureTenant::class, 'admin'],\n        'admin' => ['api'],",
        );

        $this->assertCount(1, $this->lane($this->graphWithRoutes(2))->findingsFor(self::TENANT));
    }

    #[Test]
    public function a_name_that_is_both_a_group_and_an_alias_is_skipped(): void
    {
        // Resolving it one way needs knowledge of the resolution order the reader does not have, and
        // the wrong choice points the note at the wrong routes.
        $this->kernel(
            "'api' => ['shared'],\n        'shared' => [\\App\\Http\\Middleware\\EnsureTenant::class],",
            "'shared' => \\App\\Http\\Middleware\\Other::class,",
        );

        $this->assertSame([], $this->lane($this->graphWithRoutes(2))->findingsFor(self::TENANT));
    }

    #[Test]
    public function a_group_no_route_references_stays_silent(): void
    {
        // "guards 0 routes" sizes nothing and teaches its reader to skip the check.
        $this->kernel("'api' => [\\App\\Http\\Middleware\\EnsureTenant::class],");

        $graph = new CodeGraph([
            ['source' => 'route::GET::/x', 'target' => 'middleware::web', 'type' => 'route-to-middleware'],
        ], hasUnparseableFiles: false);

        $this->assertSame([], $this->lane($graph)->findingsFor(self::TENANT));
    }

    #[Test]
    public function a_middleware_in_no_group_stays_silent(): void
    {
        $this->kernel("'api' => [\\App\\Http\\Middleware\\Other::class],");

        $this->assertSame([], $this->lane($this->graphWithRoutes(2))->findingsFor(self::TENANT));
    }

    #[Test]
    public function an_app_with_no_kernel_and_no_bootstrap_stays_silent(): void
    {
        $this->assertSame([], $this->lane($this->graphWithRoutes(2))->findingsFor(self::TENANT));
    }

    #[Test]
    public function membership_in_two_groups_produces_one_note_each(): void
    {
        $this->kernel(
            "'api' => [\\App\\Http\\Middleware\\EnsureTenant::class],\n        'admin' => [\\App\\Http\\Middleware\\EnsureTenant::class],",
        );

        $graph = new CodeGraph([
            ['source' => 'route::GET::/a', 'target' => 'middleware::api', 'type' => 'route-to-middleware'],
            ['source' => 'route::GET::/b', 'target' => 'middleware::admin', 'type' => 'route-to-middleware'],
            ['source' => 'route::GET::/c', 'target' => 'middleware::admin', 'type' => 'route-to-middleware'],
        ], hasUnparseableFiles: false);

        $findings = $this->lane($graph)->findingsFor(self::TENANT);

        $this->assertCount(2, $findings);
        $this->assertStringContainsString("group 'api', which guards 1 route;", $findings[0]);
        $this->assertStringContainsString("group 'admin', which guards 2 routes;", $findings[1]);
    }

    #[Test]
    public function only_routes_count_toward_the_size_not_other_callers(): void
    {
        // A controller-level middleware attachment reaches the same node. The note counts endpoints,
        // so a non-route caller must not inflate it.
        $this->kernel("'api' => [\\App\\Http\\Middleware\\EnsureTenant::class],");

        $graph = new CodeGraph([
            ['source' => 'route::GET::/a', 'target' => 'middleware::api', 'type' => 'route-to-middleware'],
            ['source' => 'App\Http\Controllers\PostController', 'target' => 'middleware::api', 'type' => 'controller-middleware'],
        ], hasUnparseableFiles: false);

        $this->assertStringContainsString('guards 1 route;', $this->lane($graph)->findingsFor(self::TENANT)[0]);
    }

    #[Test]
    public function a_bootstrap_append_to_group_call_is_read(): void
    {
        $this->bootstrap("\$middleware->appendToGroup('api', [\\App\\Http\\Middleware\\EnsureTenant::class]);");

        $this->assertCount(1, $this->lane($this->graphWithRoutes(2))->findingsFor(self::TENANT));
    }

    #[Test]
    public function a_bootstrap_prepend_is_read_like_an_append(): void
    {
        $this->bootstrap('$middleware->api(prepend: [\\App\\Http\\Middleware\\EnsureTenant::class]);');

        $this->assertCount(1, $this->lane($this->graphWithRoutes(2))->findingsFor(self::TENANT));
    }

    #[Test]
    public function a_member_built_by_a_static_call_is_the_class_it_builds(): void
    {
        // `ThrottleRequests::with(30, 1)` is the shape: a builder that returns the class name with
        // its parameters. The changed file is that class, so the member must resolve to it.
        $this->bootstrap("\$middleware->api(append: [\\App\\Http\\Middleware\\EnsureTenant::with('strict')]);");

        $this->assertCount(1, $this->lane($this->graphWithRoutes(2))->findingsFor(self::TENANT));
    }

    #[Test]
    public function two_calls_on_one_group_are_merged(): void
    {
        // A second call adds to the group. Keeping only the last one drops the member the first one added.
        $this->bootstrap(
            "\$middleware->api(append: [\\App\\Http\\Middleware\\EnsureTenant::class]);\n"
            . '        $middleware->api(append: [\\App\\Http\\Middleware\\Other::class]);',
        );

        $this->assertCount(1, $this->lane($this->graphWithRoutes(2))->findingsFor(self::TENANT));
    }

    #[Test]
    public function a_member_removed_from_the_group_is_not_reported(): void
    {
        $this->bootstrap(
            "\$middleware->appendToGroup('api', [\\App\\Http\\Middleware\\EnsureTenant::class]);\n"
            . "        \$middleware->removeFromGroup('api', \\App\\Http\\Middleware\\EnsureTenant::class);",
        );

        $this->assertSame([], $this->lane($this->graphWithRoutes(2))->findingsFor(self::TENANT));
    }

    #[Test]
    public function a_group_declared_in_bootstrap_is_read(): void
    {
        $this->bootstrap("\$middleware->group('api', [\\App\\Http\\Middleware\\EnsureTenant::class]);");

        $this->assertCount(1, $this->lane($this->graphWithRoutes(2))->findingsFor(self::TENANT));
    }

    #[Test]
    public function a_group_declaration_replaces_the_members_read_before_it(): void
    {
        // Laravel's `group()` assigns the whole list, so a member appended before it is gone.
        $this->bootstrap(
            "\$middleware->appendToGroup('api', [\\App\\Http\\Middleware\\EnsureTenant::class]);\n"
            . "        \$middleware->group('api', [\\App\\Http\\Middleware\\Other::class]);",
        );

        $this->assertSame([], $this->lane($this->graphWithRoutes(2))->findingsFor(self::TENANT));
    }

    #[Test]
    public function a_kernel_stub_beside_bootstrap_does_not_hide_the_bootstrap_groups(): void
    {
        // An upgraded app can keep an old Kernel. Laravel 11+ reads bootstrap/app.php, so a group
        // declared there wins over the Kernel's stale copy of the same group.
        $this->kernel("'api' => [\\App\\Http\\Middleware\\Other::class],");
        $this->bootstrap('$middleware->api(append: [\\App\\Http\\Middleware\\EnsureTenant::class]);');

        $this->assertCount(1, $this->lane($this->graphWithRoutes(2))->findingsFor(self::TENANT));
    }

    #[Test]
    public function the_member_names_project_onto_the_guard_tokens_the_hazard_lane_reads(): void
    {
        // Both lanes read groups through one reader, so one source gives both lanes the same groups.
        // Where no two names stand for one guard, the names projected onto guard tokens are exactly
        // the tokens the hazard lane compares.
        $source = <<<'PHP'
            <?php

            use Illuminate\Foundation\Configuration\Middleware;

            return Application::configure()->withMiddleware(function (Middleware $middleware): void {
                $middleware->web(append: ['auth', \App\Http\Middleware\EnsureTenant::class], remove: ['verified']);
                $middleware->appendToGroup('admin', ['can:manage', 'throttle:10,1']);
                $middleware->replaceInGroup('admin', 'throttle:10,1', 'throttle:20,1');
            });
            PHP;

        $names = MiddlewareGroups::fromSource($source, MiddlewareGroups::namesIn(...));
        $tokens = MiddlewareGroups::fromSource($source, GuardMiddleware::tokensIn(...));

        $this->assertNotNull($names);
        $this->assertNotNull($tokens);
        $this->assertSame(['web', 'admin'], array_keys($names));
        $this->assertSame(
            $tokens,
            array_map(static fn (array $group): array => [GuardMiddleware::tokensFor($group[0]), GuardMiddleware::tokensFor($group[1])], $names),
        );
        $this->assertSame(['middleware:auth'], $tokens['web'][0]);
        $this->assertSame(['middleware:verified'], $tokens['web'][1]);
    }

    private function bootstrap(string $calls): void
    {
        mkdir("{$this->projectRoot}/bootstrap");
        file_put_contents("{$this->projectRoot}/bootstrap/app.php", <<<PHP
            <?php declare(strict_types=1);

            use Illuminate\\Foundation\\Application;
            use Illuminate\\Foundation\\Configuration\\Middleware;

            return Application::configure(basePath: dirname(__DIR__))
                ->withMiddleware(function (Middleware \$middleware): void {
                    {$calls}
                })
                ->create();
            PHP);
    }

    private function kernel(string $groups, string $aliases = ''): void
    {
        file_put_contents("{$this->projectRoot}/app/Http/Kernel.php", <<<PHP
            <?php declare(strict_types=1);

            namespace App\\Http;

            final class Kernel
            {
                protected \$middlewareGroups = [
                    {$groups}
                ];

                protected \$middlewareAliases = [
                    {$aliases}
                ];
            }
            PHP);
    }

    private function graphWithRoutes(int $count): CodeGraph
    {
        $edges = [];

        for ($i = 0; $i < $count; ++$i) {
            $edges[] = ['source' => "route::GET::/r{$i}", 'target' => 'middleware::api', 'type' => 'route-to-middleware'];
        }

        return new CodeGraph($edges, hasUnparseableFiles: false);
    }

    private function lane(CodeGraph $graph): MiddlewareGroupFindings
    {
        return new MiddlewareGroupFindings($graph, $this->projectRoot);
    }
}
