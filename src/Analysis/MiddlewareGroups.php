<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

use Closure;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Closure as ClosureExpr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Property;
use PhpParser\NodeFinder;
use SanderMuller\Richter\Analysis\Hazards\MiddlewareGroupHazards;
use SanderMuller\Richter\Support\AppFiles;

/**
 * The middleware groups an app declares, read from the two places it can declare them: the Laravel 10
 * Kernel's `$middlewareGroups` array and the Laravel 11+ `withMiddleware` calls in `bootstrap/app.php`
 * (`web(append: [...])`, `api(remove: [...])`, `group('name', [...])`, `appendToGroup('name', [...])`,
 * and the rest).
 *
 * One reader for both lanes that ask. {@see MiddlewareGroupHazards} compares the guards a group runs;
 * {@see MiddlewareGroupFindings} sizes the routes a member runs on. Each passes its own projection of
 * one middleware argument — guard tokens for the first, the names as written for the second — and the
 * shape walk is shared, so a form one lane reads the other reads too.
 *
 * @phpstan-type GroupCalls array{declared: list<string>|null, replacements: list<array{0: list<string>, 1: list<string>}>, removals: list<string>, additions: list<string>}
 *
 * @internal
 */
final class MiddlewareGroups
{
    /** The group-shorthand calls Laravel 11+ exposes on the middleware configurator. */
    private const array GROUP_METHODS = ['web', 'api'];

    /** Their generic counterparts, which name the group in the first argument. */
    private const array NAMED_GROUP_METHODS = ['appendToGroup', 'prependToGroup'];

    /** The same shape, subtracting instead of adding: `removeFromGroup('web', 'auth')`. */
    private const array REMOVING_GROUP_METHODS = ['removeFromGroup'];

    /**
     * Every group the project declares, as the names its entries are written with. A `bootstrap/app.php`
     * that calls `withMiddleware` is the only source: Laravel 11+ then ignores the app's own Kernel, so
     * an old Kernel left beside it declares groups that no route runs. Without that call the Kernel is
     * the source. A file that does not parse adds nothing.
     *
     * @return array<string, array{0: list<string>, 1: list<string>}>
     */
    public static function forProject(string $projectRoot): array
    {
        $bootstrap = self::sourceOf($projectRoot . '/bootstrap/app.php');
        $ast = AppFiles::parseResolved($bootstrap);
        $source = $ast !== null && self::configuresMiddleware($ast) ? $bootstrap : self::sourceOf($projectRoot . '/app/Http/Kernel.php');

        return self::fromSource($source, self::namesIn(...)) ?? [];
    }

    /**
     * Every middleware group one source declares, each as the middleware it RUNS and the middleware it
     * explicitly REMOVES, in the projection `$project` gives one middleware argument.
     *
     * Null ONLY when the source does not parse. A file that parses and names no group in a shape this
     * reader knows answers with an empty array, which is a real comparison — deleting the last group
     * declaration removes its middleware.
     *
     * @param  Closure(?Expr): list<string>  $project
     * @return array<string, array{0: list<string>, 1: list<string>}>|null
     */
    public static function fromSource(string $source, Closure $project): ?array
    {
        $ast = AppFiles::parseResolved($source);

        if ($ast === null) {
            return null;
        }

        $calls = self::fromKernelProperty($ast, $project);

        foreach (new NodeFinder()->findInstanceOf(self::configuratorBodies($ast), MethodCall::class) as $call) {
            if (! $call instanceof MethodCall || ! $call->name instanceof Identifier) {
                continue;
            }

            $name = $call->name->toString();

            if (in_array($name, self::GROUP_METHODS, strict: true)) {
                self::recordConfigurator($calls, $name, $call->args, $project);

                continue;
            }

            self::recordNamedGroupCall($calls, $name, $call->args, $project);
        }

        return array_map(self::resolve(...), $calls);
    }

    /**
     * One group's recorded calls, applied in the order Laravel's `getMiddlewareGroups()` applies them
     * and not in the order the file writes them: the declared list, then each replacement, then the
     * removals, then the prepends and appends. So a middleware appended before `group()` still runs,
     * and so does one that is both removed and appended.
     *
     * The removed set is every middleware a removal or replacement names that the group does not run
     * in the end.
     *
     * @param  GroupCalls  $calls
     * @return array{0: list<string>, 1: list<string>}
     */
    private static function resolve(array $calls): array
    {
        $runs = $calls['declared'] ?? [];
        $replaced = [];

        foreach ($calls['replacements'] as [$out, $in]) {
            $runs = [...array_diff($runs, $out), ...$in];
            $replaced = [...$replaced, ...$out];
        }

        $runs = array_values(array_unique([...array_diff($runs, $calls['removals']), ...$calls['additions']]));

        return [$runs, array_values(array_diff(array_unique([...$calls['removals'], ...$replaced]), $runs))];
    }

