<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Analysis\Hazard;
use SanderMuller\Richter\Analysis\HazardFindings;
use SanderMuller\Richter\Analysis\Hazards\GuardMiddleware;
use SanderMuller\Richter\Analysis\Hazards\RouteFileHazards;
use SanderMuller\Richter\Changes\ChangedFileSymbols;
use SanderMuller\Richter\Tests\TestCase;

final class RouteFileHazardsTest extends TestCase
{
    private const string FILE = 'routes/web.php';

    private function routes(string $body): string
    {
        return "<?php\nuse App\\Http\\Controllers\\ArchiveController;\nuse App\\Http\\Controllers\\PostController;\nuse Illuminate\\Support\\Facades\\Route;\n{$body}\n";
    }

    /** @return list<Hazard> */
    private function hazards(string $head, string $base): array
    {
        return RouteFileHazards::for(self::FILE, $this->routes($head), $this->routes($base))[0];
    }

    /** @return list<string> */
    private function added(string $head, string $base): array
    {
        return RouteFileHazards::for(self::FILE, $this->routes($head), $this->routes($base))[1];
    }

    // ------------------------------------------------------------- removal

    #[Test]
    public function middleware_dropped_from_a_route_is_tier_three(): void
    {
        $hazards = $this->hazards(
            "Route::get('/posts/{post}/edit', [PostController::class, 'edit']);",
            "Route::get('/posts/{post}/edit', [PostController::class, 'edit'])->middleware('auth');",
        );

        $this->assertCount(1, $hazards);
        $this->assertSame('auth', $hazards[0]->lane);
        $this->assertSame(3, $hazards[0]->tier);
        $this->assertSame('CWE-306', $hazards[0]->cwe);
        $this->assertSame('App\Http\Controllers\PostController::edit', $hazards[0]->member);
        $this->assertSame(['middleware:auth'], $hazards[0]->removedTokens);
        $this->assertStringContainsString('GET /posts/{post}/edit', $hazards[0]->evidence);
    }

    #[Test]
    public function a_can_middleware_dropped_is_an_authorization_removal(): void
    {
        $hazards = $this->hazards(
            "Route::delete('/posts/{post}', [PostController::class, 'destroy']);",
            "Route::delete('/posts/{post}', [PostController::class, 'destroy'])->middleware('can:delete,post');",
        );

        $this->assertSame(['CWE-862'], array_column($hazards, 'cwe'));
        $this->assertSame(['middleware:can:delete,post'], $hazards[0]->removedTokens);
    }

    #[Test]
    public function a_parameterised_auth_guard_is_read_as_a_guard(): void
    {
        // Named by the guard, not by the driver: `auth:sanctum` and `auth:web` are both the auth
        // guard, so the token that stands for the removal is the alias alone.
        $hazards = $this->hazards(
            "Route::post('/posts', [PostController::class, 'store']);",
            "Route::post('/posts', [PostController::class, 'store'])->middleware('auth:sanctum');",
        );

        $this->assertSame(['middleware:auth'], $hazards[0]->removedTokens);
    }

    #[Test]
    public function a_framework_guard_named_by_its_class_is_read_as_a_guard(): void
    {
        // A route can name its middleware by class as easily as by alias, and both stand for the same
        // guard — so both have to produce the same token, or one spelling is a blind spot.
        $hazards = RouteFileHazards::for(self::FILE,
            "<?php\nuse App\\Http\\Controllers\\PostController;\nuse Illuminate\\Auth\\Middleware\\Authenticate;\nuse Illuminate\\Support\\Facades\\Route;\nRoute::get('/posts', [PostController::class, 'index']);\n",
            "<?php\nuse App\\Http\\Controllers\\PostController;\nuse Illuminate\\Auth\\Middleware\\Authenticate;\nuse Illuminate\\Support\\Facades\\Route;\nRoute::get('/posts', [PostController::class, 'index'])->middleware(Authenticate::class);\n",
        )[0];

        $this->assertSame(['middleware:auth'], $hazards[0]->removedTokens);
    }

    #[Test]
    public function the_authorization_middleware_class_is_read_as_a_guard(): void
    {
        $hazards = RouteFileHazards::for(self::FILE,
            "<?php\nuse App\\Http\\Controllers\\PostController;\nuse Illuminate\\Auth\\Middleware\\Authorize;\nuse Illuminate\\Support\\Facades\\Route;\nRoute::get('/posts', [PostController::class, 'index']);\n",
            "<?php\nuse App\\Http\\Controllers\\PostController;\nuse Illuminate\\Auth\\Middleware\\Authorize;\nuse Illuminate\\Support\\Facades\\Route;\nRoute::get('/posts', [PostController::class, 'index'])->middleware(Authorize::class);\n",
        )[0];

        $this->assertSame(['middleware:can'], $hazards[0]->removedTokens);
        $this->assertSame('CWE-862', $hazards[0]->cwe);
    }

    #[Test]
    public function an_unrecognised_middleware_draws_nothing(): void
    {
        $this->assertSame([], $this->hazards(
            "Route::post('/posts', [PostController::class, 'store']);",
            "Route::post('/posts', [PostController::class, 'store'])->middleware('log.requests');",
        ));
    }

