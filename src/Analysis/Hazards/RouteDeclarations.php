<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis\Hazards;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Block;
use PhpParser\Node\Stmt\Catch_;
use PhpParser\Node\Stmt\Do_;
use PhpParser\Node\Stmt\ElseIf_;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\TryCatch;
use PhpParser\Node\Stmt\While_;
use SanderMuller\Richter\Support\AppFiles;

/**
 * Every route a `routes/*.php` file declares, with the guards that actually apply to it.
 *
 * Split from {@see RouteFileHazards} because reading these files and comparing two readings of them
 * are different jobs, and the parser is the larger of the two.
 *
 * **Effective guards, not written ones.** A route's guard set is the middleware written on the route,
 * plus every enclosing `Route::middleware(...)->group()` and `Route::group(['middleware' => ...], ...)`
 * wrapper, minus its own `->withoutMiddleware()`. The chain is read in CALL order, because
 * `->middleware('auth')->withoutMiddleware('auth')` unwinds outermost-first and would otherwise
 * subtract the guard before adding it.
 *
 * @internal
 *
 * @phpstan-type RouteRecord array{verb: string, uri: string, member: string, guards: list<string>}
 */
final class RouteDeclarations
{
    /** Route-registration methods whose first string argument is the URI and second the action. */
    private const array ACTION_VERBS = ['get', 'post', 'put', 'patch', 'delete', 'options', 'any'];

    /** The HTTP verbs that have a `route::VERB::/uri` node of their own. `any` and `match` do not. */
    private const array NODE_VERBS = ['get', 'post', 'put', 'patch', 'delete', 'options'];

    /** Controller-shaped registrations: URI first, controller class second, many routes out. */
    private const array CONTROLLER_VERBS = ['resource', 'apiResource', 'singleton', 'apiSingleton'];

    /** Registrations that declare a URI and no action of their own. */
    private const array ACTIONLESS_VERBS = ['view', 'redirect', 'permanentRedirect'];

    /**
     * Every route one side declares, keyed so the two sides line up. The key is the registration as
     * written — its method and its URI literal — because that is what survives the edits this lane
     * has to see through: moving a route into a group, reordering the file, renaming the action.
     *
     * Null when the source does not parse. A half-read side would report the routes it failed to find
     * as deleted, which is the one answer this lane must never give.
     *
     * @return array<string, RouteRecord>|null
     */
    public static function of(string $source): ?array
    {
        $ast = AppFiles::parseResolved($source);

        if ($ast === null) {
            return null;
        }

        $routes = [];
        self::collect($ast, [], $routes);

        return $routes;
    }

    /**
     * Walks statements, not nodes: a chain's root static call is reached by unwinding the expression
     * it sits at the bottom of, and a group's closure body is walked separately with the group's own
     * guards inherited. A node-level sweep would find the same call twice — once bare and once with
     * its wrappers — and the bare reading would report every grouped route as unguarded.
     *
     * @param  array<Node>  $nodes
     * @param  list<string>  $inherited
     * @param  array<string, RouteRecord>  $routes
     */
    private static function collect(array $nodes, array $inherited, array &$routes): void
    {
        foreach ($nodes as $node) {
            if ($node instanceof Expression) {
                self::record($node->expr, $inherited, $routes);

                continue;
            }

            if ($node instanceof Expr) {
                self::record($node, $inherited, $routes);

                continue;
            }

            // A route registered inside an `if`, a `foreach` or a `try` is still a route. Descending
            // through the statement body reaches it; nothing here re-enters a closure, which only the
            // group branch is allowed to do.
            self::collect(self::bodyOf($node), $inherited, $routes);
        }
    }

