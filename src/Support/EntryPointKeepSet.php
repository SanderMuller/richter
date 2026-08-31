<?php declare(strict_types=1);

namespace SanderMuller\Richter\Support;

use SanderMuller\Richter\Analysis\EntryPointAttribution;

/**
 * Splits the entry surfaces a diff reaches into the ones the TASK owns and the ones reached only
 * through a file the project calls a hub.
 *
 * The report's ordering ({@see EntryPointAttribution}) answers a different question: which rows a
 * reader should meet first. That is specificity, and it is a total order over everything. Ownership is
 * not an ordering at all — a surface either belongs to the work in front of you or is fan-out through
 * a shared class you merely touched — and no measurement this package ran produced a rule for it. Two
 * applications gave no defensible threshold, and the one real hub list names a service provider, a
 * shared client and a model: a set the measurement explicitly could NOT derive.
 *
 * So hub-ness is CONFIGURATION, and an empty configuration keeps everything. A project that has not
 * described its hubs gets the full list, which is the honest answer rather than a guessed one. Nothing
 * here reaches the risk ladder, the gate or test selection: a hub list is a project's policy, not
 * evidence about the code, and it must never grade a change.
 *
 * `ownReach` is deliberately not an input. It ranks how specifically the diff explains a surface,
 * which is a presentation decision; hub-ness decides ownership. Two questions, two answers.
 *
 * An UNATTRIBUTED surface is kept, following {@see AssociationSurfaces}: absence of a reason is not
 * evidence of a weak one. That is not a stylistic echo — the routes a changed frontend file references
 * are appended to the report without ever being attributed, so a rule that dropped the unexplained
 * would drop exactly the surfaces a frontend change owns.
 *
 * @internal
 */
final readonly class EntryPointKeepSet
{
    /**
     * @param  list<string>  $kept  the surfaces this diff owns, in the order they were given
     * @param  int  $droppedHub  how many were reached only through a configured hub
     */
    private function __construct(public array $kept, public int $droppedHub) {}

    /**
     * @param  list<string>  $entryPoints  the run's reported entry points, in reading order
     * @param  array<string, array{via: string, ownReach: int}>  $attribution
     * @param  array<string, array{file: string, line?: int}>  $locations
     * @param  array<string, int>  $changed  the diff's changed files, keyed by path
     * @param  list<string>  $hubPaths  exact project-relative paths
     * @param  list<string>  $hubPathPrefixes  directory prefixes
     */
    public static function for(
        array $entryPoints,
        array $attribution,
        array $locations,
        array $changed,
        array $hubPaths,
        array $hubPathPrefixes,
    ): self {
        if ($hubPaths === [] && $hubPathPrefixes === []) {
            return new self($entryPoints, 0);       // not configured: the feature is off
        }

        $changedFiles = [];

        foreach (array_keys($changed) as $file) {
            $changedFiles[self::normalise($file)] = true;
        }

        $kept = [];
        $droppedHub = 0;

        foreach ($entryPoints as $entryPoint) {
            // The surface's OWN file being in the diff outranks any prefix: you edited that admin
            // resource, you did not merely touch the model behind it.
            $file = $locations[$entryPoint]['file'] ?? null;

            if (is_string($file) && isset($changedFiles[self::normalise($file)])) {
                $kept[] = $entryPoint;

                continue;
            }

            $via = $attribution[$entryPoint]['via'] ?? null;

            // Deliberately NOT also testing `$via` against the changed files. Attribution walks the
            // per-changed-file seed map, so `via` is always a changed file by construction and the
            // test can only ever return true — see the class docblock's spec reference.
            if (is_string($via) && self::isHub($via, $hubPaths, $hubPathPrefixes)) {
                ++$droppedHub;

                continue;
            }

            $kept[] = $entryPoint;
        }

        return new self($kept, $droppedHub);
    }

    /**
     * @param  list<string>  $hubPaths
     * @param  list<string>  $hubPathPrefixes
     */
    private static function isHub(string $path, array $hubPaths, array $hubPathPrefixes): bool
    {
        $path = self::normalise($path);

        foreach ($hubPaths as $hub) {
            if ($path === self::normalise($hub)) {
                return true;
            }
        }

        return array_any($hubPathPrefixes, fn (string $prefix) => str_starts_with($path, self::normalise($prefix)));
    }

    /** A configured path and a reported one can disagree about a `./` prefix and mean the same file. */
    private static function normalise(string $path): string
    {
        return str_starts_with($path, './') ? substr($path, 2) : $path;
    }
}