    #[Test]
    public function a_guard_added_to_a_route_is_reported_as_an_arrival(): void
    {
        $this->assertSame(['middleware:auth'], $this->added(
            "Route::post('/posts', [PostController::class, 'store'])->middleware('auth');",
            "Route::post('/posts', [PostController::class, 'store']);",
        ));
    }

    #[Test]
    public function a_registrar_first_declaration_is_read_like_any_other(): void
    {
        // `Route::middleware('auth')->get(...)` is as common as the chained form, and the registration
        // sits at the END of the chain rather than at its root.
        $hazards = $this->hazards(
            "Route::get('/posts', [PostController::class, 'index']);",
            "Route::middleware('auth')->get('/posts', [PostController::class, 'index']);",
        );

        $this->assertCount(1, $hazards);
        $this->assertSame('App\Http\Controllers\PostController::index', $hazards[0]->member);
        $this->assertSame(['middleware:auth'], $hazards[0]->removedTokens);
    }

    // --------------------------------------------------------------- moves

    #[Test]
    public function wrapping_a_route_in_a_guarded_group_is_not_a_removal(): void
    {
        $this->assertSame([], $this->hazards(
            "Route::middleware('auth')->group(function (): void {\n    Route::get('/posts/{post}/edit', [PostController::class, 'edit']);\n});",
            "Route::get('/posts/{post}/edit', [PostController::class, 'edit'])->middleware('auth');",
        ));
    }

    #[Test]
    public function the_array_group_form_carries_its_middleware_too(): void
    {
        // Both directions, because only one of them proves the group body was walked at all. With the
        // group on the HEAD side a walk that never entered it would find no route, read the base route
        // as deleted, and pass while seeing nothing — so the base side carries the group here, where
        // failing to walk it means finding no base route and reporting the removal that follows.
        $this->assertSame([], $this->hazards(
            "Route::group(['middleware' => ['auth']], function (): void {\n    Route::get('/posts/{post}/edit', [PostController::class, 'edit']);\n});",
            "Route::get('/posts/{post}/edit', [PostController::class, 'edit'])->middleware('auth');",
        ));

        $this->assertSame([], $this->hazards(
            "Route::get('/posts/{post}/edit', [PostController::class, 'edit'])->middleware('auth');",
            "Route::group(['middleware' => ['auth']], function (): void {\n    Route::get('/posts/{post}/edit', [PostController::class, 'edit']);\n});",
        ));
    }

    #[Test]
    public function an_arrow_function_group_body_is_walked(): void
    {
        $this->assertSame([], $this->hazards(
            "Route::get('/posts', [PostController::class, 'index'])->middleware('auth');",
            "Route::middleware(['auth'])->prefix('admin')->group(fn () => Route::get('/posts', [PostController::class, 'index']));",
        ));
    }

    #[Test]
    public function a_group_that_loses_its_middleware_reports_every_route_under_it(): void
    {
        $hazards = $this->hazards(
            "Route::prefix('admin')->group(function (): void {\n    Route::get('/posts', [PostController::class, 'index']);\n    Route::post('/posts', [PostController::class, 'store']);\n});",
            "Route::middleware('auth')->prefix('admin')->group(function (): void {\n    Route::get('/posts', [PostController::class, 'index']);\n    Route::post('/posts', [PostController::class, 'store']);\n});",
        );

        $this->assertSame([
            'App\Http\Controllers\PostController::index',
            'App\Http\Controllers\PostController::store',
        ], array_column($hazards, 'member'));
    }

    #[Test]
    public function a_nested_group_inherits_the_outer_guard(): void
    {
        $this->assertSame([], $this->hazards(
            "Route::middleware('auth')->group(function (): void {\n    Route::prefix('admin')->group(function (): void {\n        Route::get('/posts', [PostController::class, 'index']);\n    });\n});",
            "Route::get('/posts', [PostController::class, 'index'])->middleware('auth');",
        ));

        $this->assertSame([], $this->hazards(
            "Route::get('/posts', [PostController::class, 'index'])->middleware('auth');",
            "Route::middleware('auth')->group(function (): void {\n    Route::prefix('admin')->group(function (): void {\n        Route::get('/posts', [PostController::class, 'index']);\n    });\n});",
        ));
    }

    #[Test]
    public function a_route_lifted_out_of_a_guarded_group_loses_the_guard(): void
    {
        $hazards = $this->hazards(
            "Route::get('/posts/{post}/edit', [PostController::class, 'edit']);",
            "Route::middleware('auth')->group(function (): void {\n    Route::get('/posts/{post}/edit', [PostController::class, 'edit']);\n});",
        );

        $this->assertSame(['middleware:auth'], $hazards[0]->removedTokens);
    }

    #[Test]
    public function a_guard_moved_between_two_routes_still_reports_the_route_that_lost_it(): void
    {
        $hazards = $this->hazards(
            "Route::get('/a', [PostController::class, 'index']);\nRoute::get('/b', [PostController::class, 'show'])->middleware('auth');",
            "Route::get('/a', [PostController::class, 'index'])->middleware('auth');\nRoute::get('/b', [PostController::class, 'show']);",
        );

        $this->assertCount(1, $hazards);
        $this->assertSame('App\Http\Controllers\PostController::index', $hazards[0]->member);

        // And it survives the whole-diff pass. `middleware:auth` names no particular surface, so
        // treating the other route's arrival as the same guard moving would silence every guard
        // removal in any diff that also adds one guarded route.
        $this->assertCount(1, HazardFindings::for([
            new ChangedFileSymbols(self::FILE, '', [], cosmeticOnly: false, hazards: $hazards,
                addedHazardTokens: $this->added(
                    "Route::get('/a', [PostController::class, 'index']);\nRoute::get('/b', [PostController::class, 'show'])->middleware('auth');",
                    "Route::get('/a', [PostController::class, 'index'])->middleware('auth');\nRoute::get('/b', [PostController::class, 'show']);",
                )),
        ]));
    }