    /**
     * One chain, unwound from its outermost call to its root: `Route::middleware('auth')
     * ->prefix('admin')->group(fn () => ...)`, or `Route::get('/x', ...)->middleware('auth')->name(...)`.
     *
     * @param  list<string>  $inherited
     * @param  array<string, RouteRecord>  $routes
     */
    private static function record(Expr $expr, array $inherited, array &$routes): void
    {
        $links = [];

        while ($expr instanceof MethodCall) {
            $links[] = $expr;
            $expr = $expr->var;
        }

        // Reversed into CALL order. PhpParser nests a chain through `->var`, so unwinding it yields
        // the outermost call first — and `->middleware('auth')->withoutMiddleware('auth')` would then
        // subtract the guard before adding it and grade the route guarded.
        $links = array_reverse($links);

        if (! $expr instanceof StaticCall || ! self::isRouteFacade($expr)) {
            return;
        }

        // The whole chain in call order, root first. The REGISTRATION can sit anywhere in it:
        // `Route::get('/x', …)->middleware('auth')` puts it at the root, and the equally common
        // `Route::middleware('auth')->get('/x', …)` puts it at the end. Reading only the root would
        // record no route for the second shape, so removing its guard would report nothing.
        $calls = [$expr, ...$links];
        $guards = $inherited;
        $group = null;
        $registration = null;

        foreach ($calls as $call) {
            $name = $call->name instanceof Identifier ? $call->name->toString() : '';

            if ($name === 'withoutMiddleware') {
                $guards = array_values(array_diff($guards, self::middlewareTokens($call->args)));

                continue;
            }

            if (self::isRegistration($name)) {
                $registration ??= $call;

                continue;
            }

            if ($name === 'group') {
                $group = $call;
            }

            $guards = [...$guards, ...self::guardsOf($call)];
        }

        $guards = array_values(array_unique($guards));

        if ($group !== null) {
            self::recordGroup($group->args, $guards, $routes);

            return;
        }

        if ($registration === null || ! $registration->name instanceof Identifier) {
            return;
        }

        $verb = $registration->name->toString();
        $uri = self::uriOf($verb, $registration->args);

        if ($uri === null) {
            return;
        }

        $key = "{$verb} {$uri}";

        // Two registrations can share a verb and a URI as written — the same path under two different
        // `prefix()` or `domain()` groups. Their guards are UNIONED rather than the later one
        // overwriting the earlier, so a token only counts as lost when it is gone from every
        // registration under the key. The cost is a guard dropped from one of two such routes while
        // the other keeps it, which is missed; the alternative, keying on the group context, would
        // lose the far commoner case of a route lifted out of a prefixed group, whose key would then
        // change and read as a deletion.
        $routes[$key] = [
            'verb' => $verb,
            'uri' => $uri,
            'member' => $routes[$key]['member'] ?? self::memberOf($verb, $uri, $registration->args),
            'guards' => array_values(array_unique([...$routes[$key]['guards'] ?? [], ...$guards])),
        ];
    }

    /**
     * The statements nested inside a control structure. A route registered under an `if` or in a
     * `foreach` is still a route, and reading only the top level would report it as deleted the
     * moment the surrounding condition moved.
     *
     * @return array<Node>
     */
    private static function bodyOf(Node $node): array
    {
        return match (true) {
            $node instanceof If_ => [
                ...$node->stmts,
                ...array_merge([], ...array_map(static fn (ElseIf_ $elseif): array => $elseif->stmts, $node->elseifs)),
                ...($node->else->stmts ?? []),
            ],
            $node instanceof TryCatch => [
                ...$node->stmts,
                ...array_merge([], ...array_map(static fn (Catch_ $catch): array => $catch->stmts, $node->catches)),
                ...($node->finally->stmts ?? []),
            ],
            $node instanceof Foreach_,
            $node instanceof While_,
            $node instanceof Do_,
            $node instanceof For_,
            $node instanceof Block => $node->stmts,
            default => [],
        };
    }

    /** Whether this facade method registers a route, as opposed to configuring one. */
    private static function isRegistration(string $verb): bool
    {
        return in_array($verb, self::ACTION_VERBS, strict: true)
            || in_array($verb, self::CONTROLLER_VERBS, strict: true)
            || in_array($verb, self::ACTIONLESS_VERBS, strict: true)
            || $verb === 'match'
            || $verb === 'fallback';
    }

    private static function isRouteFacade(StaticCall $call): bool
    {
        return $call->class instanceof Name && str_ends_with(AppFiles::resolveName($call->class), '\\Route');
    }

    /**
     * The guard tokens one call in a chain contributes: `->middleware(...)`, and the `middleware` key
     * of a `Route::group([...], ...)` array.
     *
     * @return list<string>
     */
    private static function guardsOf(StaticCall|MethodCall $call): array
    {
        $name = $call->name instanceof Identifier ? $call->name->toString() : '';

        if ($name === 'middleware') {
            return self::middlewareTokens($call->args);
        }

        if ($name !== 'group') {
            return [];
        }

        $first = $call->args[0] ?? null;

        if (! $first instanceof Arg || ! $first->value instanceof Array_) {
            return [];
        }

        foreach ($first->value->items as $item) {
            if ($item->key instanceof String_ && $item->key->value === 'middleware') {
                return GuardMiddleware::tokensIn($item->value);
            }
        }

        return [];
    }

    /**
     * @param  array<Arg|Node\VariadicPlaceholder>  $args
     * @return list<string>
     */
    private static function middlewareTokens(array $args): array
    {
        $first = $args[0] ?? null;

        return $first instanceof Arg ? GuardMiddleware::tokensIn($first->value) : [];
    }

    /**
     * @param  array<Arg|Node\VariadicPlaceholder>  $args
     */
    private static function uriOf(string $verb, array $args): ?string
    {
        // `Route::match(['get', 'post'], '/x', $action)` puts the verbs first; every other shape this
        // lane reads starts with the URI. A `fallback` route declares no URI at all.
        $index = $verb === 'match' ? 1 : 0;
        $arg = $args[$index] ?? null;

        if ($verb === 'fallback') {
            return '/{fallback}';
        }

        return $arg instanceof Arg && $arg->value instanceof String_ ? $arg->value->value : null;
    }

