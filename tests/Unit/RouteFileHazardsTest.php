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
        $hazards = $this->hazards(
            "Route::post('/posts', [PostController::class, 'store']);",
            "Route::post('/posts', [PostController::class, 'store'])->middleware('auth:sanctum');",
        );

        $this->assertSame(['middleware:auth:sanctum'], $hazards[0]->removedTokens);
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