    #[Test]
    public function withoutmiddleware_added_on_the_same_chain_is_a_removal(): void
    {
        // The chain is read in CALL order. Unwound as PhpParser nests it, the outermost call comes
        // first, and this route would subtract the guard before adding it — grading a route that
        // explicitly opted out of `auth` as guarded.
        $hazards = $this->hazards(
            "Route::get('/posts', [PostController::class, 'index'])->middleware('auth')->withoutMiddleware('auth');",
            "Route::get('/posts', [PostController::class, 'index'])->middleware('auth');",
        );

        $this->assertSame(['middleware:auth'], $hazards[0]->removedTokens);
    }

    #[Test]
    public function withoutmiddleware_added_under_a_guarded_group_is_a_removal(): void
    {
        $hazards = $this->hazards(
            "Route::middleware('auth')->group(function (): void {\n    Route::get('/posts', [PostController::class, 'index'])->withoutMiddleware('auth');\n});",
            "Route::middleware('auth')->group(function (): void {\n    Route::get('/posts', [PostController::class, 'index']);\n});",
        );

        $this->assertSame(['middleware:auth'], $hazards[0]->removedTokens);
    }

    #[Test]
    public function a_route_repointed_in_the_same_edit_is_named_by_its_head_action(): void
    {
        $hazards = $this->hazards(
            "Route::get('/posts', [ArchiveController::class, 'index']);",
            "Route::get('/posts', [PostController::class, 'index'])->middleware('auth');",
        );

        $this->assertSame('App\Http\Controllers\ArchiveController::index', $hazards[0]->member);
    }

    #[Test]
    public function two_registrations_sharing_a_verb_and_uri_do_not_overwrite_each_other(): void
    {
        // The same path under two different prefixes. The later registration must not replace the
        // earlier one, or a guard removed from the first is compared against the second and missed.
        $hazards = $this->hazards(
            "Route::prefix('admin')->group(function (): void {\n    Route::get('/posts', [PostController::class, 'index']);\n});\nRoute::prefix('team')->group(function (): void {\n    Route::get('/posts', [PostController::class, 'index'])->middleware('auth');\n});",
            "Route::prefix('admin')->group(function (): void {\n    Route::get('/posts', [PostController::class, 'index'])->middleware('auth');\n});\nRoute::prefix('team')->group(function (): void {\n    Route::get('/posts', [PostController::class, 'index'])->middleware('auth');\n});",
        );

        // Unioned, so the guard is still on the key at head and nothing is claimed. The point of the
        // test is that the second registration did not erase the first: with an overwrite the base
        // side would read one guarded route and the head side one unguarded one.
        $this->assertSame([], $hazards);

        $this->assertSame(['middleware:auth'], $this->hazards(
            "Route::prefix('admin')->group(function (): void {\n    Route::get('/posts', [PostController::class, 'index']);\n});\nRoute::prefix('team')->group(function (): void {\n    Route::get('/posts', [PostController::class, 'index']);\n});",
            "Route::prefix('admin')->group(function (): void {\n    Route::get('/posts', [PostController::class, 'index'])->middleware('auth');\n});\nRoute::prefix('team')->group(function (): void {\n    Route::get('/posts', [PostController::class, 'index'])->middleware('auth');\n});",
        )[0]->removedTokens);
    }

    #[Test]
    public function a_guard_removed_from_a_file_backed_group_is_a_removal(): void
    {
        // `->group(base_path('routes/admin.php'))` has no body to walk, so the group itself is the
        // comparable unit. Dropping it silently would say nothing about every route in that file.
        $hazards = $this->hazards(
            "Route::group(base_path('routes/admin.php'));",
            "Route::middleware('auth')->group(base_path('routes/admin.php'));",
        );

        $this->assertCount(1, $hazards);
        $this->assertSame(['middleware:auth'], $hazards[0]->removedTokens);
        $this->assertStringContainsString('group loading', $hazards[0]->evidence);
    }

    #[Test]
    public function a_route_renamed_in_the_same_edit_that_drops_its_guard_is_still_compared(): void
    {
        // The key is the registration as written, so changing the URI changes it. Without a fallback
        // the base route reads as deleted — and a deleted route raises nothing, which would make
        // "rename the URI and drop the guard" the one edit this lane cannot see.
        $hazards = $this->hazards(
            "Route::get('/articles/{post}/edit', [PostController::class, 'edit']);",
            "Route::get('/posts/{post}/edit', [PostController::class, 'edit'])->middleware('auth');",
        );

        $this->assertCount(1, $hazards);
        $this->assertSame(['middleware:auth'], $hazards[0]->removedTokens);
    }