    /**
     * What the reach lane resolves this hazard through.
     *
     * The action when the file names one, because a controller action is a node the graph knows and
     * the entry points reaching it are exactly this route and its siblings. Otherwise the route's own
     * node id, which the reach lane matches against the entry-point set directly — it lands whenever
     * the declared URI is the registered one, and grades `no-known-path` when a group prefix made it
     * something else. Never a guess dressed as a class name.
     *
     * @param  array<Arg|Node\VariadicPlaceholder>  $args
     */
    private static function memberOf(string $verb, string $uri, array $args): string
    {
        $index = match (true) {
            $verb === 'match' => 2,
            $verb === 'fallback' => 0,
            in_array($verb, self::ACTIONLESS_VERBS, strict: true) => null,
            default => 1,
        };

        $action = $index === null ? null : ($args[$index] ?? null);

        if ($action instanceof Arg) {
            $member = self::actionMember($action->value, in_array($verb, self::CONTROLLER_VERBS, strict: true));

            if ($member !== null) {
                return $member;
            }
        }

        return in_array($verb, self::NODE_VERBS, strict: true)
            ? 'route::' . strtoupper($verb) . '::' . $uri
            : "route {$verb} {$uri}";
    }

    /**
     * `[PostController::class, 'store']`, an invokable `PostController::class`, and a resource
     * controller. A legacy `'Foo@bar'` string is deliberately not read: it is namespace-relative to a
     * root a service provider supplies, so resolving it here would name a class that may not exist.
     */
    private static function actionMember(Expr $action, bool $isController): ?string
    {
        if ($action instanceof Array_) {
            $items = $action->items;
            $class = ($items[0] ?? null)?->value;
            $method = ($items[1] ?? null)?->value;

            return $class instanceof ClassConstFetch && $method instanceof String_
                ? self::className($class) . '::' . $method->value
                : null;
        }

        if (! $action instanceof ClassConstFetch) {
            return null;
        }

        $class = self::className($action);

        // A resource registration is many routes on one class, so the class itself is the member the
        // reach lane can answer for. A single invokable route is its `__invoke`.
        return $class === null ? null : ($isController ? $class : $class . '::__invoke');
    }

    private static function className(ClassConstFetch $fetch): ?string
    {
        return $fetch->class instanceof Name ? AppFiles::resolveName($fetch->class) : null;
    }

    /**
     * A group's contents. A closure body is walked with the group's guards inherited; a group that
     * loads ANOTHER route file (`->group(base_path('routes/admin.php'))`) has no body to walk, so the
     * group itself is recorded as one comparable unit keyed by what it loads. Removing `auth` from
     * such a declaration unguards every route in the included file, and dropping the group silently
     * would say nothing about it.
     *
     * @param  array<Arg|Node\VariadicPlaceholder>  $args
     * @param  list<string>  $guards
     * @param  array<string, RouteRecord>  $routes
     */
    private static function recordGroup(array $args, array $guards, array &$routes): void
    {
        $body = self::closureBodyOf($args);

        if ($body !== []) {
            self::collect($body, $guards, $routes);

            return;
        }

        $loaded = self::loadedPathOf($args);

        if ($loaded === null) {
            return;
        }

        $routes["group {$loaded}"] = [
            'verb' => 'group',
            'uri' => $loaded,
            'member' => "route group {$loaded}",
            'guards' => array_values(array_unique([...$routes["group {$loaded}"]['guards'] ?? [], ...$guards])),
        ];
    }

    /**
     * What a file-backed group loads, as written. A literal path is used as it is; anything else is
     * printed, which is stable enough to line the two sides up and names nothing the reader has to
     * trust.
     *
     * @param  array<Arg|Node\VariadicPlaceholder>  $args
     */
    private static function loadedPathOf(array $args): ?string
    {
        foreach ($args as $arg) {
            if (! $arg instanceof Arg || $arg->value instanceof Closure || $arg->value instanceof ArrowFunction) {
                continue;
            }

            return $arg->value instanceof String_ ? $arg->value->value : HazardSource::print($arg->value);
        }

        return null;
    }

    /**
     * @param  array<Arg|Node\VariadicPlaceholder>  $args
     * @return array<Node>
     */
    private static function closureBodyOf(array $args): array
    {
        foreach ($args as $arg) {
            if (! $arg instanceof Arg) {
                continue;
            }

            if ($arg->value instanceof Closure) {
                return $arg->value->stmts;
            }

            if ($arg->value instanceof ArrowFunction) {
                return [$arg->value->expr];
            }
        }

        return [];
    }
}
