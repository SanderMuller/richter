<?php declare(strict_types=1);

namespace SanderMuller\Richter\Changes;

use SanderMuller\Richter\Analysis\Hazards\MiddlewareGroupHazards;
use SanderMuller\Richter\Analysis\Hazards\RouteFileHazards;

/**
 * The two files outside `app/` that decide who may reach a route: `routes/*.php`, where a route's own
 * middleware is written, and `bootstrap/app.php`, where a middleware group is composed.
 *
 * Their own branch in {@see ChangedSymbols} because neither declares a class, so no hazard lane
 * reaches either — the lanes run behind a class-like gate, and a route file has nothing for it to
 * match.
 *
 * A route's guard can leave in two directions, and reading only one makes the other silent:
 * {@see RouteFileHazards} sees it disappear from a route, {@see MiddlewareGroupHazards} sees it
 * disappear from the group the route runs in.
 *
 * Neither file seeds anything. Which routes a file registers is Brain's answer, not this parser's: a
 * seed built from a URI written here would be a guess about a node id, and a wrong guess reads as a
 * real entry point. A hazard carries its own reach instead.
 *
 * @internal
 */
final class RouteFileChanges
{
    /** Where a middleware group is composed in Laravel 11+. */
    private const string BOOTSTRAP = 'bootstrap/app.php';

    public static function handles(string $file): bool
    {
        return $file === self::BOOTSTRAP || (str_starts_with($file, 'routes/') && str_ends_with($file, '.php'));
    }

    /**
     * @param  string|null  $headSrc  null when the file could not be read at head
     * @param  string|null  $baseSrc  null for a new file, or when the base could not be read
     * @param  bool  $hasAdditions  whether the diff adds lines, which proves the file exists at head
     */
    public static function resolve(string $file, ?string $headSrc, ?string $baseSrc, bool $isNew, bool $hasAdditions): ChangedFileSymbols
    {
        // Same honesty rule as every other branch: an unreadable head on a diff that adds lines is an
        // I/O failure, not a deletion. Comparing against '' would read every route in the file as
        // deleted, which raises nothing — a silent all-clear on the one file this branch exists for.
        if ($headSrc === null && $hasAdditions) {
            return self::unreadable($file, 'head');
        }

        if ($baseSrc === null && ! $isNew) {
            return self::unreadable($file, 'base');
        }

        [$hazards, $added, $findings] = $file === self::BOOTSTRAP
            ? MiddlewareGroupHazards::for($file, $headSrc ?? '', $baseSrc)
            : RouteFileHazards::for($file, $headSrc ?? '', $baseSrc);

        return new ChangedFileSymbols($file, '', [], cosmeticOnly: false, findings: $findings, hazards: $hazards, addedHazardTokens: $added);
    }

    private static function unreadable(string $file, string $side): ChangedFileSymbols
    {
        return new ChangedFileSymbols($file, '', [], cosmeticOnly: false,
            findings: ["{$file} could not be read at {$side} — route middleware was not compared"]);
    }
}