    /**
     * The middleware one argument names, as the app wrote them: a string literal as it stands, a
     * `::class` constant as its FQCN, and a builder such as `ThrottleRequests::with(30, 1)` as the class
     * it builds. An array names each of its items. Anything else names nothing, the refusal
     * {@see Hazards\GuardMiddleware::tokensIn()} makes too.
     *
     * @return list<string>
     */
    public static function namesIn(?Expr $expr): array
    {
        $items = $expr instanceof Array_ ? array_map(static fn (ArrayItem $item): Expr => $item->value, $expr->items) : [$expr];
        $names = [];

        foreach ($items as $item) {
            if ($item instanceof String_) {
                $names[] = $item->value;
            } elseif ($item instanceof ClassConstFetch && $item->class instanceof Name) {
                $names[] = AppFiles::resolveName($item->class);
            } elseif ($item instanceof StaticCall && $item->class instanceof Name) {
                $names[] = AppFiles::resolveName($item->class);
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * The bodies of every `withMiddleware(...)` closure — the only place these method names mean
     * middleware configuration. Matching `web`, `api` or `appendToGroup` anywhere in the file would
     * let an unrelated call on an unrelated object invent or hide a group member.
     *
     * @param  array<Node>  $ast
     * @return array<Node>
     */
    private static function configuratorBodies(array $ast): array
    {
        $bodies = [];

        foreach (new NodeFinder()->findInstanceOf($ast, MethodCall::class) as $call) {
            if (! $call instanceof MethodCall || ! $call->name instanceof Identifier || $call->name->toString() !== 'withMiddleware') {
                continue;
            }

            foreach ($call->args as $arg) {
                if ($arg instanceof Arg && $arg->value instanceof ClosureExpr) {
                    $bodies = [...$bodies, ...$arg->value->stmts];
                }
            }
        }

        return $bodies;
    }

    /** @param  array<Node>  $ast */
    private static function configuresMiddleware(array $ast): bool
    {
        return array_any(
            new NodeFinder()->findInstanceOf($ast, MethodCall::class),
            static fn (Node $call): bool => $call instanceof MethodCall && $call->name instanceof Identifier && $call->name->toString() === 'withMiddleware',
        );
    }

    /**
     * The calls that name their group in the first argument: `group`, which sets the declared list,
     * and `appendToGroup`, `prependToGroup`, `removeFromGroup` and `replaceInGroup`, which modify it.
     * Any other call is not a group call.
     *
     * @param  array<string, GroupCalls>  $calls
     * @param  array<Node>  $args
     * @param  Closure(?Expr): list<string>  $project
     */
    private static function recordNamedGroupCall(array &$calls, string $name, array $args, Closure $project): void
    {
        if (! in_array($name, [...self::NAMED_GROUP_METHODS, ...self::REMOVING_GROUP_METHODS, 'replaceInGroup', 'group'], strict: true)) {
            return;
        }

        $group = ($args[0] ?? null) instanceof Arg && $args[0]->value instanceof String_
            ? $args[0]->value->value
            : null;

        if ($group === null) {
            return;
        }

        $first = ($args[1] ?? null) instanceof Arg ? $project($args[1]->value) : [];
        $calls[$group] ??= self::noCalls();

        match ($name) {
            'group' => $calls[$group]['declared'] = $first,
            'removeFromGroup' => $calls[$group]['removals'] = [...$calls[$group]['removals'], ...$first],
            'replaceInGroup' => $calls[$group]['replacements'][] = [
                $first,
                ($args[2] ?? null) instanceof Arg ? $project($args[2]->value) : [],
            ],
            default => $calls[$group]['additions'] = [...$calls[$group]['additions'], ...$first],
        };
    }

    /**
     * `$middleware->web(append: [...], remove: [...])`. A middleware named under `remove` is one the
     * group does not run, so adding it there removes it as surely as deleting the entry would.
     *
     * @param  array<string, GroupCalls>  $calls
     * @param  array<Node>  $args
     * @param  Closure(?Expr): list<string>  $project
     */
    private static function recordConfigurator(array &$calls, string $group, array $args, Closure $project): void
    {
        $calls[$group] ??= self::noCalls();

        foreach ($args as $arg) {
            if (! $arg instanceof Arg) {
                continue;
            }

            $keyword = $arg->name?->toString() ?? 'append';

            // `replace: [Old::class => New::class]` is a MAP: the key leaves the group and the value
            // joins it. Reading only the values would leave the replaced middleware in the running set
            // and miss the removal.
            if ($keyword === 'replace' && $arg->value instanceof Array_) {
                foreach ($arg->value->items as $item) {
                    $calls[$group]['replacements'][] = [$item->key instanceof Expr ? $project($item->key) : [], $project($item->value)];
                }

                continue;
            }

            if ($keyword === 'remove') {
                $calls[$group]['removals'] = [...$calls[$group]['removals'], ...$project($arg->value)];

                continue;
            }

            $calls[$group]['additions'] = [...$calls[$group]['additions'], ...$project($arg->value)];
        }
    }

    /** @return GroupCalls */
    private static function noCalls(): array
    {
        return ['declared' => null, 'replacements' => [], 'removals' => [], 'additions' => []];
    }

    /**
     * The Laravel 10 Kernel's `protected $middlewareGroups = ['web' => [...]]`.
     *
     * @param  array<Node>  $ast
     * @param  Closure(?Expr): list<string>  $project
     * @return array<string, GroupCalls>
     */
    private static function fromKernelProperty(array $ast, Closure $project): array
    {
        $groups = [];

        foreach (new NodeFinder()->findInstanceOf($ast, Property::class) as $property) {
            if (! $property instanceof Property) {
                continue;
            }

            foreach ($property->props as $prop) {
                if ($prop->name->toString() !== 'middlewareGroups' || ! $prop->default instanceof Array_) {
                    continue;
                }

                foreach ($prop->default->items as $item) {
                    if ($item->key instanceof String_) {
                        $groups[$item->key->value] = ['declared' => $project($item->value)] + self::noCalls();
                    }
                }
            }
        }

        return $groups;
    }

    private static function sourceOf(string $path): string
    {
        return is_file($path) ? (string) file_get_contents($path) : '';
    }
}
