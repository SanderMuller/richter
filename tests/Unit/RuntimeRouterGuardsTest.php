<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Middleware\ValidateSignature;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\File;
use LaraMint\LaravelBrain\Analysis\SecurityAnalyzer;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClassConstant;
use ReflectionMethod;
use SanderMuller\Richter\Analysis\AuthMiddlewareVocabulary;
use SanderMuller\Richter\Analysis\Hazard;
use SanderMuller\Richter\Analysis\ImpactAnalyzer;
use SanderMuller\Richter\Analysis\OptionalLogin;
use SanderMuller\Richter\Analysis\RuntimeRouterGuards;
use SanderMuller\Richter\Changes\ChangedFileSymbols;
use SanderMuller\Richter\Changes\MemberChange;
use SanderMuller\Richter\Graph\CodeGraph;
use SanderMuller\Richter\Support\RichterConfig;
use SanderMuller\Richter\Tests\TestCase;

final class RuntimeRouterGuardsTest extends TestCase
{
    /** @var list<string> */
    private array $written = [];

    protected function tearDown(): void
    {
        File::delete($this->written);

        parent::tearDown();
    }

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
    public function an_indirect_group_cycle_around_an_optional_login_keeps_brains_word(): void
    {
        $this->router()->middlewareGroup('a', ['b', $this->optionalLogin()]);
        $this->router()->middlewareGroup('b', ['a']);
        $this->router()->post('/cyclic', static fn (): string => 'ok')->middleware('a');

        $this->assertSame($this->authedSurface(), $this->surfaceAfter('route::POST::/cyclic'));
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
            'auth.basic' => true,
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

    /**
     * Written to a file, because the check reads the source of the class and code this odd would not
     * survive the tools that read the tests.
     */
    private function middleware(string $members, string $extends = '\\' . Authenticate::class): string
    {
        $class = 'GuestMiddleware' . hash('crc32b', $members . $extends);
        $path = sys_get_temp_dir() . "/{$class}.php";

        File::put($path, "<?php\nnamespace Acme\\Guests;\nuse Illuminate\\Auth\\AuthenticationException;\nclass {$class} extends {$extends}\n{\n{$members}\n}\n");

        $this->written[] = $path;

        require_once $path;

        $this->assertTrue(class_exists("Acme\\Guests\\{$class}"));

        return "Acme\\Guests\\{$class}";
    }

    private function handling(string $body): string
    {
        return $this->middleware("public function handle(\$request, \\Closure \$next, ...\$guards) { {$body} return \$next(\$request); }");
    }

    #[Test]
    public function a_descendant_that_lets_a_guest_through_is_no_guard(): void
    {
        $silent = $this->middleware('protected function unauthenticated($request, array $guards) {}');

        foreach ([
            $this->handling('try { $this->authenticate($request, $guards); } catch (\Illuminate\Auth\AuthenticationException) {}'),
            $this->handling('try { return parent::handle($request, $next); } catch (\Throwable) {}'),
            $this->handling('$login = fn () => parent::handle($request, $next);'),
            $this->handling('$login = $this->authenticate(...);'),
            $this->handling(''),
            $silent,
            // The method it calls up to lets the guest through.
            $this->middleware('protected function unauthenticated($request, array $guards) { parent::unauthenticated($request, $guards); }', '\\' . $silent),
        ] as $class) {
            $this->assertFalse(AuthMiddlewareVocabulary::extendsAuthMiddleware($class), $class);
            $this->assertTrue(AuthMiddlewareVocabulary::letsGuestsThrough($class), $class);
        }
    }

    #[Test]
    public function a_descendant_that_refuses_a_guest_in_its_own_way_is_a_guard(): void
    {
        foreach ([
            $this->handling('return parent::handle(...func_get_args());'),
            $this->handling('$this->authenticate($request, $guards);'),
            $this->handling('try { $this->authenticate($request, $guards); } catch (\Throwable $e) { throw $e; }'),
            $this->handling('try { $this->authenticate($request, $guards); } finally { logger("seen"); }'),
            $this->middleware('protected function unauthenticated($request, array $guards) { abort(401); }'),
            $this->middleware('protected function redirectTo($request) { return "/login"; }'),
            ...AuthMiddlewareVocabulary::AUTH_MIDDLEWARE_BASES,
        ] as $class) {
            $this->assertTrue(AuthMiddlewareVocabulary::extendsAuthMiddleware($class), $class);
            $this->assertFalse(AuthMiddlewareVocabulary::letsGuestsThrough($class), $class);
        }

        // Nothing to read, so nothing that says it refuses.
        $gone = $this->handling('$this->authenticate($request, $guards); $seen = true;');
        File::delete((string) array_pop($this->written));
        $this->assertFalse(AuthMiddlewareVocabulary::extendsAuthMiddleware($gone));

        // No descendant at all is neither.
        $this->assertFalse(AuthMiddlewareVocabulary::letsGuestsThrough(PlainTokenMiddleware::class));
    }

    #[Test]
    public function an_optional_login_is_no_guard_on_a_route_whatever_its_alias(): void
    {
        $optional = $this->handling('');
        $this->router()->aliasMiddleware('auth', $optional);
        $this->router()->post('optional', static fn (): string => 'ok')->middleware('auth');
        $this->router()->post('optional-class', static fn (): string => 'ok')->middleware($optional);

        $this->assertSame([], $this->guards()->guardsByEntryPoint([
            'route::POST::/optional' => $this->publicSurface(publicWrite: true),
            'route::POST::/optional-class' => $this->publicSurface(publicWrite: true),
        ]));
    }

    /** The optional login as an application writes it. */
    private function optionalLogin(): string
    {
        return $this->handling('try { $this->authenticate($request, $guards); } catch (AuthenticationException) { /* a guest */ }');
    }

    /**
     * @param  array{exposure: string, riskLevel: string, issues: list<array{type: string, severity: string, message: string}>}|null  $surface
     * @return array{exposure: string, riskLevel: string, issues: list<array{type: string, severity: string, message: string, file?: string, line?: int}>}
     */
    private function surfaceAfter(string $entryPoint, ?array $surface = null): array
    {
        return $this->guards()->withoutOptionalLogins([$entryPoint => $surface ?? $this->authedSurface()])[$entryPoint];
    }

    /** @return array{exposure: string, riskLevel: string, issues: list<array{type: string, severity: string, message: string}>} */
    private function authedSurface(): array
    {
        return ['exposure' => 'authed', 'riskLevel' => 'none', 'issues' => []];
    }

    #[Test]
    public function a_login_that_provably_lets_every_guest_through_is_optional(): void
    {
        foreach ([
            $this->optionalLogin(),
            $this->handling('try { return parent::handle($request, $next, ...$guards); } catch (\Throwable) {}'),
            $this->handling('try { $user = auth("api")->authenticate(); } catch (\Exception $e) { report($e); } finally { }'),
            $this->handling('if ($this->auth->guard("api")->check()) { $this->auth->shouldUse("api"); return $next($request); }'),
            $this->handling(''),
            $this->middleware('protected function unauthenticated($request, array $guards) { /* a guest */ }'),
            $this->middleware('', '\\' . $this->optionalLogin()),
        ] as $class) {
            $this->assertTrue(OptionalLogin::by($class), $class);
        }
    }

    #[Test]
    public function a_login_that_may_refuse_a_guest_is_not_optional(): void
    {
        foreach ([
            // A helper of its own can refuse, whatever it is called.
            $this->handling('$this->refuseGuests($request);'),
            $this->handling('$this->check($request);'),
            $this->handling('self::guard($request);'),
            $this->handling('\Acme\Tokens::verify($request);'),
            // A name on the list says nothing on a receiver that is not the request or the auth manager.
            $this->handling('$this->keys->check($request);'),
            $this->handling('\Acme\ApiKey::check($request);'),
            $this->handling('$request->user()->check();'),
            $this->handling('$this->authenticate($request, $guards);'),
            $this->handling('try { $this->authenticate($request, $guards); } catch (\Throwable $e) { throw $e; }'),
            $this->handling('try { $this->authenticate($request, $guards); } catch (\Throwable) { abort(401); }'),
            $this->handling('try { $this->authenticate($request, $guards); } catch (\RuntimeException) {}'),
            $this->handling('try { abort(401); } catch (\Illuminate\Auth\AuthenticationException) {}'),
            $this->handling('if (! $request->user()) { return redirect("/login"); }'),
            $this->handling('if (! $request->user()) { throw new \RuntimeException(); }'),
            $this->handling('if (! $request->user()) { exit; }'),
            $this->handling('include "guests.php";'),
            // A resolver runs later, wherever the user is read.
            $this->handling('$request->setUserResolver(fn () => abort(401));'),
            $this->middleware('public function handle($request, \Closure $next, ...$guards) { if ($request->user()) { return $next($request); } }'),
            $this->middleware('protected function unauthenticated($request, array $guards) { abort(401); }'),
            // Two methods that decide it together.
            $this->middleware('public function handle($request, \Closure $next, ...$guards) { try { $this->authenticate($request, $guards); } catch (\Illuminate\Auth\AuthenticationException) {} return $next($request); } protected function authenticate($request, array $guards) { abort(401); }'),
            // Not a login: what it replaces refuses with another exception.
            $this->middleware('public function handle($request, \Closure $next, ...$relative) { return $next($request); }', '\\' . ValidateSignature::class),
            DescendantAuthMiddleware::class,
            ...AuthMiddlewareVocabulary::AUTH_MIDDLEWARE_BASES,
        ] as $class) {
            $this->assertFalse(OptionalLogin::by($class), $class);
        }
    }

    #[Test]
    public function a_write_behind_an_optional_login_alone_is_public(): void
    {
        $login = $this->optionalLogin();
        $this->router()->aliasMiddleware('auth', $login);
        $this->router()->middlewareGroup('members', ['throttle:api', "{$login}:api"]);
        $this->router()->post('class', static fn (): string => 'ok')->middleware($login);
        $this->router()->post('alias', static fn (): string => 'ok')->middleware('auth:api');
        $this->router()->post('group', static fn (): string => 'ok')->middleware('members');
        $this->router()->put('put', static fn (): string => 'ok')->middleware($login);
        $this->router()->patch('patch', static fn (): string => 'ok')->middleware($login);

        foreach (['class' => 'POST', 'alias' => 'POST', 'group' => 'POST', 'put' => 'PUT', 'patch' => 'PATCH'] as $uri => $method) {
            $this->assertSame([
                'exposure' => 'public',
                'riskLevel' => 'medium',
                'issues' => [['type' => 'PUBLIC_WRITE', 'severity' => 'medium', 'message' => "Mutating `{$method} /{$uri}` requires no authentication — its only auth middleware, {$login}, lets a guest through."]],
            ], $this->surfaceAfter("route::{$method}::/{$uri}"), $uri);
        }
    }

    #[Test]
    public function an_optional_login_keeps_what_brain_found_besides(): void
    {
        $this->router()->delete('notes', static fn (): string => 'ok')->middleware($this->optionalLogin());
        $this->router()->get('notes', static fn (): string => 'ok')->middleware($this->optionalLogin());
        $issue = ['type' => 'MASS_ASSIGNMENT', 'severity' => 'critical', 'message' => 'x'];

        $deleted = $this->surfaceAfter('route::DELETE::/notes', ['exposure' => 'authed', 'riskLevel' => 'critical', 'issues' => [$issue]]);

        $this->assertSame('critical', $deleted['riskLevel']);
        $this->assertSame(['MASS_ASSIGNMENT', 'PUBLIC_WRITE'], array_column($deleted['issues'], 'type'));
        $this->assertSame('high', $deleted['issues'][1]['severity'] ?? null);
        $this->assertSame(['exposure' => 'public'] + $this->authedSurface(), $this->surfaceAfter('route::GET::/notes'));

        $this->router()->post('notes', static fn (): string => 'ok')->middleware($this->optionalLogin());
        $this->assertSame('high', $this->surfaceAfter('route::POST::/notes', ['riskLevel' => 'high'] + $this->authedSurface())['riskLevel']);
    }

    #[Test]
    public function a_route_the_project_trusts_gets_no_public_write(): void
    {
        config(['laravel-brain.security.trusted_route_names' => ['Hooks.*'], 'laravel-brain.security.trusted_route_uris' => ['/callbacks/*']]);
        $this->router()->post('named', static fn (): string => 'ok')->middleware($this->optionalLogin())->name('hooks.store');
        $this->router()->post('callbacks/one', static fn (): string => 'ok')->middleware($this->optionalLogin());

        foreach (['route::POST::/named', 'route::POST::/callbacks/one'] as $entryPoint) {
            $this->assertSame(['exposure' => 'public'] + $this->authedSurface(), $this->surfaceAfter($entryPoint));
        }

        // A trusted twin does not answer for the other.
        $this->router()->group(['domain' => 'one.example.test'], fn () => $this->router()->post('twins', static fn (): string => 'ok')->middleware($this->optionalLogin())->name('hooks.twin'));
        $this->router()->group(['domain' => 'two.example.test'], fn () => $this->router()->post('twins', static fn (): string => 'ok')->middleware($this->optionalLogin()));
        $this->assertSame(['PUBLIC_WRITE'], array_column($this->surfaceAfter('route::POST::/twins')['issues'], 'type'));
    }

    #[Test]
    public function brains_word_stands_where_the_optional_login_is_not_proven_to_be_all(): void
    {
        config(['laravel-brain.security.auth_middleware' => ['partners', PlainTokenMiddleware::class]]);
        $this->router()->aliasMiddleware('token', PlainTokenMiddleware::class);
        $login = $this->optionalLogin();
        $this->router()->middlewareGroup('secure', [Authenticate::class]);
        $this->router()->middlewareGroup('partners', [PlainTokenMiddleware::class]);
        $this->router()->middlewareGroup('looping', ['looping', $login]);
        $this->router()->post('guarded', static fn (): string => 'ok')->middleware([$login, Authenticate::class]);
        $this->router()->post('guarded-group', static fn (): string => 'ok')->middleware(['secure', $login]);
        $this->router()->post('named-group', static fn (): string => 'ok')->middleware(['partners', $login]);
        $this->router()->post('looping', static fn (): string => 'ok')->middleware('looping');
        $this->router()->post('excluded', static fn (): string => 'ok')->middleware($login)->withoutMiddleware($login);
        $this->router()->post('unclear', static fn (): string => 'ok')->middleware($this->handling('$this->refuseGuests($request);'));
        $this->router()->post('plain', static fn (): string => 'ok')->middleware(PlainTokenMiddleware::class);
        $this->router()->post('configured-alias', static fn (): string => 'ok')->middleware([$login, 'token']);
        $this->router()->post('optional', static fn (): string => 'ok')->middleware($login);
        $this->router()->post('controller-guarded', [AuthenticatedController::class, 'store'])->middleware($login);
        $this->router()->group(['domain' => 'one.example.test'], function () use ($login): void {
            $this->router()->post('twins', static fn (): string => 'ok')->middleware($login);
        });
        $this->router()->group(['domain' => 'two.example.test'], function (): void {
            $this->router()->post('twins', static fn (): string => 'ok')->middleware(Authenticate::class);
        });

        foreach (['guarded', 'guarded-group', 'named-group', 'controller-guarded', 'configured-alias', 'looping', 'excluded', 'unclear', 'plain', 'twins', 'unregistered'] as $uri) {
            $this->assertSame($this->authedSurface(), $this->surfaceAfter("route::POST::/{$uri}"), $uri);
        }

        $admin = ['exposure' => 'admin'] + $this->authedSurface();
        $this->assertSame($admin, $this->surfaceAfter('route::POST::/optional', $admin));

        foreach ([null, '/another/checkout'] as $root) {
            $surfaces = ['route::POST::/optional' => $this->authedSurface()];
            $this->assertSame($surfaces, new RuntimeRouterGuards($root)->withoutOptionalLogins($surfaces));
        }
    }

    #[Test]
    public function an_optional_login_route_is_public_in_a_report_and_for_its_hazards(): void
    {
        $this->router()->post('notes', static fn (): string => 'ok')->middleware($this->optionalLogin());
        $analyzer = new ImpactAnalyzer(new CodeGraph(
            [['source' => 'route::POST::/notes', 'target' => 'App\Services\Publisher::publish', 'type' => 'route-to-controller']],
            hasUnparseableFiles: false,
            nodeMetadata: ['route::POST::/notes' => ['security' => $this->authedSurface()]],
        ));
        $changed = [new ChangedFileSymbols(
            'app/Services/Publisher.php',
            'App\Services\Publisher',
            [new MemberChange('publish', MemberChange::KIND_METHOD, MemberChange::CHANGE_MODIFIED, resolvable: true)],
            cosmeticOnly: false,
            hazards: [new Hazard('contract', 2, null, 'App\Services\Publisher::publish', 'the return type changed')],
        )];

        foreach ([[base_path(), 'public', Hazard::REACH_PUBLIC_WRITE], [null, 'authed', Hazard::REACH_GATED]] as [$root, $exposure, $reach]) {
            $result = $analyzer->detectChanges($changed, runtimeEvidenceRoot: $root);
            $impact = $analyzer->impact('App\Services\Publisher::publish', runtimeEvidenceRoot: $root);

            $this->assertSame($exposure, $impact['entryPointSecurity']['route::POST::/notes']['exposure']);
            $this->assertSame($exposure, $result['entryPointSecurity']['route::POST::/notes']['exposure']);
            $this->assertSame([$reach], array_column($result['hazards'], 'reach'));
        }
    }

    #[Test]
    public function a_route_with_no_optional_login_never_builds_its_controller(): void
    {
        $this->router()->post('built', [UnbuildableController::class, 'store'])->middleware(Authenticate::class);

        $this->assertSame($this->authedSurface(), $this->surfaceAfter('route::POST::/built'));
        $this->assertFalse(UnbuildableController::$built);
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

final class UnbuildableController
{
    public static bool $built = false;

    public function __construct()
    {
        self::$built = true;
    }

    public function store(): string
    {
        return 'ok';
    }
}

final class DescendantAuthMiddleware extends Authenticate {}

final class AuthenticatedController implements HasMiddleware
{
    public static function middleware(): array
    {
        return [Authenticate::class];
    }

    public function store(): string
    {
        return 'ok';
    }
}

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
