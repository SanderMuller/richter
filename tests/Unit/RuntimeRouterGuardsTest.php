<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Router;
use LaraMint\LaravelBrain\Analysis\SecurityAnalyzer;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClassConstant;
use ReflectionMethod;
use SanderMuller\Richter\Analysis\AuthMiddlewareVocabulary;
use SanderMuller\Richter\Analysis\RuntimeRouterGuards;
use SanderMuller\Richter\Support\RichterConfig;
use SanderMuller\Richter\Tests\TestCase;

final class RuntimeRouterGuardsTest extends TestCase
{
    private function router(): Router
    {
        $router = $this->app?->make('router');
        $this->assertInstanceOf(Router::class, $router);

        return $router;
    }

    /** @return array{exposure: string, riskLevel: string, issues: list<array{type: string, severity: string, message: string}>} */
    private function publicSurface(bool $publicWrite = false): array
    {
        return [
            'exposure' => 'public',
            'riskLevel' => 'medium',
            'issues' => $publicWrite ? [['type' => 'PUBLIC_WRITE', 'severity' => 'medium', 'message' => 'POST route with no auth middleware']] : [],
        ];
    }

    private function guards(): RuntimeRouterGuards
    {
        return new RuntimeRouterGuards(base_path());
    }

    #[Test]
    public function a_group_applied_framework_guard_is_recognized_with_its_group_attributed(): void
    {
        $router = $this->router();
        $router->middlewareGroup('secure', [Authenticate::class]);
        $router->post('/pay', static fn (): string => 'ok')->middleware('secure');

        $guards = $this->guards()->guardsByEntryPoint(['route::POST::/pay' => $this->publicSurface(publicWrite: true)]);

        $this->assertSame([
            'route::POST::/pay' => [['middleware' => Authenticate::class, 'group' => 'secure']],
        ], $guards);
    }

    #[Test]
    public function a_nested_group_attributes_the_outermost_group(): void
    {
        $router = $this->router();
        $router->middlewareGroup('inner', [Authenticate::class]);
        $router->middlewareGroup('outer', ['inner']);
        $router->post('/nested', static fn (): string => 'ok')->middleware('outer');

        $guards = $this->guards()->guardsByEntryPoint(['route::POST::/nested' => $this->publicSurface()]);

        $this->assertSame([['middleware' => Authenticate::class, 'group' => 'outer']], $guards['route::POST::/nested'] ?? null);
    }

    #[Test]
    public function a_builtin_brain_alias_inside_a_group_is_recognized_without_ancestry(): void
    {
        // The simulated Sanctum middleware descends from none of the four framework bases: the
        // pattern layer, not ancestry, is what recognises it — exactly the group-only case.
        $router = $this->router();
        $router->aliasMiddleware('sanctum', PlainTokenMiddleware::class);
        $router->middlewareGroup('api', ['sanctum']);
        $router->post('/api/things', static fn (): string => 'ok')->middleware('api');

        $guards = $this->guards()->guardsByEntryPoint(['route::POST::/api/things' => $this->publicSurface(publicWrite: true)]);

        $this->assertSame([['middleware' => PlainTokenMiddleware::class, 'group' => 'api']], $guards['route::POST::/api/things'] ?? null);
    }

    #[Test]
    public function a_configured_alias_inside_a_group_is_recognized_via_brain_pattern_semantics(): void
    {
        config()->set('laravel-brain.security.auth_middleware', ['merchant.hmac']);
        $router = $this->router();
        $router->aliasMiddleware('merchant.hmac', PlainTokenMiddleware::class);
        $router->middlewareGroup('merchant', ['merchant.hmac']);
        $router->post('/merchant/pay', static fn (): string => 'ok')->middleware('merchant');

        $guards = $this->guards()->guardsByEntryPoint(['route::POST::/merchant/pay' => $this->publicSurface()]);

        $this->assertSame([['middleware' => PlainTokenMiddleware::class, 'group' => 'merchant']], $guards['route::POST::/merchant/pay'] ?? null);
    }

    #[Test]
    public function a_configured_fqcn_prefix_matches_a_directly_applied_class(): void
    {
        config()->set('laravel-brain.security.auth_middleware', [PlainTokenMiddleware::class]);
        $router = $this->router();
        $router->post('/direct', static fn (): string => 'ok')->middleware(PlainTokenMiddleware::class);

        $guards = $this->guards()->guardsByEntryPoint(['route::POST::/direct' => $this->publicSurface()]);

        $this->assertSame([['middleware' => PlainTokenMiddleware::class, 'group' => null]], $guards['route::POST::/direct'] ?? null);
    }

