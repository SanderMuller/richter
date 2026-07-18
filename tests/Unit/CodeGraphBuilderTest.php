<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use App\Http\Controllers\Auth\SocialAuthController;
use App\Http\Middleware\Authenticate;
use App\Models\Question;
use App\Models\Video;
use App\Policies\UserPolicy;
use App\Policies\VideoPolicy;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Routing\Middleware\ValidateSignature;
use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Graph\CodeGraphBuilder;
use SanderMuller\Richter\Graph\MiddlewareAliases;
use SanderMuller\Richter\Tests\TestCase;

final class CodeGraphBuilderTest extends TestCase
{
    #[Test]
    public function it_links_every_app_member_node_to_its_declaring_class(): void
    {
        $edges = [
            ['source' => 'App\Http\Controllers\FooController::store', 'target' => 'App\Jobs\BarJob', 'type' => 'new'],
            ['source' => 'App\Jobs\BarJob::handle', 'target' => Video::class, 'type' => 'call'],
        ];

        $declares = CodeGraphBuilder::declaresEdges($edges);

        $this->assertContains(['source' => 'App\Http\Controllers\FooController', 'target' => 'App\Http\Controllers\FooController::store', 'type' => 'declares'], $declares);
        $this->assertContains(['source' => 'App\Jobs\BarJob', 'target' => 'App\Jobs\BarJob::handle', 'type' => 'declares'], $declares);
    }

    #[Test]
    public function it_ignores_non_app_and_non_member_nodes(): void
    {
        $edges = [
            ['source' => 'route::GET /videos', 'target' => 'App\Http\Controllers\VideoController', 'type' => 'route-to-controller'],
            ['source' => 'command::video:cleanup', 'target' => Video::class, 'type' => 'call'],
            ['source' => 'view::player.index', 'target' => VideoPolicy::class, 'type' => 'authorizes'],
        ];

        $this->assertSame([], CodeGraphBuilder::declaresEdges($edges));
    }

    #[Test]
    public function it_declares_the_parsed_methods_of_a_class_as_member_nodes(): void
    {
        $source = "<?php\nnamespace App\Policies;\nclass UserPolicy\n{\n    public const DELETE = 'delete';\n    private string \$name = '';\n    public function delete(): bool { return true; }\n    private function helper(): void {}\n}\n";

        $edges = CodeGraphBuilder::declaredMemberEdges($source, UserPolicy::class);

        $this->assertSame([
            ['source' => UserPolicy::class, 'target' => 'App\Policies\UserPolicy::delete', 'type' => 'declares'],
            ['source' => UserPolicy::class, 'target' => 'App\Policies\UserPolicy::helper', 'type' => 'declares'],
        ], $edges);
    }

    #[Test]
    public function it_rewrites_short_controller_ids_onto_the_fqcn_scheme(): void
    {
        $edges = [
            ['source' => 'route::GET::/auth/login', 'target' => 'controller::SocialAuthController', 'type' => 'route-to-controller'],
            ['source' => 'controller::SocialAuthController', 'target' => 'action::SocialAuthController::login', 'type' => 'controller-to-action'],
        ];

        $resolved = CodeGraphBuilder::resolveShortControllerIds($edges, [
            'SocialAuthController' => [SocialAuthController::class],
        ]);

        $this->assertSame(SocialAuthController::class, $resolved[0]['target']);
        $this->assertSame('App\Http\Controllers\Auth\SocialAuthController::login', $resolved[1]['target']);
    }

    #[Test]
    public function an_ambiguous_short_controller_id_stays_verbatim(): void
    {
        $edges = [
            ['source' => 'route::GET::/x', 'target' => 'controller::VideoController', 'type' => 'route-to-controller'],
        ];

        $resolved = CodeGraphBuilder::resolveShortControllerIds($edges, [
            'VideoController' => ['App\Http\Controllers\Video\VideoController', 'App\Http\Controllers\Api\VideoController'],
        ]);

        $this->assertSame('controller::VideoController', $resolved[0]['target']);
    }

