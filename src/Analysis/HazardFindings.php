<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

use SanderMuller\Richter\Analysis\Hazards\GuardArrival;
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
        return self::split($changed, $enabledOverride, $also)[0];
    }

    /**
     * {@see for()}, plus a finding for each removed check that moved rather than left. With no
     * {@see MovedGuards} to compare the two places, every move is taken at its word and says nothing.
     *
     * @param  list<ChangedFileSymbols>  $changed
     * @param  list<Hazard>  $also
     * @return array{0: list<Hazard>, 1: list<string>}
     */
    public static function split(array $changed, ?bool $enabledOverride = null, array $also = [], ?MovedGuards $moved = null): array
    {
        if (! ($enabledOverride ?? RichterConfig::hazardsEnabled())) {
            return [self::worstFirst($also), []];
        }

        $ignore = RichterConfig::hazardsIgnore();
        $hazards = [];
        $findings = [];
        $remaining = [];

        foreach ($changed as $file) {
            foreach ($file->hazards as $hazard) {
                // Silenced by name: nothing about it is worth a line either.
                if (array_intersect($hazard->suppressionKeys(), $ignore) !== []) {
                    continue;
                }

                [$kept, $finding] = self::afterMoves($hazard, $changed, $moved);
                $hazards = [...$hazards, ...($kept instanceof Hazard ? [$kept] : [])];
                $findings = [...$findings, ...($finding === null ? [] : [$finding])];

                if (! $kept instanceof Hazard && $hazard->whenMoved instanceof Hazard) {
                    $remaining[$hazard->whenMoved->member] = $hazard->whenMoved;
                }
            }
        }

        // A member that still reports a guard of its own says it already.
        $hazards = [...$hazards, ...array_values(array_diff_key($remaining, array_flip(array_map(static fn (Hazard $hazard): string => $hazard->member, $hazards))))];

        return [self::worstFirst([...$hazards, ...$also]), $findings];
    }

    /**
     * A hazard is suppressed when the thing it says was removed is added somewhere else in the same
     * diff. An authorization check is compared further ({@see MovedGuards}): the new place may not run
     * where the old one did. A middleware is not, because one move to a group is a move for every route
     * in it.
     *
     * An EMPTY token list never matches. That is the lane's way of saying "nothing was named here, so
     * nothing elsewhere can be this same thing arriving" — a neutered `authorize()` body names no
     * ability, and must not be silenced by an unrelated `authorize()` added in another file.
     *
     * @param  list<ChangedFileSymbols>  $changed
     * @return array{0: Hazard|null, 1: string|null}
     */
    private static function afterMoves(Hazard $hazard, array $changed, ?MovedGuards $moved): array
    {
        $arrivals = self::arrivalsOf($hazard, $changed);

        if ($moved instanceof MovedGuards && $hazard->guard !== null && $arrivals !== []) {
            return $moved->judge($hazard, $arrivals);
        }

        return [array_any($arrivals, static fn (GuardArrival $arrival): bool => ! $arrival->inNewFile) ? null : $hazard, null];
    }

    /**
     * Everywhere the diff added what this hazard says was removed. Read over every changed file
     * precisely because the guard's whole point is cross-file: the removal is in one file and the
     * arrival is in another.
     *
     * A new file counts only for a check the hazard names and the ability is written out. Anything
     * looser would let an unrelated new file answer for it. An arrival whose receiver is no proven user
     * counts in its own class only.
     *
     * @param  list<ChangedFileSymbols>  $changed
     * @return list<GuardArrival>
     */
    private static function arrivalsOf(Hazard $hazard, array $changed): array
    {
        $arrivals = [];

        foreach ($changed as $file) {
            foreach (array_intersect($hazard->removedTokens, [...$file->addedHazardTokens, ...array_column($file->guardArrivals, 'token')]) as $token) {
                $named = array_values(array_filter($file->guardArrivals, static fn (GuardArrival $arrival): bool => $arrival->token === $token));
                $usable = array_values(array_filter(
                    $named === [] ? [new GuardArrival($token, $file->file)] : $named,
                    static fn (GuardArrival $arrival): bool => $arrival->canAnswerFor($hazard->member)
                        && (! $arrival->inNewFile || ($hazard->guard !== null && str_starts_with($token, 'ability:'))),
                ));

                $arrivals = [...$arrivals, ...$usable];
            }
        }

        return $arrivals;
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
        usort($hazards, static fn (Hazard $a, Hazard $b): int => [$b->tier, self::laneRank($a->lane), $a->member, $a->evidence] <=> [$a->tier, self::laneRank($b->lane), $b->member, $b->evidence]);

        return $hazards;
    }

    /**
     * Lanes sort by name, except that `model` reads just before `contract`: widened data exposure comes
     * before a changed member of the same tier.
     *
     * @return array{0: string, 1: int}
     */
    private static function laneRank(string $lane): array
    {
        return $lane === 'model' ? ['contract', 0] : [$lane, 1];
    }
}