    #[Test]
    public function an_ambiguous_action_is_not_paired_across_a_rename(): void
    {
        // Two base routes on the same action name neither of them uniquely. Pairing the wrong two
        // would report a removal on a route that never carried the guard, so neither is paired.
        $this->assertSame([], $this->hazards(
            "Route::get('/a', [PostController::class, 'index']);\nRoute::get('/b', [PostController::class, 'index']);",
            "Route::get('/x', [PostController::class, 'index'])->middleware('auth');\nRoute::get('/y', [PostController::class, 'index']);",
        ));
    }

    #[Test]
    public function a_root_level_withoutmiddleware_group_removes_the_inherited_guard(): void
    {
        $hazards = $this->hazards(
            "Route::middleware('auth')->group(function (): void {\n    Route::withoutMiddleware('auth')->group(function (): void {\n        Route::get('/open', [PostController::class, 'index']);\n    });\n});",
            "Route::middleware('auth')->group(function (): void {\n    Route::get('/open', [PostController::class, 'index']);\n});",
        );

        $this->assertCount(1, $hazards);
        $this->assertSame(['middleware:auth'], $hazards[0]->removedTokens);
    }

    #[Test]
    public function an_application_guard_class_resolves_through_the_projects_own_alias_map(): void
    {
        // The fixture project registers `'auth' => App\Http\Middleware\Authenticate::class`, which is
        // the stock Laravel scaffolding shape. A route naming that class is naming the `auth` guard,
        // and the project said so itself — this is declared intent, not an inference from `extends`.
        $original = base_path();
        app()->setBasePath(self::fixtureProjectPath());
        GuardMiddleware::flush();

        try {
            $hazards = RouteFileHazards::for(self::FILE,
                "<?php\nuse App\\Http\\Controllers\\PostController;\nuse App\\Http\\Middleware\\Authenticate;\nuse Illuminate\\Support\\Facades\\Route;\nRoute::get('/posts', [PostController::class, 'index']);\n",
                "<?php\nuse App\\Http\\Controllers\\PostController;\nuse App\\Http\\Middleware\\Authenticate;\nuse Illuminate\\Support\\Facades\\Route;\nRoute::middleware(Authenticate::class)->get('/posts', [PostController::class, 'index']);\n",
            )[0];

            $this->assertCount(1, $hazards);
            $this->assertSame(['middleware:auth'], $hazards[0]->removedTokens);
            $this->assertSame('CWE-306', $hazards[0]->cwe);
        } finally {
            app()->setBasePath($original);
            GuardMiddleware::flush();
        }
    }

    #[Test]
    public function a_middleware_class_the_project_registers_under_no_guard_alias_draws_nothing(): void
    {
        // The same fixture registers `category.auth`, which is not a name richter knows. An
        // application middleware richter cannot place is still a guess, and still draws nothing.
        $original = base_path();
        app()->setBasePath(self::fixtureProjectPath());
        GuardMiddleware::flush();

        try {
            $this->assertSame([], RouteFileHazards::for(self::FILE,
                "<?php\nuse App\\Http\\Controllers\\PostController;\nuse App\\Http\\Middleware\\CategoryAuthenticate;\nuse Illuminate\\Support\\Facades\\Route;\nRoute::get('/posts', [PostController::class, 'index']);\n",
                "<?php\nuse App\\Http\\Controllers\\PostController;\nuse App\\Http\\Middleware\\CategoryAuthenticate;\nuse Illuminate\\Support\\Facades\\Route;\nRoute::middleware(CategoryAuthenticate::class)->get('/posts', [PostController::class, 'index']);\n",
            )[0]);
        } finally {
            app()->setBasePath($original);
            GuardMiddleware::flush();
        }
    }

    #[Test]
    public function a_guard_a_package_ships_is_in_the_vocabulary(): void
    {
        // An application gating on spatie/laravel-permission's `role` got no report at all while
        // richter did not know the name, and its own middleware is registered under that alias.
        $hazards = $this->hazards(
            "Route::get('/admin', [PostController::class, 'index']);",
            "Route::get('/admin', [PostController::class, 'index'])->middleware('role:admin');",
        );

        $this->assertCount(1, $hazards);
        $this->assertSame(3, $hazards[0]->tier);
        $this->assertSame('CWE-862', $hazards[0]->cwe);
        $this->assertSame(['middleware:role:admin'], $hazards[0]->removedTokens);
    }

    #[Test]
    public function a_middleware_outside_the_vocabulary_still_draws_nothing(): void
    {
        $this->assertSame([], $this->hazards(
            "Route::get('/admin', [PostController::class, 'index']);",
            "Route::get('/admin', [PostController::class, 'index'])->middleware('tenant:strict');",
        ));
    }

    #[Test]
    public function a_scoped_guards_parameter_is_part_of_the_guard(): void
    {
        // The parameter names what is authorised, so a different one is a different check: a route
        // that required `update` and now requires `view` lost the check it had.
        foreach ([['can:update,post', 'can:view,post'], ['role:admin', 'role:editor']] as [$before, $after]) {
            $hazards = $this->hazards(
                "Route::get('/posts', [PostController::class, 'index'])->middleware('{$after}');",
                "Route::get('/posts', [PostController::class, 'index'])->middleware('{$before}');",
            );

            $this->assertSame(["middleware:{$before}"], $hazards[0]->removedTokens);
        }
    }

