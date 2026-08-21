<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

use SanderMuller\Richter\Changes\ChangedFileSymbols;
use SanderMuller\Richter\Support\RichterConfig;

/**
 * The change-hazard family's dispatch, beside {@see ImpactAnalyzer} (complexity budget). One gate
 * (`hazards.enabled` / `--no-hazards`), then the second of the family's two passes.
 *
 * This is where the family DIFFERS from {@see ParityFindings}, which fans out per file inside
 * `detectChanges()`'s loop. A removal predicate cannot be decided from one file: authorization MOVES
 * — a controller's `authorize()` becomes a form request's, a route's middleware becomes a group's, a
 * policy becomes a gate — and a lane that called each of those a removal would be wrong most of the
 * time. So the first pass (inside `ChangedSymbols::classifyFile()`, where source still exists)
 * records what every file ADDED, and this second pass evaluates each file's removals against the
 * union of all of it.
 */
final class HazardFindings
{
    /**
     * Every hazard the diff carries, ordered by tier descending so the worst reads first.
     *
     * @param  list<ChangedFileSymbols>  $changed
     * @param  bool|null  $enabledOverride  the command's `--no-hazards`; null defers to config
     * @param  list<Hazard>  $also  hazards a lane outside the classification stage produced — the
     *   parity family, which needs the graph and so cannot run where the diff's source is still in
     *   hand. Gated by its own `payload_parity.enabled` key, which is why `--no-hazards` does not
     *   silence it and the whole-diff guard does not apply to it: a parity hazard names a payload
     *   that went missing, not a token that could have moved.
     * @return list<Hazard>
     */
    public static function for(array $changed, ?bool $enabledOverride = null, array $also = []): array
    {
        if (! ($enabledOverride ?? RichterConfig::hazardsEnabled())) {
            return self::worstFirst($also);
        }

        $added = self::addedTokens($changed);
        $ignore = RichterConfig::hazardsIgnore();
        $hazards = [];

        foreach ($changed as $file) {
            foreach ($file->hazards as $hazard) {
                if (self::suppressed($hazard, $added, $ignore)) {
                    continue;
                }

                $hazards[] = $hazard;
            }
        }

        return self::worstFirst([...$hazards, ...$also]);
    }

    /**
     * The whole diff's added tokens. Unioned across every changed file precisely because the guard's
     * whole point is cross-file: the removal is in one file and the arrival is in another.
     *
     * @param  list<ChangedFileSymbols>  $changed
     * @return list<string>
     */
    private static function addedTokens(array $changed): array
    {
        $tokens = [];

        foreach ($changed as $file) {
            $tokens = [...$tokens, ...$file->addedHazardTokens];
        }

        return array_values(array_unique($tokens));
    }

    /**
     * A hazard is suppressed when the thing it says was removed is added somewhere else in the same
     * diff, or when the project has named it in `hazards.ignore`.
     *
     * An EMPTY token list never matches. That is the lane's way of saying "nothing was named here, so
     * nothing elsewhere can be this same thing arriving" — a neutered `authorize()` body names no
     * ability, and must not be silenced by an unrelated `authorize()` added in another file.
     *
     * @param  list<string>  $added
     * @param  list<string>  $ignore
     */
    private static function suppressed(Hazard $hazard, array $added, array $ignore): bool
    {
        if (array_intersect($hazard->removedTokens, $added) !== []) {
            return true;
        }

        return in_array($hazard->suppressionKey(), $ignore, strict: true);
    }

    /**
     * Tier descending, then lane and member, so the order is stable across runs — a report that
     * reshuffles between two runs of the same diff cannot be diffed by a reviewer or a CI job.
     *
     * @param  list<Hazard>  $hazards
     * @return list<Hazard>
     */
    private static function worstFirst(array $hazards): array
    {
        usort($hazards, static fn (Hazard $a, Hazard $b): int => [$b->tier, $a->lane, $a->member, $a->evidence] <=> [$a->tier, $b->lane, $b->member, $b->evidence]);

        return $hazards;
    }
}
