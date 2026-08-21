<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis\Hazards;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\VariadicPlaceholder;
use PhpParser\NodeFinder;
use SanderMuller\Richter\Analysis\Hazard;
use SanderMuller\Richter\Support\AppFiles;

/**
 * A guard removed from a middleware GROUP — the other half of the route-file comparison.
 *
 * A route's guard can leave in two directions, and reading only one of them makes the other silent.
 * {@see RouteFileHazards} sees `->middleware('auth')` disappear from a route; this sees `'auth'`
 * disappear from the `web` group, which unguards every route in that group at once.
 *
 * **Shape-aware, deliberately.** {@see GuardMiddleware::literalTokens()} reads every string literal in
 * these files, and that is right for suppression — a missed shape only costs an arrival — but wrong
 * for detection: swapping `'auth'` for the middleware's `::class` form is a pure refactor, and a
 * literal comparison would call it an authentication removal. So only two shapes are read, and an
 * unrecognised one produces a finding rather than a guess.
 *
 * The two are the Laravel 10 Kernel's `$middlewareGroups` array and the Laravel 11+
 * `bootstrap/app.php` `withMiddleware` calls (`web(append: [...])`, `api(remove: [...])`,
 * `appendToGroup('name', [...])`). A middleware named in `remove:` is a guard the group does NOT run,
 * so adding one there removes a guard as surely as deleting the entry does.
 *
 * @internal
 */
final class MiddlewareGroupHazards
{
    /** The group-shorthand calls Laravel 11+ exposes on the middleware configurator. */
    private const array GROUP_METHODS = ['web', 'api'];

    /** Their generic counterparts, which name the group in the first argument. */
    private const array NAMED_GROUP_METHODS = ['appendToGroup', 'prependToGroup'];

    /** The same shape, subtracting instead of adding: `removeFromGroup('web', 'auth')`. */
    private const array REMOVING_GROUP_METHODS = ['removeFromGroup'];

    /**
     * @return array{0: list<Hazard>, 1: list<string>, 2: list<string>} hazards, the tokens this file
     *   added, and any finding about the file itself
     */
    public static function for(string $file, string $headSrc, ?string $baseSrc): array
    {
        // Arrivals stay shape-blind. Reporting one shape too many can only suppress a removal that a
        // guard genuinely moved to, and a missed shape silently stops suppressing — the asymmetry that
        // makes the wide reading right here and the narrow reading right below.
        $added = GuardMiddleware::gainedTokens($headSrc, $baseSrc);

        if ($baseSrc === null) {
            return [[], $added, []];
        }

        $head = self::groups($headSrc);
        $base = self::groups($baseSrc);

        // Arrivals come from the parsed groups too, not only the literals. A guard that joins a group
        // as `Authenticate::class` names no guard string, so a literal scan finds no arrival and the
        // removal it moved from stays reported as a tier-3 hazard.
        $added = [...$added, ...array_diff(self::allGuards($head), self::allGuards($base))];

        // Null is a PARSE failure, and an empty array is a file that parses and declares no group in a
        // shape this reader knows. The two must not be conflated: an edit that deletes the only
        // `web(append: ['auth'])` leaves a valid file with no groups, and reading that as "could not
        // compare" would drop the tier-3 removal it just made.
        if ($head === null || $base === null || ($head === [] && $base === [])) {
            $lost = array_diff(GuardMiddleware::literalTokens($baseSrc), GuardMiddleware::literalTokens($headSrc));

            return [[], $added, $lost === [] ? [] : ["{$file} declares middleware groups in a shape richter does not read — the groups were not compared"]];
        }

        $hazards = [];
        $lost = [];
        $unreadable = [];

        // Every guard the head accounts for — one a recognised group RUNS, and one it explicitly
        // REMOVES. A token this reader cannot account for, yet which is still written somewhere in the
        // file, did not leave: the shape holding it is one this reader does not know, and calling that
        // a removal would invent a tier-3 hazard out of a refactor. A token accounted for is compared
        // normally, so a guard that moved from `web` to `api` is still a loss for `web`, and one named
        // under `remove` is still a removal.
        $headKnown = array_merge([], ...array_values(array_map(
            static fn (array $group): array => [...$group[0], ...$group[1]],
            $head,
        )));
        $headLiterals = GuardMiddleware::literalTokens($headSrc);

        // A guard leaves a group two ways: the entry goes, or the middleware is named under `remove`,
        // which subtracts a framework default the group never listed. The second is invisible to a
        // comparison of effective sets — both sides read empty — so the removals are compared too. The
        // union of both sides' group names is walked, because a `remove` list can arrive in a group the
        // base never declared.
        foreach (array_keys($base + $head) as $group) {
            [$guards, $removed] = $base[$group] ?? [[], []];

            // A guard newly named under `remove` counts only when the base group actually RAN it.
            // `remove` subtracts from a default set richter does not model, so an app that never
            // appended `auth` and now writes `web(remove: ['auth'])` unguards nothing — reporting it
            // would be a tier-3 hazard asserted about defaults this reader cannot see.
            $gone = [
                ...array_diff($guards, $head[$group][0] ?? []),
                ...array_intersect(array_diff($head[$group][1] ?? [], $removed), $guards),
            ];

            foreach (array_values(array_unique($gone)) as $token) {
                if (! in_array($token, $headKnown, strict: true) && in_array($token, $headLiterals, strict: true)) {
                    $unreadable[] = $token;

                    continue;
                }

                $lost[] = $token;
                $hazards[] = new Hazard(
                    'auth',
                    3,
                    GuardMiddleware::cweFor($token),
                    "middleware group '{$group}'",
                    sprintf("the `%s` middleware is gone from the '%s' middleware group in %s, which guards every route in that group", substr($token, strlen('middleware:')), $group, $file),
                    [$token],
                    ignoreKey: "middleware-group:{$group}",
                );
            }
        }

        // A guard that left the `remove` list is an arrival: the group runs it again. Nothing in the
        // file's literals changed, so only this comparison can see it.
        foreach ($head as $group => [, $removed]) {
            $added = [...$added, ...array_diff($base[$group][1] ?? [], $removed)];
        }

        $findings = $unreadable === []
            ? []
            : ["{$file} declares middleware groups in a shape richter does not read — the groups were not compared"];

        // Two groups are two surfaces, not one guard moving: `web` losing `auth` while `api` gains it
        // must not suppress the loss. Same exclusion the route-file reader makes between two routes.
        return [$hazards, array_values(array_diff(array_unique($added), $lost)), $findings];
    }