    #[Test]
    public function a_raised_rate_limit_is_a_weakened_constraint_rather_than_a_removed_guard(): void
    {
        $hazards = $this->hazards(
            "Route::get('/search', [PostController::class, 'index'])->middleware('throttle:120,1');",
            "Route::get('/search', [PostController::class, 'index'])->middleware('throttle:60,1');",
        );

        $this->assertCount(1, $hazards);
        $this->assertSame(2, $hazards[0]->tier);
        $this->assertSame('CWE-770', $hazards[0]->cwe);
        $this->assertStringContainsString('rose from `throttle:60,1` to `throttle:120,1`', $hazards[0]->evidence);
    }

    #[Test]
    public function a_guard_built_by_a_static_call_is_read(): void
    {
        // `->middleware(ThrottleRequests::with(30, 1))` names the same guard as `throttle:30,1`. The
        // reader saw a string and a class constant and nothing else, so this drew nothing at all —
        // removal included, on routes whose rate limit was the only guard they had.
        $route = "<?php\nuse App\\Http\\Controllers\\PostController;\nuse Illuminate\\Routing\\Middleware\\ThrottleRequests;\nuse Illuminate\\Support\\Facades\\Route;\nRoute::get('/streetview', [PostController::class, 'index'])%s;\n";

        $removed = RouteFileHazards::for(self::FILE, sprintf($route, ''), sprintf($route, '->middleware(ThrottleRequests::with(30, 1))'))[0];

        $this->assertCount(1, $removed);
        $this->assertSame(3, $removed[0]->tier);
        $this->assertSame(['middleware:throttle:30,1'], $removed[0]->removedTokens);

        // And it feeds the rate comparison this release built, in both directions.
        $raised = RouteFileHazards::for(self::FILE,
            sprintf($route, '->middleware(ThrottleRequests::with(120, 1))'),
            sprintf($route, '->middleware(ThrottleRequests::with(30, 1))'))[0];

        $this->assertSame(2, $raised[0]->tier);
        $this->assertStringContainsString('rose from `throttle:30,1` to `throttle:120,1`', $raised[0]->evidence);

        $this->assertSame([], RouteFileHazards::for(self::FILE,
            sprintf($route, '->middleware(ThrottleRequests::with(15, 1))'),
            sprintf($route, '->middleware(ThrottleRequests::with(30, 1))'))[0]);
    }

    #[Test]
    public function a_named_limiter_built_by_a_static_call_is_present_or_absent(): void
    {
        // `using()` names a limiter whose rate lives in a `RateLimiter::for()` closure nothing here
        // follows, so it answers the bare alias: a removal is tier 3, and swapping the limiter says
        // nothing. The same reading the reader already gives `throttle:api`.
        $route = "<?php\nuse App\\Http\\Controllers\\PostController;\nuse App\\Support\\Limiter;\nuse Illuminate\\Routing\\Middleware\\ThrottleRequests;\nuse Illuminate\\Support\\Facades\\Route;\nRoute::post('/verify', [PostController::class, 'store'])%s;\n";

        $removed = RouteFileHazards::for(self::FILE, sprintf($route, ''),
            sprintf($route, '->middleware(ThrottleRequests::using(Limiter::GuestVerification))'))[0];

        $this->assertCount(1, $removed);
        $this->assertSame(3, $removed[0]->tier);
        $this->assertSame(['middleware:throttle'], $removed[0]->removedTokens);

        $this->assertSame([], RouteFileHazards::for(self::FILE,
            sprintf($route, '->middleware(ThrottleRequests::using(Limiter::Signup))'),
            sprintf($route, '->middleware(ThrottleRequests::using(Limiter::GuestVerification))'))[0]);
    }

    #[Test]
    public function a_builders_class_constant_argument_keeps_the_check_it_names(): void
    {
        // `Authorize::using('update', Post::class)` names an ability and a model exactly as
        // `can:update,post` does. Reading it as the bare `can` would collapse two different
        // authorization checks onto one token and hide the change between them.
        $route = "<?php\nuse App\\Http\\Controllers\\PostController;\nuse App\\Models\\Post;\nuse Illuminate\\Auth\\Middleware\\Authorize;\nuse Illuminate\\Support\\Facades\\Route;\nRoute::get('/posts', [PostController::class, 'index'])->middleware(Authorize::using('%s', Post::class));\n";

        $hazards = RouteFileHazards::for(self::FILE, sprintf($route, 'view'), sprintf($route, 'update'))[0];

        $this->assertCount(1, $hazards);
        $this->assertSame(['middleware:can:update,App\Models\Post'], $hazards[0]->removedTokens);
        $this->assertSame('CWE-862', $hazards[0]->cwe);
    }