    #[Test]
    public function a_controller_applied_configured_alias_is_recognized_with_null_group(): void
    {
        config()->set('laravel-brain.security.auth_middleware', ['merchant.hmac']);
        $router = $this->router();
        $router->aliasMiddleware('merchant.hmac', PlainTokenMiddleware::class);
        $router->post('/controller', [GuardedTestController::class, 'store']);

        $guards = $this->guards()->guardsByEntryPoint(['route::POST::/controller' => $this->publicSurface(publicWrite: true)]);

        $this->assertSame([['middleware' => PlainTokenMiddleware::class, 'group' => null]], $guards['route::POST::/controller'] ?? null);
    }

    #[Test]
    public function a_without_middleware_exclusion_is_never_evidence(): void
    {
        $router = $this->router();
        $router->middlewareGroup('secure', [Authenticate::class]);
        $router->post('/excluded', static fn (): string => 'ok')->middleware('secure')->withoutMiddleware(Authenticate::class);

        $guards = $this->guards()->guardsByEntryPoint(['route::POST::/excluded' => $this->publicSurface(publicWrite: true)]);

        $this->assertSame([], $guards);
    }

    #[Test]
    public function an_excluded_configured_alias_is_never_evidence_either(): void
    {
        // The exclusion arbiter works on the raw-token layer too: the alias matches the configured
        // pattern, but its class does not survive the effective stack.
        config()->set('laravel-brain.security.auth_middleware', ['merchant.hmac']);
        $router = $this->router();
        $router->aliasMiddleware('merchant.hmac', PlainTokenMiddleware::class);
        $router->middlewareGroup('merchant', ['merchant.hmac']);
        $router->post('/excluded-alias', static fn (): string => 'ok')->middleware('merchant')->withoutMiddleware(PlainTokenMiddleware::class);

        $guards = $this->guards()->guardsByEntryPoint(['route::POST::/excluded-alias' => $this->publicSurface()]);

        $this->assertSame([], $guards);
    }

    #[Test]
    public function colliding_domain_routes_with_one_unguarded_twin_yield_no_entry(): void
    {
        $router = $this->router();
        $router->middlewareGroup('secure', [Authenticate::class]);
        $router->group(['domain' => 'a.example.test'], static function () use ($router): void {
            $router->post('/account', static fn (): string => 'ok')->middleware('secure');
        });
        $router->group(['domain' => 'b.example.test'], static function () use ($router): void {
            $router->post('/account', static fn (): string => 'ok');
        });

        $guards = $this->guards()->guardsByEntryPoint(['route::POST::/account' => $this->publicSurface(publicWrite: true)]);

        $this->assertSame([], $guards);
    }

    #[Test]
    public function colliding_routes_guarded_by_different_guards_yield_no_entry(): void
    {
        config()->set('laravel-brain.security.auth_middleware', [PlainTokenMiddleware::class]);
        $router = $this->router();
        $router->middlewareGroup('secure', [Authenticate::class]);
        $router->group(['domain' => 'a.example.test'], static function () use ($router): void {
            $router->post('/shared', static fn (): string => 'ok')->middleware('secure');
        });
        $router->group(['domain' => 'b.example.test'], static function () use ($router): void {
            $router->post('/shared', static fn (): string => 'ok')->middleware(PlainTokenMiddleware::class);
        });

        // Both registrations are gated, but by different guards: the intersection is empty, and
        // claiming either guard for the shared node would attach the wrong route's evidence.
        $guards = $this->guards()->guardsByEntryPoint(['route::POST::/shared' => $this->publicSurface(publicWrite: true)]);

        $this->assertSame([], $guards);
    }

    #[Test]
    public function colliding_routes_guarded_by_the_same_guard_keep_the_intersection(): void
    {
        $router = $this->router();
        $router->middlewareGroup('secure', [Authenticate::class]);
        $router->group(['domain' => 'a.example.test'], static function () use ($router): void {
            $router->post('/same', static fn (): string => 'ok')->middleware('secure');
        });
        $router->group(['domain' => 'b.example.test'], static function () use ($router): void {
            $router->post('/same', static fn (): string => 'ok')->middleware('secure');
        });

        $guards = $this->guards()->guardsByEntryPoint(['route::POST::/same' => $this->publicSurface()]);

        $this->assertSame([['middleware' => Authenticate::class, 'group' => 'secure']], $guards['route::POST::/same'] ?? null);
    }

