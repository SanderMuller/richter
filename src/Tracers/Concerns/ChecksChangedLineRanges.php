<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tracers\Concerns;

use SanderMuller\Richter\Tracers\FeatureGateChecker;
use SanderMuller\Richter\Tracers\InertiaPageChecker;

/**
 * The locality rule shared by the per-source checkers ({@see FeatureGateChecker},
 * {@see InertiaPageChecker}): a finding counts only when its call
 * starts inside one of the CHANGED members' [start, end] line spans, so an untouched sibling
 * method's call never reads as part of the change.
 */
trait ChecksChangedLineRanges
{
    /** @param  list<array{int, int}>  $ranges */
    private function withinRanges(int $line, array $ranges): bool
    {
        return array_any($ranges, static fn (array $range): bool => $line >= $range[0] && $line <= $range[1]);
    }
}