    /**
     * Every guard any recognised group on one side runs.
     *
     * @param  array<string, array{0: list<string>, 1: list<string>}>|null  $groups
     * @return list<string>
     */
    private static function allGuards(?array $groups): array
    {
        return array_values(array_unique(array_merge([], ...array_values(array_map(
            static fn (array $group): array => $group[0],
            $groups ?? [],
        )))));
    }

    /**
     * Every middleware group one side declares, as guard tokens. Null ONLY when the source does not
     * parse; a file that parses and names no group in a shape this reader knows answers with an empty
     * array, which is a real comparison — deleting the last group declaration removes its guards.
     *
     * Each group answers with the guards it RUNS and the guards it explicitly REMOVES.
     *
     * @return array<string, array{0: list<string>, 1: list<string>}>|null
     */
    private static function groups(string $source): ?array
    {
        $ast = AppFiles::parseResolved($source);

        if ($ast === null) {
            return null;
        }

        $groups = self::fromKernelProperty($ast);

        foreach (new NodeFinder()->findInstanceOf(self::configuratorBodies($ast), MethodCall::class) as $call) {
            if (! $call instanceof MethodCall || ! $call->name instanceof Identifier) {
                continue;
            }

            $name = $call->name->toString();

            if (in_array($name, self::GROUP_METHODS, strict: true)) {
                self::mergeConfigurator($groups, $name, $call->args);

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

            $first = ($call->args[1] ?? null) instanceof Arg ? GuardMiddleware::tokensIn($call->args[1]->value) : [];

            // `replaceInGroup('web', $search, $replace)` swaps one for another: the searched middleware
            // leaves the group and the replacement joins it.
            $second = $name === 'replaceInGroup' && ($call->args[2] ?? null) instanceof Arg
                ? GuardMiddleware::tokensIn($call->args[2]->value)
                : [];

            $gone = $removes || $name === 'replaceInGroup' ? array_diff($first, $second) : [];
            $joined = $removes ? [] : ($name === 'replaceInGroup' ? $second : $first);

            // A guard that rejoins the group leaves the removal set with it. These calls are a
            // SEQUENCE, and reading the final state as "removed and also present" would report a
            // tier-3 removal for a group that still runs the guard.
            $groups[$group] = [
                array_values(array_diff(array_unique([...$groups[$group][0] ?? [], ...$joined]), $gone)),
                array_values(array_diff(array_unique([...$groups[$group][1] ?? [], ...$gone]), $joined)),
            ];
        }

        return $groups;
    }

    /**
     * The bodies of every `withMiddleware(...)` closure — the only place these method names mean
     * middleware configuration. Matching `web`, `api` or `appendToGroup` anywhere in the file would
     * let an unrelated call on an unrelated object invent or suppress an authentication hazard.
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
                if ($arg instanceof Arg && $arg->value instanceof Closure) {
                    $bodies = [...$bodies, ...$arg->value->stmts];
                }
            }
        }

        return $bodies;
    }

    /**
     * `$middleware->web(append: [...], remove: [...])`. A guard named under `remove` is one the group
     * does not run, so adding it there removes a guard as surely as deleting the entry would.
     *
     * @param  array<string, array{0: list<string>, 1: list<string>}>  $groups
     * @param  array<Arg|VariadicPlaceholder>  $args
     */
    private static function mergeConfigurator(array &$groups, string $group, array $args): void
    {
        [$current, $removed] = $groups[$group] ?? [[], []];

        foreach ($args as $arg) {
            if (! $arg instanceof Arg) {
                continue;
            }

            $tokens = GuardMiddleware::tokensIn($arg->value);
            $keyword = $arg->name?->toString() ?? 'append';

            // `replace: [Old::class => New::class]` is a MAP: the key leaves the group and the value
            // joins it. Reading only the values would leave the replaced guard in the running set and
            // miss the removal.
            if ($keyword === 'replace' && $arg->value instanceof Array_) {
                foreach ($arg->value->items as $item) {
                    $out = $item->key instanceof Expr ? GuardMiddleware::tokensIn($item->key) : [];
                    $in = GuardMiddleware::tokensIn($item->value);

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
     * @return array<string, array{0: list<string>, 1: list<string>}>
     */
    private static function fromKernelProperty(array $ast): array
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
                        $groups[$item->key->value] = [GuardMiddleware::tokensIn($item->value), []];
                    }
                }
            }
        }

        return $groups;
    }
}