    #[Test]
    public function the_lane_fails_closed_and_stays_silent_on_every_ineligible_shape(): void
    {
        $router = $this->router();
        $router->middlewareGroup('secure', [Authenticate::class]);
        $router->post('/guarded', static fn (): string => 'ok')->middleware('secure');
        $router->aliasMiddleware('ghost', 'Nonexistent\\Middleware\\Class');
        $router->post('/ghost', static fn (): string => 'ok')->middleware('ghost');
        $surfaces = ['route::POST::/guarded' => $this->publicSurface(publicWrite: true)];

        // Null root and foreign root: fail closed before any router read.
        $this->assertSame([], new RuntimeRouterGuards(null)->guardsByEntryPoint($surfaces));
        $this->assertSame([], new RuntimeRouterGuards('/definitely/not/this/project')->guardsByEntryPoint($surfaces));

        $eligible = $this->guards()->guardsByEntryPoint([
            'route::POST::/guarded' => $this->publicSurface(publicWrite: true),
            // An alias resolving to a class that does not exist is not evidence.
            'route::POST::/ghost' => $this->publicSurface(publicWrite: true),
            // A node matching no registered route is never guessed at.
            'route::POST::/nowhere' => $this->publicSurface(publicWrite: true),
            // A non-route surface is excluded by the route:: guard.
            'App\\Livewire\\Dashboard' => $this->publicSurface(publicWrite: true),
            // An authed route with no PUBLIC_WRITE issue is not a candidate.
            'route::POST::/guarded-elsewhere' => ['exposure' => 'authed', 'riskLevel' => 'low', 'issues' => []],
        ]);

        $this->assertSame(['route::POST::/guarded'], array_keys($eligible));
    }

    #[Test]
    public function a_guard_both_direct_and_via_a_group_appears_once_with_the_first_tokens_provenance(): void
    {
        $router = $this->router();
        $router->middlewareGroup('secure', [Authenticate::class]);
        $router->post('/twice', static fn (): string => 'ok')->middleware([Authenticate::class, 'secure']);

        $guards = $this->guards()->guardsByEntryPoint(['route::POST::/twice' => $this->publicSurface(publicWrite: true)]);

        // One entry, and the direct token came first, so provenance is null — display only.
        $this->assertSame([['middleware' => Authenticate::class, 'group' => null]], $guards['route::POST::/twice'] ?? null);
    }

    #[Test]
    public function colliding_routes_sharing_a_guard_through_different_groups_read_null_provenance(): void
    {
        $router = $this->router();
        $router->middlewareGroup('web-secure', [Authenticate::class]);
        $router->middlewareGroup('api-secure', [Authenticate::class]);
        $router->group(['domain' => 'a.example.test'], static function () use ($router): void {
            $router->post('/mixed', static fn (): string => 'ok')->middleware('web-secure');
        });
        $router->group(['domain' => 'b.example.test'], static function () use ($router): void {
            $router->post('/mixed', static fn (): string => 'ok')->middleware('api-secure');
        });

        $guards = $this->guards()->guardsByEntryPoint(['route::POST::/mixed' => $this->publicSurface(publicWrite: true)]);

        // Same guard, different groups: the guard is common evidence, the provenance is not.
        $this->assertSame([['middleware' => Authenticate::class, 'group' => null]], $guards['route::POST::/mixed'] ?? null);
    }

    #[Test]
    public function an_indirect_group_cycle_yields_silence_instead_of_recursion(): void
    {
        $router = $this->router();
        $router->middlewareGroup('a', [Authenticate::class, 'b']);
        $router->middlewareGroup('b', ['a']);
        $router->post('/cyclic', static fn (): string => 'ok')->middleware('a');

        // Laravel's own expansion would recurse forever on this config; the lane must answer with
        // an empty map, never a stack overflow.
        $this->assertSame([], $this->guards()->guardsByEntryPoint(['route::POST::/cyclic' => $this->publicSurface(publicWrite: true)]));
    }

