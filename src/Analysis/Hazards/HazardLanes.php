<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis\Hazards;

use SanderMuller\Richter\Analysis\Hazard;
use SanderMuller\Richter\Analysis\HazardFindings;

/**
 * Every hazard lane's parser, run where base and head source still exist — inside
 * `ChangedSymbols::classifyFile()`. `ChangedFileSymbols` carries extracted records, never source
 * text, so a lane that waited for the analysis stage would have nothing left to compare.
 *
 * Each lane returns its hazards plus the tokens this file ADDED that another file's removal may have
 * moved here. {@see HazardFindings} owns the second pass over those
 * tokens; a lane never sees a file other than its own.
 */
final class HazardLanes
{
    /**
     * @return array{0: list<Hazard>, 1: list<string>, 2: list<string>} the file's hazards, the tokens
     *   it added, and any finding about the file itself
     */
    public static function for(string $file, bool $isNew, string $headSrc, ?string $baseSrc): array
    {
        // The Laravel 10 middleware Kernel is a class, so it reaches this dispatch — but what matters
        // in it is the middleware GROUPS it composes, which is a different comparison from any lane's.
        // Its Laravel 11+ counterpart, `bootstrap/app.php`, declares no class and is dispatched from
        // `ChangedSymbols` instead; both go through the same reader. Added to what the lanes find,
        // never instead of it: the Kernel is still a class whose members can lose a guard or change
        // shape, and replacing the lanes would switch that analysis off for the one file in `app/`
        // most likely to hold it.
        // A NEW Kernel still contributes arrivals — the reader answers a null base with them and no
        // hazard. Skipping it would let a guard moved out of a route and into a Kernel added by the
        // same diff report as a tier-3 removal at the place it left.
        $group = $file === 'app/Http/Kernel.php'
            ? MiddlewareGroupHazards::for($file, $headSrc, $isNew ? null : $baseSrc)
            : [[], [], []];

        // Every predicate is a comparison, so a side that does not exist ends the matter: a new file
        // has nothing to have lost, and an unreadable base is already coarse-seeded upstream.
        if ($isNew || $baseSrc === null) {
            return $group;
        }

        // A side that has source but yields no class-like did not parse. Treating that as "every
        // class was deleted" would report a whole file's contract as broken because of a syntax
        // error somewhere in it — a lane never guesses from half a comparison. A genuinely deleted
        // file has an EMPTY head, which is a real comparison and stays in.
        if (($headSrc !== '' && HazardSource::classLikes($headSrc) === []) || HazardSource::classLikes($baseSrc) === []) {
            return $group;
        }

        [$hazards, $added, $findings] = $group;

        foreach (self::lanes() as $lane) {
            [$laneHazards, $laneAdded] = $lane::for($file, $headSrc, $baseSrc);
            $hazards = [...$hazards, ...$laneHazards];
            $added = [...$added, ...$laneAdded];
        }

        return [$hazards, $added, $findings];
    }

    /**
     * The lanes, in report order. Class-string list rather than instances: every lane is pure over
     * one file's two sources and holds no state worth constructing.
     *
     * @return list<class-string<HazardLane>>
     */
    private static function lanes(): array
    {
        return [
            AuthHazardLane::class,
            ModelHazardLane::class,
            ContractHazardLane::class,
            BoundaryHazardLane::class,
        ];
    }
}