    #[Test]
    public function a_static_call_the_reader_cannot_evaluate_falls_back_to_the_bare_guard(): void
    {
        // A named argument read positionally would invent a limit, so the guard answers present or
        // absent instead. An unmapped class still draws nothing at all.
        $route = "<?php\nuse App\\Http\\Controllers\\PostController;\nuse App\\Support\\Tenancy;\nuse Illuminate\\Routing\\Middleware\\ThrottleRequests;\nuse Illuminate\\Support\\Facades\\Route;\nRoute::get('/posts', [PostController::class, 'index'])%s;\n";

        $named = RouteFileHazards::for(self::FILE, sprintf($route, ''),
            sprintf($route, '->middleware(ThrottleRequests::with(maxAttempts: 30))'))[0];

        $this->assertSame(['middleware:throttle'], $named[0]->removedTokens);

        $this->assertSame([], RouteFileHazards::for(self::FILE, sprintf($route, ''),
            sprintf($route, '->middleware(Tenancy::strict())'))[0]);

        // An argument that is neither a scalar nor a `::class` constant is not the parameter either.
        $variable = RouteFileHazards::for(self::FILE, sprintf($route, ''),
            sprintf($route, '->middleware(ThrottleRequests::with($limit, 1))'))[0];

        $this->assertSame(['middleware:throttle'], $variable[0]->removedTokens);
    }

    #[Test]
    public function a_route_replacing_several_throttles_reports_the_change_once(): void
    {
        // A route has one effective limit however many throttles it lists. Reading the change per lost
        // token reported the same rise once for each of them.
        $hazards = $this->hazards(
            "Route::get('/search', [PostController::class, 'index'])->middleware(['throttle:120,1', 'throttle:200,1']);",
            "Route::get('/search', [PostController::class, 'index'])->middleware(['throttle:60,1', 'throttle:100,1']);",
        );

        $this->assertCount(1, $hazards);
        $this->assertStringContainsString('rose from `throttle:60,1` to `throttle:120,1`', $hazards[0]->evidence);
    }

    #[Test]
    public function a_stricter_throttle_beside_the_raised_one_keeps_the_limit(): void
    {
        // Every throttle on the route applies, so the strictest is the limit. Reading the first looser
        // one reported a weakening on a route that kept a tighter throttle beside it.
        $this->assertSame([], $this->hazards(
            "Route::get('/search', [PostController::class, 'index'])->middleware(['throttle:30,1', 'throttle:120,1']);",
            "Route::get('/search', [PostController::class, 'index'])->middleware('throttle:60,1');",
        ));
    }

    #[Test]
    public function a_rate_limit_this_reader_cannot_compare_says_nothing(): void
    {
        // One rate the reader cannot read makes the whole set unreadable: it could be the strict one.
        // True of either side — a base carrying a named limiter beside a numeric throttle has an
        // effective limit nobody here can name, so reading the numeric one as the limit would report a
        // weakening the named limiter may well have prevented.
        $this->assertSame([], $this->hazards(
            "Route::get('/search', [PostController::class, 'index'])->middleware(['throttle:api', 'throttle:120,1']);",
            "Route::get('/search', [PostController::class, 'index'])->middleware('throttle:60,1');",
        ));

        $this->assertSame([], $this->hazards(
            "Route::get('/search', [PostController::class, 'index'])->middleware('throttle:120,1');",
            "Route::get('/search', [PostController::class, 'index'])->middleware(['throttle:api', 'throttle:60,1']);",
        ));

        // A named limiter's rate lives in a `RateLimiter::for()` closure this reader does not follow,
        // and a longer window is not automatically looser. Silence beats a guess in both directions.
        // The last pair counts over a different window, and fixed windows have no ordering between
        // them: `throttle:100,60` allows a burst of a hundred in one minute where `throttle:2,1`
        // allows two, yet the second averages the higher rate.
        foreach ([['throttle:api', 'throttle:web'], ['throttle:60,1', 'throttle:api'], ['throttle:100,60', 'throttle:2,1']] as [$before, $after]) {
            $this->assertSame([], $this->hazards(
                "Route::get('/search', [PostController::class, 'index'])->middleware('{$after}');",
                "Route::get('/search', [PostController::class, 'index'])->middleware('{$before}');",
            ), "{$before} -> {$after}");
        }
    }

    #[Test]
    public function a_set_scoped_guard_reordered_is_not_a_removal(): void
    {
        // Sanctum checks the same abilities either way round, and spatie admits the same people. Only
        // `can` keeps its order, because its parameters are positional rather than a set.
        foreach ([['abilities:read,write', 'abilities:write,read'], ['role:admin|editor', 'role:editor|admin']] as [$before, $after]) {
            $this->assertSame([], $this->hazards(
                "Route::get('/posts', [PostController::class, 'index'])->middleware('{$after}');",
                "Route::get('/posts', [PostController::class, 'index'])->middleware('{$before}');",
            ), "{$before} -> {$after}");
        }

        $this->assertCount(1, $this->hazards(
            "Route::get('/posts', [PostController::class, 'index'])->middleware('can:post,update');",
            "Route::get('/posts', [PostController::class, 'index'])->middleware('can:update,post');",
        ));

        // spatie separates its roles with pipes and then takes an optional guard name after a comma.
        // Only the pipes are a set: `role:admin,web` and `role:web,admin` name a different role and a
        // different guard, so sorting across the comma would hide a real authorization change.
        $this->assertCount(1, $this->hazards(
            "Route::get('/posts', [PostController::class, 'index'])->middleware('role:web,admin');",
            "Route::get('/posts', [PostController::class, 'index'])->middleware('role:admin,web');",
        ));

        $this->assertSame([], $this->hazards(
            "Route::get('/posts', [PostController::class, 'index'])->middleware('role:editor|admin,web');",
            "Route::get('/posts', [PostController::class, 'index'])->middleware('role:admin|editor,web');",
        ));
    }