    #[Test]
    public function a_cycle_in_an_unrelated_group_does_not_silence_other_routes(): void
    {
        $router = $this->router();
        $router->middlewareGroup('cyclic-a', ['cyclic-b']);
        $router->middlewareGroup('cyclic-b', ['cyclic-a']);
        $router->middlewareGroup('secure', [Authenticate::class]);
        $router->post('/healthy', static fn (): string => 'ok')->middleware('secure');

        $guards = $this->guards()->guardsByEntryPoint(['route::POST::/healthy' => $this->publicSurface(publicWrite: true)]);

        $this->assertSame([['middleware' => Authenticate::class, 'group' => 'secure']], $guards['route::POST::/healthy'] ?? null);
    }

    #[Test]
    public function a_guard_nested_in_an_excluded_group_is_never_evidence(): void
    {
        $router = $this->router();
        $router->middlewareGroup('inner-secure', [Authenticate::class]);
        $router->middlewareGroup('outer-secure', ['inner-secure']);
        $router->post('/nested-excluded', static fn (): string => 'ok')->middleware('outer-secure')->withoutMiddleware(Authenticate::class);

        $this->assertSame([], $this->guards()->guardsByEntryPoint(['route::POST::/nested-excluded' => $this->publicSurface(publicWrite: true)]));
    }

    #[Test]
    public function a_multi_method_route_indexes_one_node_per_method_and_skips_head(): void
    {
        $router = $this->router();
        $router->middlewareGroup('secure', [Authenticate::class]);
        $router->match(['GET', 'POST'], '/multi', static fn (): string => 'ok')->middleware('secure');

        $guards = $this->guards()->guardsByEntryPoint([
            'route::GET::/multi' => $this->publicSurface(),
            'route::POST::/multi' => $this->publicSurface(publicWrite: true),
            'route::HEAD::/multi' => $this->publicSurface(),
        ]);

        $this->assertSame(['route::GET::/multi', 'route::POST::/multi'], array_keys($guards));
    }

    #[Test]
    public function the_pattern_mirror_agrees_with_brains_own_matcher(): void
    {
        // Brain's middlewareMatches() is private; the vocabulary mirrors it, and this contract test
        // pins the mirror against upstream on the semantics the spec names: exact alias, parameter
        // prefix, FQCN prefix, and the basename of an FQCN pattern, case-insensitively.
        config()->set('laravel-brain.security.auth_middleware', []);

        foreach ([
            'auth' => true,
            'auth:sanctum' => true,
            'AUTH' => true,
            'sanctum' => true,
            'jwt:api' => true,
            'passport' => true,
            'verified' => true,
            'signed:relative' => true,
            'Illuminate\\Auth\\Middleware\\Authenticate' => true,
            'Illuminate\\Auth\\Middleware\\Authenticate:web' => true,
            // Basename of an FQCN pattern: the app's own same-named class.
            'App\\Http\\Middleware\\Authenticate' => true,
            'auth.basic' => false,
            'guest' => false,
            'throttle:60,1' => false,
            'App\\Http\\Middleware\\EnsureTenant' => false,
        ] as $token => $expected) {
            $this->assertSame($expected, AuthMiddlewareVocabulary::matchesBrainAuthPattern($token), $token);
        }

        config()->set('laravel-brain.security.auth_middleware', ['merchant.hmac', 'App\\Http\\Middleware\\HmacGate']);
        $this->assertTrue(AuthMiddlewareVocabulary::matchesBrainAuthPattern('merchant.hmac'));
        $this->assertTrue(AuthMiddlewareVocabulary::matchesBrainAuthPattern('merchant.hmac:strict'));
        $this->assertTrue(AuthMiddlewareVocabulary::matchesBrainAuthPattern('App\\Http\\Middleware\\HmacGate'));
        // Basename match applies to FQCN patterns, configured ones included.
        $this->assertTrue(AuthMiddlewareVocabulary::matchesBrainAuthPattern('Other\\Ns\\HmacGate'));
        $this->assertFalse(AuthMiddlewareVocabulary::matchesBrainAuthPattern('merchant.other'));
    }