    #[Test]
    public function it_parses_the_kernel_middleware_alias_map(): void
    {
        $source = "<?php\nnamespace App\Http;\nuse App\Http\Middleware\Authenticate;\nclass Kernel\n{\n    protected \$middlewareAliases = [\n        'auth' => Authenticate::class,\n        'signed' => \Illuminate\Routing\Middleware\ValidateSignature::class,\n        'password.confirm' => \Illuminate\Auth\Middleware\RequirePassword::class,\n        'legacy' => 'App\Http\Middleware\LegacyStringAlias',\n    ];\n}\n";

        $this->assertSame([
            'auth' => Authenticate::class,
            'signed' => ValidateSignature::class,
            'password.confirm' => RequirePassword::class,
            'legacy' => 'App\Http\Middleware\LegacyStringAlias',
        ], MiddlewareAliases::fromKernel($source));
    }

    #[Test]
    public function it_parses_the_bootstrap_middleware_alias_map(): void
    {
        // The Laravel 11+ registration form — ->alias([...]) inside bootstrap/app.php's
        // withMiddleware closure; ::class refs and class-string literals both resolve.
        $source = <<<'PHP'
            <?php
            use App\Http\Middleware\Authenticate;
            use Illuminate\Foundation\Application;
            use Illuminate\Foundation\Configuration\Middleware;

            return Application::configure(basePath: dirname(__DIR__))
                ->withMiddleware(function (Middleware $middleware): void {
                    $middleware->alias([
                        'auth' => Authenticate::class,
                        'features' => 'Laravel\Pennant\Middleware\EnsureFeaturesAreActive',
                    ]);
                })
                ->create();
            PHP;

        $this->assertSame([
            'auth' => 'App\Http\Middleware\Authenticate',
            'features' => 'Laravel\Pennant\Middleware\EnsureFeaturesAreActive',
        ], MiddlewareAliases::fromBootstrap($source));
    }

    #[Test]
    public function an_empty_or_alias_free_bootstrap_yields_no_map(): void
    {
        $this->assertSame([], MiddlewareAliases::fromBootstrap(''));
        $this->assertSame([], MiddlewareAliases::fromBootstrap('<?php return 1;'));
    }

    #[Test]
    public function it_rewrites_alias_middleware_nodes_onto_the_fqcn(): void
    {
        $edges = [
            ['source' => 'route::GET::/x', 'target' => 'middleware::auth', 'type' => 'route-to-middleware'],
            ['source' => 'route::GET::/y', 'target' => 'middleware::throttle:api', 'type' => 'route-to-middleware'],
            ['source' => 'route::GET::/z', 'target' => 'middleware::web', 'type' => 'route-to-middleware'],
        ];

        $resolved = CodeGraphBuilder::resolveMiddlewareAliases($edges, [
            'auth' => Authenticate::class,
            'throttle' => 'App\Http\Middleware\ThrottleRequests',
        ]);

        $this->assertSame(Authenticate::class, $resolved[0]['target']);
        // Parameterised aliases resolve by their base name.
        $this->assertSame('App\Http\Middleware\ThrottleRequests', $resolved[1]['target']);
        // Group aliases (web/api) are not in the alias map and stay verbatim by design.
        $this->assertSame('middleware::web', $resolved[2]['target']);
    }

    #[Test]
    public function a_dotted_alias_node_resolves(): void
    {
        // `password.confirm` is why the alias pattern accepts dots — a regex "tightening" that drops
        // the dot would orphan it.
        $edges = [['source' => 'route::GET::/x', 'target' => 'middleware::password.confirm', 'type' => 'route-to-middleware']];

        $resolved = CodeGraphBuilder::resolveMiddlewareAliases($edges, [
            'password.confirm' => RequirePassword::class,
        ]);

        $this->assertSame(RequirePassword::class, $resolved[0]['target']);
    }

    #[Test]
    public function it_emits_one_declares_edge_per_member(): void
    {
        $edges = [
            ['source' => 'App\Jobs\BarJob::handle', 'target' => Video::class, 'type' => 'call'],
            ['source' => 'App\Jobs\BarJob::handle', 'target' => Question::class, 'type' => 'call'],
        ];

        $this->assertCount(1, CodeGraphBuilder::declaresEdges($edges));
    }
}
