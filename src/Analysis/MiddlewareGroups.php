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
use SanderMuller\Richter\Graph\MiddlewareAliases;
use SanderMuller\Richter\Support\AppFiles;

/**
 * The middleware groups an app declares, read from the two places it can declare them: the Laravel 10
 * Kernel's `$middlewareGroups` array and the Laravel 11+ `withMiddleware` calls in `bootstrap/app.php`
 * (`web(append: [...])`, `api(remove: [...])`, `appendToGroup('name', [...])`, and the rest).
 *
 * One reader for both lanes that ask. {@see MiddlewareGroupHazards} compares the guards a group runs;
 * {@see MiddlewareGroupFindings} sizes the routes a member runs on. Each passes its own projection of
 * one middleware argument — guard tokens for the first, the names as written for the second — and the
 * shape walk is shared, so a form one lane reads the other reads too.
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
     * Every group the project declares, as the names its entries are written with. The Kernel is read
     * first and `bootstrap/app.php` after it, so bootstrap wins for a group both declare — the order
     * {@see MiddlewareAliases::forProject()} uses, because Laravel 11+ reads
     * bootstrap even where an old Kernel is still around. A file that does not parse adds nothing.
     *
     * @return array<string, array{0: list<string>, 1: list<string>}>
     */
    public static function forProject(string $projectRoot): array
    {
        return [
            ...self::fromSource(self::sourceOf($projectRoot . '/app/Http/Kernel.php'), self::namesIn(...)) ?? [],
            ...self::fromSource(self::sourceOf($projectRoot . '/bootstrap/app.php'), self::namesIn(...)) ?? [],
        ];
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

        $groups = self::fromKernelProperty($ast, $project);

        foreach (new NodeFinder()->findInstanceOf(self::configuratorBodies($ast), MethodCall::class) as $call) {
            if (! $call instanceof MethodCall || ! $call->name instanceof Identifier) {
                continue;
            }

            $name = $call->name->toString();

            if (in_array($name, self::GROUP_METHODS, strict: true)) {
                self::mergeConfigurator($groups, $name, $call->args, $project);

                continue;
            }

            $removes = in_array($name, self::REMOVING_GROUP_METHODS, strict: true);

            if (! $removes && ! in_array($name, self::NAMED_GROUP_METHODS, strict: true) && $name !== 'replaceInGroup') {
                continue;
            }

            $group = ($call->args[0] ?? null) instanceof Arg && $call->args[0]->value instanceof String_
                ? $call->args[0]->value->value
                : null;

            if ($group === null) {
                continue;
            }

            $first = ($call->args[1] ?? null) instanceof Arg ? $project($call->args[1]->value) : [];

            // `replaceInGroup('web', $search, $replace)` swaps one for another: the searched middleware
            // leaves the group and the replacement joins it.
            $second = $name === 'replaceInGroup' && ($call->args[2] ?? null) instanceof Arg
                ? $project($call->args[2]->value)
                : [];

            $gone = $removes || $name === 'replaceInGroup' ? array_diff($first, $second) : [];
            $joined = $removes ? [] : ($name === 'replaceInGroup' ? $second : $first);

            // A middleware that rejoins the group leaves the removal set with it. These calls are a
            // SEQUENCE, and reading the final state as "removed and also present" would report a
            // removal for a group that still runs it.
            $groups[$group] = [
                array_values(array_diff(array_unique([...$groups[$group][0] ?? [], ...$joined]), $gone)),
                array_values(array_diff(array_unique([...$groups[$group][1] ?? [], ...$gone]), $joined)),
            ];
        }

        return $groups;
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

    /**
     * `$middleware->web(append: [...], remove: [...])`. A middleware named under `remove` is one the
     * group does not run, so adding it there removes it as surely as deleting the entry would.
     *
     * @param  array<string, array{0: list<string>, 1: list<string>}>  $groups
     * @param  array<Node>  $args
     * @param  Closure(?Expr): list<string>  $project
     */
    private static function mergeConfigurator(array &$groups, string $group, array $args, Closure $project): void
    {
        [$current, $removed] = $groups[$group] ?? [[], []];

        foreach ($args as $arg) {
            if (! $arg instanceof Arg) {
                continue;
            }

            $tokens = $project($arg->value);
            $keyword = $arg->name?->toString() ?? 'append';

            // `replace: [Old::class => New::class]` is a MAP: the key leaves the group and the value
            // joins it. Reading only the values would leave the replaced middleware in the running set
            // and miss the removal.
            if ($keyword === 'replace' && $arg->value instanceof Array_) {
                foreach ($arg->value->items as $item) {
                    $out = $item->key instanceof Expr ? $project($item->key) : [];
                    $in = $project($item->value);

                    $current = [...array_diff($current, $out), ...$in];
                    $removed = [...array_diff($removed, $in), ...array_diff($out, $in)];
                }

                continue;
            }

            if ($keyword === 'remove') {
                $current = array_diff($current, $tokens);
                $removed = [...$removed, ...$tokens];

                continue;
            }

            $current = [...$current, ...$tokens];
            $removed = array_diff($removed, $tokens);
        }

        $groups[$group] = [array_values(array_unique($current)), array_values(array_unique($removed))];
    }

    /**
     * The Laravel 10 Kernel's `protected $middlewareGroups = ['web' => [...]]`.
     *
     * @param  array<Node>  $ast
     * @param  Closure(?Expr): list<string>  $project
     * @return array<string, array{0: list<string>, 1: list<string>}>
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
                        $groups[$item->key->value] = [$project($item->value), []];
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
