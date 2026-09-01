<?php declare(strict_types=1);

namespace SanderMuller\Richter\Support;

use SanderMuller\Richter\Analysis\MiddlewareGroupFindings;
use Throwable;

/**
 * Whether an analyzed project root IS the booted application, so a runtime read (the registered
 * route table, the router's middleware expansion) describes the code under analysis and not a
 * stranger's. Extracted from {@see MiddlewareGroupFindings} so the
 * runtime-guards lane and the group-count note answer the question the same way.
 *
 * The two consumers differ deliberately on an UNKNOWN root: the group note proceeds (a wrong count
 * is a nuisance) while the guards lane fails closed (a wrong guard is a wrong level) — that policy
 * lives in the consumers, not here. This predicate takes a known root and answers only the
 * comparison; any failure reads as "not the project", the safe direction for both.
 *
 * @internal
 */
final class RunningApplication
{
    public static function isProject(string $root): bool
    {
        try {
            $analyzed = realpath($root);

            // Both sides unresolvable would compare false === false: an unreadable root must never
            // read as "this is the running app".
            return $analyzed !== false && $analyzed === realpath(base_path());
        } catch (Throwable) {
            return false;
        }
    }
}