    #[Test]
    public function an_unscoped_guards_parameter_is_configuration_and_not_the_guard(): void
    {
        // Tightening a rate limit reported the limit as REMOVED, at tier 3, and after the per-guard
        // CWE work it said CWE-770 — a limit went missing — about a limit that got stricter. Switching
        // an auth driver read the same way. Neither is a removal: the guard is still there.
        foreach ([['auth', 'auth:sanctum'], ['auth:web', 'auth:sanctum'], ['throttle:60,1', 'throttle:30,1']] as [$before, $after]) {
            $this->assertSame([], $this->hazards(
                "Route::get('/posts', [PostController::class, 'index'])->middleware('{$after}');",
                "Route::get('/posts', [PostController::class, 'index'])->middleware('{$before}');",
            ), "{$before} -> {$after}");
        }

        // Dropping it altogether is still a removal, named by the guard rather than by its parameter.
        $hazards = $this->hazards(
            "Route::get('/posts', [PostController::class, 'index']);",
            "Route::get('/posts', [PostController::class, 'index'])->middleware('throttle:60,1');",
        );

        $this->assertSame(['middleware:throttle:60,1'], $hazards[0]->removedTokens);
        $this->assertSame(3, $hazards[0]->tier);
        $this->assertSame('CWE-770', $hazards[0]->cwe);
    }

    #[Test]
    public function a_parameterised_guard_moved_into_a_group_as_a_class_is_not_a_removal(): void
    {
        // The route wrote `auth:sanctum` and the group writes the framework class. Comparing the
        // written forms made the two different tokens, so the whole-diff guard could not match them
        // and a pure move reported a tier-3 removal.
        $hazards = $this->hazards(
            "Route::get('/posts', [PostController::class, 'index']);",
            "Route::get('/posts', [PostController::class, 'index'])->middleware('auth:sanctum');",
        );

        $this->assertSame([], HazardFindings::for([
            new ChangedFileSymbols(self::FILE, '', [], cosmeticOnly: false, hazards: $hazards),
            new ChangedFileSymbols('bootstrap/app.php', '', [], cosmeticOnly: false,
                addedHazardTokens: ['middleware:auth']),
        ]));
    }

    #[Test]
    public function a_package_guard_named_by_class_resolves_through_the_projects_alias_map(): void
    {
        // The two halves compose: the project's alias map says which class the alias is, and the
        // vocabulary says the alias is a guard. A package's middleware named by class in a route file
        // resolves through both.
        $root = sys_get_temp_dir() . '/richter-package-guard-' . getmypid();
        mkdir($root . '/app/Http', recursive: true);
        file_put_contents($root . '/app/Http/Kernel.php', "<?php\nnamespace App\\Http;\nclass Kernel\n{\n    protected \$middlewareAliases = ['role' => 'Vendor\\Permission\\RoleMiddleware'];\n}\n");

        $original = base_path();
        app()->setBasePath($root);
        GuardMiddleware::flush();

        try {
            $this->assertSame(['middleware:role'], GuardMiddleware::tokensFor(['Vendor\Permission\RoleMiddleware']));
        } finally {
            app()->setBasePath($original);
            GuardMiddleware::flush();
            unlink($root . '/app/Http/Kernel.php');
            rmdir($root . '/app/Http');
            rmdir($root . '/app');
            rmdir($root);
        }
    }

    #[Test]
    public function a_class_two_guard_aliases_both_name_is_skipped_rather_than_resolved(): void
    {
        // The same refusal the group reader makes for a name that is both a group and an alias: the
        // reader cannot say which guard the class stands for, and the wrong choice names the wrong
        // failure in the report.
        $root = sys_get_temp_dir() . '/richter-alias-' . getmypid();
        mkdir($root . '/app/Http', recursive: true);
        file_put_contents($root . '/app/Http/Kernel.php', "<?php\nnamespace App\\Http;\nclass Kernel\n{\n    protected \$middlewareAliases = ['auth' => 'App\\Http\\Middleware\\Both', 'signed' => 'App\\Http\\Middleware\\Both'];\n}\n");

        $original = base_path();
        app()->setBasePath($root);
        GuardMiddleware::flush();

        try {
            $this->assertSame([], GuardMiddleware::tokensFor(['App\Http\Middleware\Both']));
        } finally {
            app()->setBasePath($original);
            GuardMiddleware::flush();
            unlink($root . '/app/Http/Kernel.php');
            rmdir($root . '/app/Http');
            rmdir($root . '/app');
            rmdir($root);
        }
    }

    #[Test]
    public function each_guard_carries_the_cwe_that_names_its_own_failure(): void
    {
        // One mapping per guard rather than one test for all of them. A removed `throttle:` is not
        // missing authentication, and reporting it as CWE-306 is the stretched mapping the hazard
        // table's own rule warns against.
        $this->assertSame([
            'CWE-306' => 'auth:api',
            'CWE-862' => 'can:update,post',
            'CWE-345' => 'signed',
            'CWE-770' => 'throttle:60,1',
        ], $this->cwesFor(['auth:api', 'can:update,post', 'signed', 'throttle:60,1']));

        $this->assertNull(GuardMiddleware::cweFor('middleware:unmapped'));
    }

