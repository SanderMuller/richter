<?php declare(strict_types=1);

namespace SanderMuller\Richter\Changes;

use SanderMuller\Richter\Graph\BladeViews;
use SanderMuller\Richter\Tracers\FeatureGateChecker;

/**
 * A changed Blade view. It carries no PHP member to pin, so it seeds its own `view::…` node — the
 * authorization-flag and component-render surface, invisible otherwise.
 *
 * Beside {@see RouteFileChanges} rather than inside {@see ChangedSymbols}: the diff loop dispatches
 * one file kind per collaborator, and the loop itself sits at the class's complexity ceiling.
 *
 * @internal
 */
final class BladeViewChange
{
    /**
     * Null when the path is not a view the graph knows — the caller's signal to keep looking.
     *
     * @param  string|null  $headSrc  null when the view could not be read at head. Only the flag note
     *   and the inline-endpoint seeds depend on it; the view seed itself does not, so an unreadable
     *   source narrows the answer instead of losing it.
     */
    public static function resolve(string $file, ?string $headSrc, ?string $baseSrc, FrontendChanges $frontend, FeatureGateChecker $gates): ?ChangedFileSymbols
    {
        $seed = BladeViews::seedForChangedFile($file);

        if ($seed === null) {
            return null;
        }

        // Inline-script endpoint literals (`fetch('/api/…')` in Alpine or vanilla JS) ride along as
        // touched-surface seeds next to the view node.
        return new ChangedFileSymbols($file, '', [], cosmeticOnly: false, directSeeds: [
            $seed,
            ...$frontend->inlineUriSeeds($headSrc, $baseSrc),
        ], findings: $gates->bladeFindingsFor($headSrc ?? ''));
    }
}