    #[Test]
    public function an_unregistered_builtin_alias_is_never_evidence(): void
    {
        // Laravel leaves an unregistered alias unchanged in the expanded stack, so the token
        // survives as itself and matches the built-in pattern — but no middleware class exists,
        // and evidence on a middleware that does not exist must never lower a hazard's reach.
        $router = $this->router();
        $router->post('/fake-sanctum', static fn (): string => 'ok')->middleware('sanctum');

        $this->assertSame([], $this->guards()->guardsByEntryPoint(['route::POST::/fake-sanctum' => $this->publicSurface(publicWrite: true)]));
    }

    #[Test]
    public function a_configured_nonexistent_fqcn_is_never_evidence(): void
    {
        config()->set('laravel-brain.security.auth_middleware', ['Nonexistent\\Middleware\\HmacGate']);
        $router = $this->router();
        $router->post('/ghost-config', static fn (): string => 'ok')->middleware('Nonexistent\\Middleware\\HmacGate');

        $this->assertSame([], $this->guards()->guardsByEntryPoint(['route::POST::/ghost-config' => $this->publicSurface(publicWrite: true)]));
    }

    #[Test]
    public function the_pattern_mirror_agrees_with_the_installed_brain_matcher(): void
    {
        // Brain's matcher and pattern list are private; the mirror is compared against the REAL
        // installed implementation by reflection, so a Brain update that changes either turns this
        // red instead of letting the two silently drift.
        config()->set('laravel-brain.security.auth_middleware', []);

        $brain = new SecurityAnalyzer();
        $patternsProperty = new ReflectionClassConstant($brain::class, 'AUTH_PATTERNS');
        $brainPatterns = $patternsProperty->getValue();
        $this->assertIsArray($brainPatterns);

        $mirrorPatterns = new ReflectionClassConstant(AuthMiddlewareVocabulary::class, 'BRAIN_AUTH_PATTERNS')->getValue();
        $this->assertSame($brainPatterns, $mirrorPatterns);

        $matcher = new ReflectionMethod($brain, 'middlewareMatches');

        foreach ([
            'auth', 'auth:sanctum', 'AUTH', 'sanctum', 'jwt:api', 'passport', 'verified', 'signed:relative',
            'Illuminate\\Auth\\Middleware\\Authenticate', 'Illuminate\\Auth\\Middleware\\Authenticate:web',
            'App\\Http\\Middleware\\Authenticate', 'auth.basic', 'guest', 'throttle:60,1',
            'App\\Http\\Middleware\\EnsureTenant', 'ValidateSignature', 'validatesignature:relative',
        ] as $token) {
            $this->assertSame(
                $matcher->invoke($brain, $token, $brainPatterns),
                AuthMiddlewareVocabulary::matchesBrainAuthPattern($token),
                $token,
            );
        }
    }

    #[Test]
    public function the_eligibility_seam_trusts_only_the_working_tree(): void
    {
        // The one seam every detect-changes caller derives its runtimeEvidenceRoot from: the
        // literal 'HEAD' is working-tree mode; any resolved sha — including what --head=HEAD
        // resolves to — is a historical state the booted router does not describe.
        $this->assertSame(base_path(), RichterConfig::runtimeEvidenceRoot('HEAD'));
        $this->assertNull(RichterConfig::runtimeEvidenceRoot('abc1234def5678'));
        $this->assertNull(RichterConfig::runtimeEvidenceRoot(''));
    }

    #[Test]
    public function the_shared_ancestry_predicate_serves_both_lanes(): void
    {
        $this->assertTrue(AuthMiddlewareVocabulary::extendsAuthMiddleware(Authenticate::class));
        $this->assertTrue(AuthMiddlewareVocabulary::extendsAuthMiddleware(DescendantAuthMiddleware::class));
        $this->assertFalse(AuthMiddlewareVocabulary::extendsAuthMiddleware(PlainTokenMiddleware::class));
        $this->assertFalse(AuthMiddlewareVocabulary::extendsAuthMiddleware('Nonexistent\\Middleware\\Class'));
    }
}

/** A middleware with no framework-auth ancestry — recognizable only through the pattern layer. */
final class PlainTokenMiddleware
{
    public function handle(Request $request, callable $next): mixed
    {
        return $next($request);
    }
}

final class DescendantAuthMiddleware extends Authenticate {}

final class GuardedTestController implements HasMiddleware
{
    public static function middleware(): array
    {
        return ['merchant.hmac'];
    }

    public function store(): string
    {
        return 'ok';
    }
}
