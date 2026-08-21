<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis\Hazards;

use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeFinder;
use SanderMuller\Richter\Analysis\HazardFindings;
use SanderMuller\Richter\Graph\MiddlewareAliases;
use SanderMuller\Richter\Support\AppFiles;

/**
 * Which middleware names count as a guard, and the tokens that stand for them.
 *
 * Shared by every lane that reads middleware, so a guard that MOVES between two of those surfaces —
 * a route file's `->middleware('auth')` becoming a controller constructor's, or a middleware group's
 * — produces the same token on both sides and the whole-diff moved-not-removed guard in
 * {@see HazardFindings} matches it. A per-lane vocabulary would make
 * the commonest refactor in this area read as a tier-3 removal.
 *
 * A middleware richter does not recognise draws nothing. Naming an application's own middleware a
 * guard would be a guess, and the whole family leans towards missing a real removal over inventing
 * one. An application's OWN class is recognised only where the project itself says what it is, in the
 * alias map it registers.
 *
 * @internal
 */
final class GuardMiddleware
{
    /**
     * Middleware whose absence changes who may reach an action. `can` and `throttle` appear bare as
     * well as parameterised because the class forms below carry no parameters — an alias written
     * `can:update,post` and the class it stands for are the same guard, but not the same token, so
     * only a removal of the exact form written is seen.
     */
    private const array NAMES = ['auth', 'verified', 'signed', 'password.confirm', 'can', 'throttle'];

    /**
     * Parameterised forms. `auth:sanctum` is the same guard as `auth`, `can:update,post` is an
     * ability check written as middleware, and `throttle:` bounds who may hammer an endpoint.
     */
    private const array PREFIXES = ['auth:', 'can:', 'throttle:'];

    /**
     * Framework guard middleware by class name, mapped onto the alias that stands for the same guard.
     *
     * A middleware group lists its members as `::class` far more often than as an alias, and without
     * this table swapping `'auth'` for `Authenticate::class`, a pure refactor, would read as an
     * authentication removal. An application's own subclass matches none of these names; the project's
     * own alias map answers for those ({@see projectAliases()}).
     */
    private const array CLASS_ALIASES = [
        'Illuminate\\Auth\\Middleware\\Authenticate' => 'auth',
        'Illuminate\\Auth\\Middleware\\AuthenticateWithBasicAuth' => 'auth',
        'Illuminate\\Auth\\Middleware\\EnsureEmailIsVerified' => 'verified',
        'Illuminate\\Auth\\Middleware\\RequirePassword' => 'password.confirm',
        'Illuminate\\Routing\\Middleware\\ValidateSignature' => 'signed',
        'Illuminate\\Auth\\Middleware\\Authorize' => 'can',
        'Illuminate\\Routing\\Middleware\\ThrottleRequests' => 'throttle',
    ];

    /**
     * CWE by guard, keyed on the alias the token carries. One mapping per guard rather than one test
     * for all of them: a removed `throttle:` is not missing authentication, and reporting it as
     * CWE-306 is the stretched mapping the hazard table's own rule warns about.
     */
    private const array CWES = [
        'auth' => 'CWE-306',
        'password.confirm' => 'CWE-306',
        'can' => 'CWE-862',
        'verified' => 'CWE-862',
        'signed' => 'CWE-345',
        'throttle' => 'CWE-770',
    ];

    /** @var array<string, array<string, string>> project root => FQCN => the guard alias it is registered as */
    private static array $projectAliases = [];

    /**
     * The tokens for a list of middleware names as written, filtered to the guards. A name may be a
     * middleware alias or a fully qualified class; both resolve to the same token.
     *
     * @param  list<string>  $names
     * @return list<string>
     */
    public static function tokensFor(array $names): array
    {
        $guards = array_filter(array_map(self::aliasOf(...), $names), self::isGuard(...));

        return array_values(array_unique(array_map(static fn (string $name): string => 'middleware:' . $name, $guards)));
    }

    /**
     * The CWE for one guard token, or null where no clean mapping exists.
     *
     * `verified` and `signed` are mapped rather than left null because each names a distinct failure:
     * dropping `verified` lets an unverified account reach an action it was not authorised for, and
     * dropping `signed` stops the request's authenticity being checked at all.
     */
    public static function cweFor(string $token): ?string
    {
        $alias = explode(':', substr($token, strlen('middleware:')), 2)[0];

        return self::CWES[$alias] ?? null;
    }

    /** Reset the per-project alias cache, so a second run in one process re-reads the map. */
    public static function flush(): void
    {
        self::$projectAliases = [];
    }