    /**
     * @param  list<string>  $middleware
     * @return array<string, string>
     */
    private function cwesFor(array $middleware): array
    {
        $cwes = [];

        foreach ($middleware as $name) {
            $hazards = $this->hazards(
                "Route::get('/posts', [PostController::class, 'index']);",
                "Route::get('/posts', [PostController::class, 'index'])->middleware('{$name}');",
            );

            $cwes[(string) $hazards[0]->cwe] = $name;
        }

        return $cwes;
    }

    // ------------------------------------------------------------ deletion

    #[Test]
    public function a_deleted_route_raises_nothing(): void
    {
        $this->assertSame([], $this->hazards(
            '',
            "Route::get('/posts/{post}/edit', [PostController::class, 'edit'])->middleware('auth');",
        ));
    }

    #[Test]
    public function a_new_route_file_reports_its_guards_as_arrivals_only(): void
    {
        [$hazards, $added] = RouteFileHazards::for(self::FILE, $this->routes(
            "Route::get('/posts', [PostController::class, 'index'])->middleware('auth');",
        ), null);

        $this->assertSame([], $hazards);
        $this->assertSame(['middleware:auth'], $added);
    }

    // --------------------------------------------------------------- shape

    #[Test]
    public function an_unparseable_side_reports_a_finding_rather_than_a_removal(): void
    {
        [$hazards, $added, $findings] = RouteFileHazards::for(self::FILE, '<?php this is not php {{{', $this->routes(
            "Route::get('/posts', [PostController::class, 'index'])->middleware('auth');",
        ));

        $this->assertSame([], $hazards);
        $this->assertSame([], $added);
        $this->assertCount(1, $findings);
        $this->assertStringContainsString('could not be parsed at head', $findings[0]);
    }

    #[Test]
    public function an_invokable_action_resolves_to_its_invoke_member(): void
    {
        $hazards = $this->hazards(
            "Route::get('/posts', PostController::class);",
            "Route::get('/posts', PostController::class)->middleware('auth');",
        );

        $this->assertSame('App\Http\Controllers\PostController::__invoke', $hazards[0]->member);
    }

    #[Test]
    public function a_resource_registration_resolves_to_its_controller_class(): void
    {
        $hazards = $this->hazards(
            "Route::resource('/posts', PostController::class);",
            "Route::resource('/posts', PostController::class)->middleware('auth');",
        );

        $this->assertSame('App\Http\Controllers\PostController', $hazards[0]->member);
    }

    #[Test]
    public function a_closure_route_falls_back_to_its_own_node_id(): void
    {
        $hazards = $this->hazards(
            "Route::get('/ping', static fn () => 'pong');",
            "Route::get('/ping', static fn () => 'pong')->middleware('auth');",
        );

        $this->assertSame('route::GET::/ping', $hazards[0]->member);
    }

    #[Test]
    public function a_legacy_string_action_is_not_resolved_to_a_class(): void
    {
        $hazards = $this->hazards(
            "Route::get('/legacy', 'PostController@index');",
            "Route::get('/legacy', 'PostController@index')->middleware('auth');",
        );

        $this->assertSame('route::GET::/legacy', $hazards[0]->member);
    }

    #[Test]
    public function a_route_registered_inside_a_condition_is_still_compared(): void
    {
        $hazards = $this->hazards(
            "if (true) {\n    Route::get('/posts', [PostController::class, 'index']);\n}",
            "if (true) {\n    Route::get('/posts', [PostController::class, 'index'])->middleware('auth');\n}",
        );

        $this->assertCount(1, $hazards);
    }

    #[Test]
    public function a_route_declared_in_a_catch_block_is_still_compared(): void
    {
        $hazards = $this->hazards(
            "try {\n    Route::get('/a', [PostController::class, 'index']);\n} catch (\\Throwable \$e) {\n    Route::get('/b', [PostController::class, 'show']);\n}",
            "try {\n    Route::get('/a', [PostController::class, 'index']);\n} catch (\\Throwable \$e) {\n    Route::get('/b', [PostController::class, 'show'])->middleware('auth');\n}",
        );

        $this->assertCount(1, $hazards);
        $this->assertSame('App\Http\Controllers\PostController::show', $hazards[0]->member);
    }

    #[Test]
    public function a_facade_that_is_not_the_router_is_ignored(): void
    {
        $this->assertSame([], $this->hazards(
            "<?php\nuse Illuminate\\Support\\Facades\\Gate;\nGate::define('x', fn () => true);",
            "<?php\nuse Illuminate\\Support\\Facades\\Gate;\nGate::define('x', fn () => true);",
        ));
    }

    // ---------------------------------------------------- whole-diff guard

    #[Test]
    public function a_guard_moved_from_a_route_into_a_controller_constructor_is_suppressed(): void
    {
        $hazards = $this->hazards(
            "Route::get('/posts/{post}/edit', [PostController::class, 'edit']);",
            "Route::get('/posts/{post}/edit', [PostController::class, 'edit'])->middleware('auth');",
        );

        $filtered = HazardFindings::for([
            new ChangedFileSymbols(self::FILE, '', [], cosmeticOnly: false, hazards: $hazards),
            new ChangedFileSymbols('app/Http/Controllers/PostController.php', '', [], cosmeticOnly: false,
                addedHazardTokens: ['middleware:auth']),
        ]);

        $this->assertSame([], $filtered);
    }
}
