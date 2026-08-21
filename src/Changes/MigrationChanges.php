<?php declare(strict_types=1);

namespace SanderMuller\Richter\Changes;

use SanderMuller\Richter\Analysis\Hazards\MigrationHazards;

/**
 * `database/migrations/*.php` — the schema the application's data lives in.
 *
 * Its own branch in {@see ChangedSymbols} for the same reason a route file has one: no hazard lane
 * reaches it. A conventional migration returns an anonymous class, so the lanes' class-like gate reads
 * the file as unparseable, and the lane loop is skipped for a new file in any case.
 *
 * Unlike {@see RouteFileChanges}, a new file is the normal shape here and is analysed rather than
 * skipped. Editing a migration that already ran is the anti-pattern; the reviewable change is the
 * migration that was added.
 *
 * Seeds nothing. A migration names a table, not a class, so any seed built from it would be a guess
 * about a node id. The hazard carries its own reach instead, through the model that owns the table.
 *
 * @internal
 */
final class MigrationChanges
{
    private const string ROOT = 'database/migrations/';

    public static function handles(string $file): bool
    {
        return str_starts_with($file, self::ROOT) && str_ends_with($file, '.php');
    }

    /**
     * @param  string|null  $headSrc  null when the file could not be read at head
     * @param  string|null  $baseSrc  null for a new file, or when the base could not be read
     * @param  bool  $hasAdditions  whether the diff adds lines, which proves the file exists at head
     */
    public static function resolve(string $file, ?string $headSrc, ?string $baseSrc, bool $isNew, bool $hasAdditions): ChangedFileSymbols
    {
        // The same honesty rule every branch holds: a diff that adds lines proves the file exists at
        // head, so an unreadable head is an I/O failure. Comparing against '' would read the migration
        // as holding no operations at all — a silent all-clear on the one file this branch exists for.
        if ($headSrc === null && $hasAdditions) {
            return self::unreadable($file, 'head');
        }

        if ($baseSrc === null && ! $isNew) {
            return self::unreadable($file, 'base');
        }

        [$hazards, $added, $findings] = MigrationHazards::for($file, $headSrc ?? '', $isNew ? null : $baseSrc);

        return new ChangedFileSymbols($file, '', [], cosmeticOnly: false, findings: $findings, hazards: $hazards, addedHazardTokens: $added);
    }

    private static function unreadable(string $file, string $side): ChangedFileSymbols
    {
        return new ChangedFileSymbols($file, '', [], cosmeticOnly: false,
            findings: ["{$file} could not be read at {$side} — schema operations were not compared"]);
    }
}
