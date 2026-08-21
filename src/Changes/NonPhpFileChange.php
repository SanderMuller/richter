<?php declare(strict_types=1);

namespace SanderMuller\Richter\Changes;

use Closure;
use SanderMuller\Richter\Graph\BladeViews;
use SanderMuller\Richter\Tracers\FeatureGateChecker;

/**
 * The diff loop's dispatch for the changed files that declare no PHP class — a route file, the
 * middleware-group bootstrap, a Blade view. Each has its own collaborator; this picks between them.
 *
 * Separate from {@see ChangedSymbols} because that class's diff loop sits at its complexity ceiling,
 * and because the choice is genuinely one concern: which non-class file kind is this, and who reads it.
 *
 * @internal
 */
final class NonPhpFileChange
{
    /**
     * Null when no reader here claims the file — the caller's signal to keep looking.
     *
     * The two sources are resolved LAZILY. Most changed files reach this dispatch and are claimed by
     * nothing, and each source costs a `git show`; reading them up front would spend two subprocesses
     * per unrelated file in the diff.
     *
     * @param  Closure(): ?string  $headSrc
     * @param  Closure(): ?string  $baseSrc
     * @param  bool  $hasAdditions  whether the diff adds lines, which proves the file exists at head
     */
    public static function resolve(string $file, Closure $headSrc, Closure $baseSrc, bool $isNew, bool $hasAdditions, FrontendChanges $frontend, FeatureGateChecker $gates): ?ChangedFileSymbols
    {
        if (RouteFileChanges::handles($file)) {
            return RouteFileChanges::resolve($file, $headSrc(), $baseSrc(), $isNew, $hasAdditions);
        }

        if (BladeViews::seedForChangedFile($file) === null) {
            return null;
        }

        return BladeViewChange::resolve($file, $headSrc(), $baseSrc(), $frontend, $gates);
    }
}
