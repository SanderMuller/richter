<?php declare(strict_types=1);

namespace SanderMuller\Richter\Changes;

use Closure;
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

    /**
     * The untracked migrations, read from the working tree. Every other watched root holds files that
     * are normally EDITED, so a diff sees them; a migration is normally a brand-new file, which left
     * `git diff` blind to this lane's whole subject at exactly the moment it is newest — the
     * pre-commit check on the migration just written. Each is analysed as what it is, a new file:
     * everything its `up()` does, against no base.
     *
     * Lives here rather than in {@see ChangedSymbols} for the reason {@see NonPhpFileChange} does —
     * that class sits at its cognitive-complexity ceiling, and reading a migration is this class's
     * concern either way.
     *
     * @param  list<string>  $files  project-relative paths, already filtered to migrations
     * @param  Closure(string): ?string  $source  reads one path at head; null when it cannot be read
     * @return list<ChangedFileSymbols>
     */
    public static function resolveUntracked(array $files, Closure $source): array
    {
        // `hasAdditions: true` because `git status` just named the file, so it exists: an unreadable
        // head is an I/O failure, and the branch reports that rather than reading the migration as
        // holding no operations at all.
        return array_map(
            static fn (string $file): ChangedFileSymbols => self::resolve($file, $source($file), null, isNew: true, hasAdditions: true),
            $files,
        );
    }

    private static function unreadable(string $file, string $side): ChangedFileSymbols
    {
        return new ChangedFileSymbols($file, '', [], cosmeticOnly: false,
            findings: ["{$file} could not be read at {$side} — schema operations were not compared"]);
    }
}
