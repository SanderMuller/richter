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
     * Middleware whose absence changes who may reach an action, by the alias it is registered under.
     * A parameterised use is the same guard: `auth:sanctum` is `auth`.
     *
     * The list runs past the framework's own because the packages below ship guards, not conveniences,
     * and an application gating on `role:admin` gets no report at all while richter does not know the
     * name. A guard richter has never heard of still draws nothing.
     */
    private const array NAMES = [
        // The framework's own.
        'auth', 'verified', 'signed', 'password.confirm', 'can', 'throttle',
        // spatie/laravel-permission.
        'role', 'permission', 'role_or_permission',
        // laravel/passport and laravel/sanctum.
        'client', 'scope', 'scopes', 'ability', 'abilities',
    ];

    /**
     * Guards whose parameter names WHAT is authorised, so a different parameter is a different guard:
     * `can:update,post` and `can:view,post` check different abilities, and `role:admin` and
     * `role:editor` admit different people.
     *
     * Every other guard's parameter says how it is configured, not who gets through: `auth:sanctum`
     * and `auth:web` are both the auth guard.
     *
     * `throttle` is here because its limit is a real difference, but the set difference alone cannot
     * read it: {@see looserThrottle()} settles which direction the limit moved.
     */
    private const array SCOPED = ['can', 'role', 'permission', 'role_or_permission', 'ability', 'abilities', 'scope', 'scopes', 'throttle'];

    /**
     * Scoped guards whose parameter is a SET rather than a position, by the separator the package uses.
     * Reordering a set is not a removal: `abilities:write,read` checks what `abilities:read,write`
     * checks, and `role:editor|admin` admits who `role:admin|editor` admits.
     *
     * The two lists are separate because the packages disagree. spatie/laravel-permission separates its
     * roles with pipes and then takes an optional positional guard name after a comma, so only the
     * pipes may be sorted — `role:admin,web` and `role:web,admin` name a different role and a different
     * guard. Sanctum and Passport separate their abilities and scopes with commas and take nothing
     * after them.
     *
     * `can` is in neither. Its parameters are positional — `can:update,post` names an ability and then
     * a model — and sorting them would call two different checks the same one.
     */
    private const array PIPE_SET_SCOPED = ['role', 'permission', 'role_or_permission'];

    private const array COMMA_SET_SCOPED = ['ability', 'abilities', 'scope', 'scopes'];

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
        'role' => 'CWE-862',
        'permission' => 'CWE-862',
        'role_or_permission' => 'CWE-862',
        'client' => 'CWE-862',
        'scope' => 'CWE-862',
        'scopes' => 'CWE-862',
        'ability' => 'CWE-862',
        'abilities' => 'CWE-862',
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

        return array_values(array_unique(array_map(static fn (string $name): string => 'middleware:' . self::identity($name), $guards)));
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
        return self::CWES[self::aliasIn(substr($token, strlen('middleware:')))] ?? null;
    }

    /**
     * The guards a surface no longer applies at all. A lost throttle beside a head that still throttles
     * is not one of them: the guard survived at a different limit, which {@see looserThrottle()} reads.
     * Shared by every reader, so a rate change is never a removal on one surface and a change on another.
     *
     * @param  list<string>  $gone
     * @param  list<string>  $headTokens
     * @return list<string>
     */
    public static function removals(array $gone, array $headTokens): array
    {
        $unique = array_values(array_unique($gone));

        if (! self::throttles($headTokens)) {
            return $unique;
        }

        return array_values(array_filter($unique, static fn (string $token): bool => self::aliasOfToken($token) !== 'throttle'));
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
        return in_array(self::aliasIn($name), self::NAMES, strict: true);
    }

    /**
     * What a guard is compared AS. A scoped guard keeps its parameter, because the parameter is the
     * thing being authorised; every other guard is identified by its alias alone, so tightening a
     * throttle or switching an auth driver is not a removal.
     */
    private static function identity(string $name): string
    {
        $alias = self::aliasIn($name);

        if (! in_array($alias, self::SCOPED, strict: true)) {
            return $alias;
        }

        if (! str_contains($name, ':')) {
            return $name;
        }

        $parameter = substr($name, strlen($alias) + 1);

        if (in_array($alias, self::COMMA_SET_SCOPED, strict: true)) {
            return $alias . ':' . self::sorted($parameter, ',');
        }

        if (! in_array($alias, self::PIPE_SET_SCOPED, strict: true)) {
            return $name;
        }

        // Only the first comma segment is the set. What follows it is spatie's optional guard name,
        // which is positional and stays where it was written.
        $segments = explode(',', $parameter);
        $segments[0] = self::sorted($segments[0], '|');

        return $alias . ':' . implode(',', $segments);
    }

    /** The guard alias a token stands for: `middleware:throttle:60,1` is `throttle`. */
    public static function aliasOfToken(string $token): string
    {
        return self::aliasIn(substr($token, strlen('middleware:')));
    }

    /**
     * The two throttles to name when the head allows a strictly HIGHER rate than the base did, as
     * `[before, after]`, or null when it does not.
     *
     * Null covers three situations, and all three mean the same thing to a reader: the head throttles
     * at least as hard, the head has no throttle at all (which the ordinary removal predicate already
     * reports), or one of the sides carries a rate this reader cannot read. That last one is why BOTH
     * sides are passed whole rather than the lost token alone: a surface with `throttle:api` beside
     * `throttle:60,1` has an effective limit nobody here can name, and reading the numeric one as the
     * limit would report a weakening the named limiter may well have prevented.
     *
     * @param  list<string>  $baseTokens
     * @param  list<string>  $headTokens
     * @return array{0: string, 1: string}|null
     */
    public static function looserThrottle(array $baseTokens, array $headTokens): ?array
    {
        $before = self::strictestThrottle($baseTokens);
        $after = self::strictestThrottle($headTokens);

        if ($before === null || $after === null) {
            return null;
        }

        $wasAllowed = self::limitOf($before);
        $nowAllowed = self::limitOf($after);

        if ($wasAllowed === null || $nowAllowed === null) {
            return null;
        }

        // A fixed window has no ordering against a different one. `throttle:100,60` allows a burst of a
        // hundred in one minute and `throttle:2,1` allows two, yet the second averages the higher rate:
        // comparing the averages would call the tighter limit a weakening.
        if ($wasAllowed[1] !== $nowAllowed[1]) {
            return null;
        }

        return $nowAllowed[0] > $wasAllowed[0] ? [$before, $after] : null;
    }

    /**
     * The throttle that binds on one surface: every throttle applies, so the strictest is the limit.
     *
     * Null when the surface throttles at a limit this reader cannot read, when two of its throttles
     * count over different windows (there is no ordering between those), and when it does not throttle
     * at all — the caller separates that last one with {@see throttles()}.
     *
     * @param  list<string>  $tokens
     */
    private static function strictestThrottle(array $tokens): ?string
    {
        $strictest = null;

        foreach ($tokens as $token) {
            if (self::aliasOfToken($token) !== 'throttle') {
                continue;
            }

            $limit = self::limitOf($token);

            if ($limit === null) {
                return null;
            }

            if ($strictest === null) {
                $strictest = $token;

                continue;
            }

            $bound = self::limitOf($strictest);

            if ($bound === null || $limit[1] !== $bound[1]) {
                return null;
            }

            $strictest = $limit[0] < $bound[0] ? $token : $strictest;
        }

        return $strictest;
    }

    /**
     * Whether any head-side token throttles at all.
     *
     * @param  list<string>  $tokens
     */
    public static function throttles(array $tokens): bool
    {
        return array_any($tokens, fn (string $token) => self::aliasOfToken($token) === 'throttle');
    }

    /**
     * What a `throttle:60,1` allows, as `[requests, minutes]`, or null where the parameter is not two
     * numbers. Laravel's one-argument form (`throttle:60`) decays over one minute.
     *
     * The window is carried rather than divided away: two limits counting over different windows are
     * not comparable, and an average would rank a burst of a hundred a minute below two a minute.
     *
     * @return array{0: float, 1: float}|null
     */
    private static function limitOf(string $token): ?array
    {
        $parts = explode(',', substr($token, strlen('middleware:throttle:')));
        $max = $parts[0] ?? '';
        $minutes = $parts[1] ?? '1';

        if (! is_numeric($max) || ! is_numeric($minutes) || (float) $minutes <= 0.0) {
            return null;
        }

        return [(float) $max, (float) $minutes];
    }

    /**
     * One separated list, in a fixed order, so two spellings of the same set compare equal.
     *
     * @param  non-empty-string  $separator
     */
    private static function sorted(string $list, string $separator): string
    {
        $values = explode($separator, $list);
        sort($values);

        return implode($separator, $values);
    }

    /** The alias part of a middleware name, before any parameter. */
    private static function aliasIn(string $name): string
    {
        return explode(':', $name, 2)[0];
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
