<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis\Hazards;

use SanderMuller\Richter\Analysis\Hazard;
use SanderMuller\Richter\Analysis\HazardFindings;

/**
 * One hazard lane, pure over a single changed file's two sides. A lane never reads the rest of the
 * diff: the moved-not-removed guard that needs the whole change set runs one stage later, in
 * {@see HazardFindings}, over the tokens every lane reports as added.
 */
interface HazardLane
{
    /**
     * @param  string  $file  repo-relative path, so a lane can scope itself (`app/Models/`, `app/Policies/`)
     * @return array{0: list<Hazard>, 1: list<string>} this file's hazards, and the tokens it added
     */
    public static function for(string $file, string $headSrc, string $baseSrc): array;
}
