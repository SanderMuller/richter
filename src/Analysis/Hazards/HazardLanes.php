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
     * @return array{0: list<Hazard>, 1: list<string>} the file's hazards, and the tokens it added
     */
    public static function for(string $file, bool $isNew, string $headSrc, ?string $baseSrc): array
    {
        // Every predicate is a comparison, so a side that does not exist ends the matter: a new file
        // has nothing to have lost, and an unreadable base is already coarse-seeded upstream.
        if ($isNew || $baseSrc === null) {
            return [[], []];
        }

        // A side that has source but yields no class-like did not parse. Treating that as "every
        // class was deleted" would report a whole file's contract as broken because of a syntax
        // error somewhere in it — a lane never guesses from half a comparison. A genuinely deleted
        // file has an EMPTY head, which is a real comparison and stays in.
        if (($headSrc !== '' && HazardSource::classLikes($headSrc) === []) || HazardSource::classLikes($baseSrc) === []) {
            return [[], []];
        }

        $hazards = [];
        $added = [];

        foreach (self::lanes() as $lane) {
            [$laneHazards, $laneAdded] = $lane::for($file, $headSrc, $baseSrc);
            $hazards = [...$hazards, ...$laneHazards];
            $added = [...$added, ...$laneAdded];
        }

        return [$hazards, $added];
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