    /**
     * A middleware name as the alias that stands for it: a framework class through {@see CLASS_ALIASES},
     * an application class through the project's own alias map, and anything else unchanged.
     */
    private static function aliasOf(string $name): string
    {
        $fqcn = ltrim($name, '\\');

        return self::CLASS_ALIASES[$fqcn] ?? self::projectAliases()[$fqcn] ?? $name;
    }

    /**
     * The application's own guard classes, from the alias map it registers — `$middlewareAliases` on a
     * legacy Kernel, or `$middleware->alias([...])` in `bootstrap/app.php`.
     *
     * This is DECLARED intent, not inference: a project that writes `'auth' => Authenticate::class` has
     * said which class is its `auth` guard, so `Route::middleware(Authenticate::class)` and
     * `Route::middleware('auth')` are the same guard and a removal of either is the same removal.
     * Following an `extends` clause instead would be weaker evidence for the same answer.
     *
     * A class two different guard aliases both point at is skipped rather than resolved one way, the
     * same refusal the group reader makes for a name that is both a group and an alias.
     *
     * The map is read from the working tree, so it answers for both sides of the diff. A diff that
     * rewrites the alias map itself is read against its head form; that costs a comparison, never a
     * wrong one, because an unmapped class still draws nothing.
     *
     * @return array<string, string>
     */
    private static function projectAliases(): array
    {
        $root = base_path();

        if (isset(self::$projectAliases[$root])) {
            return self::$projectAliases[$root];
        }

        $byClass = [];

        foreach (MiddlewareAliases::forProject($root) as $alias => $fqcn) {
            if (self::isGuard($alias)) {
                $byClass[ltrim($fqcn, '\\')][] = $alias;
            }
        }

        $map = [];

        foreach ($byClass as $fqcn => $aliases) {
            $unique = array_values(array_unique($aliases));

            if (count($unique) === 1) {
                $map[$fqcn] = $unique[0];
            }
        }

        return self::$projectAliases[$root] = $map;
    }

    /**
     * The guard tokens named by one middleware argument: a string literal, a `::class` constant, or an
     * array of either. Shared by every reader, so a route and a middleware group spell the same guard
     * the same way and a move between them matches.
     *
     * Anything else — a variable, a concatenation, a call — contributes nothing, which is the refusal
     * the whole family makes rather than guessing at a name.
     *
     * @return list<string>
     */
    public static function tokensIn(?Expr $expr): array
    {
        $items = $expr instanceof Array_ ? array_map(static fn (ArrayItem $item): Expr => $item->value, $expr->items) : [$expr];
        $names = [];

        foreach ($items as $item) {
            if ($item instanceof String_) {
                $names[] = $item->value;

                continue;
            }

            if ($item instanceof ClassConstFetch && $item->class instanceof Name) {
                $names[] = AppFiles::resolveName($item->class);
            }
        }

        return self::tokensFor($names);
    }

    public static function isGuard(string $name): bool
    {
        return in_array($name, self::NAMES, strict: true)
            || array_any(self::PREFIXES, static fn (string $prefix): bool => str_starts_with($name, $prefix));
    }

    /**
     * Every guard token named by a plain string literal anywhere in one file.
     *
     * Deliberately shape-blind, and used ONLY to report what a file GAINED — never to raise a hazard.
     * A middleware group is written several ways (a Laravel 10 Kernel's `$middlewareGroups` array, a
     * Laravel 11+ `bootstrap/app.php` `->web(append: [...])` call, `prependToGroup`), and a lane that
     * had to recognise each shape would silently stop suppressing on the one it did not know. Reading
     * every literal cannot miss a shape; the cost is that an unrelated `'auth'` string in the same
     * file also suppresses, which fails towards under-reporting.
     *
     * @return list<string>
     */
    public static function literalTokens(string $source): array
    {
        $ast = AppFiles::parseResolved($source);

        if ($ast === null) {
            return [];
        }

        $names = [];

        foreach (new NodeFinder()->findInstanceOf($ast, String_::class) as $string) {
            $names[] = $string->value;
        }

        return self::tokensFor($names);
    }

    /**
     * What one file's head side gained over its base side — the suppression contribution of a file
     * this family reads for arrivals only.
     *
     * @return list<string>
     */
    public static function gainedTokens(string $headSrc, ?string $baseSrc): array
    {
        if ($baseSrc === null) {
            return self::literalTokens($headSrc);
        }

        return array_values(array_diff(self::literalTokens($headSrc), self::literalTokens($baseSrc)));
    }
}
