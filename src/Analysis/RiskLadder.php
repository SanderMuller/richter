<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

use SanderMuller\Richter\Support\AppNamespace;

/**
 * The risk level: one decision ladder, evaluated top to bottom. No weights, no arithmetic, no tuning
 * knob.
 *
 * ```
 * 0. Nothing to assess               -> LOW     ("no analysable change")
 * 1. A hazard fired                  -> level from the tier x reach matrix
 * 2. Reach is unplaced               -> MEDIUM  ("could not place what this reaches")
 * 3. A verification-set member is     -> MEDIUM  (those surfaces are named)
 *    unreferenced, or could not be
 *    checked
 * 4. Otherwise                       -> LOW
 * ```
 *
 * **Step 0 and step 2 are different states, and collapsing them is the model's easiest mistake.**
 * Step 0 is *nothing was analysed*: no hazard fired and nothing pre-existing was seeded — an empty
 * diff, a cosmetic-only diff, an additive-only diff, or a brand-new file nothing calls. Step 2 is
 * *something that already existed was analysed and could not be placed*. Without step 0 a whitespace
 * commit reports MEDIUM and trips `--fail-on=medium`.
 *
 * The level replaces a model that scored breadth — impacted nodes, entry points reached, whether the
 * diff touched an entry class. All three measured how BIG a change was, so removing an authorization
 * check and renaming a method on a popular class scored the same. Those numbers are still reported,
 * under `Impact`, where they describe the change instead of grading it.
 *
 * Every outcome carries its cause. A bare `MEDIUM` is not a renderable result at any step.
 */
final class RiskLadder
{
    /**
     * @param  list<Hazard>  $hazards  reach already attached ({@see HazardReach})
     * @param  bool  $seeded  whether the walk was seeded with anything that already existed
     * @param  list<string>  $verificationSet  entry-point nodes, plus a class anchor for each changed
     *   class that reached none of them
     * @return array{0: RiskLevel, 1: string, 2: array<string, bool>}  level, its cause, and each
     *   verification-set member's verified state
     */
    public static function decide(array $hazards, bool $seeded, array $verificationSet, ?TestReferenceIndex $tests): array
    {
        $verification = self::verify($verificationSet, $tests);

        if ($hazards !== []) {
            return [...self::fromHazards($hazards), $verification];
        }

        if (! $seeded) {
            return [RiskLevel::Low, 'no analysable change: nothing in this diff seeds a walk', $verification];
        }

        if ($verificationSet === []) {
            return [RiskLevel::Medium, 'no hazard; richter could not place what this change reaches', $verification];
        }

        $unverified = array_keys(array_filter($verification, static fn (bool $verified): bool => ! $verified));

        if ($unverified !== []) {
            $total = count($verification);

            return [RiskLevel::Medium, 'no hazard; ' . count($unverified) . " of {$total} reached surfaces have no test referencing them", $verification];
        }

        return [RiskLevel::Low, 'no hazard; every reached surface is referenced by a test', $verification];
    }

    /**
     * The tier x reach matrix, taking the worst cell any one hazard lands in.
     *
     * Tier 3 is HIGH everywhere: a removed guard is a removed guard, and the graph's inability to
     * name a caller is richter's limit, not evidence of safety. Capping it at `no-known-path` would
     * silence tier 3 on exactly the applications where reach is hardest to resolve. Reach modulates
     * the middle of the scale only.
     *
     * @param  list<Hazard>  $hazards
     * @return array{0: RiskLevel, 1: string}
     */
    private static function fromHazards(array $hazards): array
    {
        $levels = array_map(
            static fn (Hazard $hazard): RiskLevel => self::cell($hazard->tier, $hazard->reach ?? Hazard::REACH_NO_KNOWN_PATH),
            $hazards,
        );

        $worst = RiskLevel::Low;
        $driver = 0;

        foreach ($levels as $index => $level) {
            if (! $worst->atLeast($level)) {
                $worst = $level;
                $driver = $index;
            }
        }

        $hazard = $hazards[$driver];
        $reach = $hazard->reach ?? Hazard::REACH_NO_KNOWN_PATH;
        $cause = "tier {$hazard->tier} `{$hazard->lane}` hazard on {$hazard->member}, reach {$reach}";
        $others = count($hazards) - 1;

        return [$worst, $others > 0 ? "{$cause} (and {$others} more)" : $cause];
    }

    private static function cell(int $tier, string $reach): RiskLevel
    {
        return match (true) {
            $tier >= 3 => RiskLevel::High,
            $tier === 2 => $reach === Hazard::REACH_PUBLIC_WRITE ? RiskLevel::High : RiskLevel::Medium,
            default => $reach === Hazard::REACH_NO_KNOWN_PATH ? RiskLevel::Low : RiskLevel::Medium,
        };
    }

    /**
     * Each verification-set member's verified state.
     *
     * **A state that could not be checked counts as unverified, never as verified.**
     * `hasReference()` is a tri-state: null means the check never ran — an unrecognised node shape, or
     * a route miss while the router was unavailable, where name matching never happened. Its own
     * contract says a consumer must then fall back to the full suite. Reading null as "not
     * unreferenced" would open the LOW path on surfaces nothing checked.
     *
     * **An absent index is the same state, not a fourth one.** `detect-changes` always builds one, and
     * `fromTests()` returns an EMPTY index rather than null when there is no `tests/` directory — so a
     * project without tests grades every surface unreferenced, which is the intended reading.
     *
     * The weak-assertion sub-tag counts as VERIFIED. Its grader collapses every uncertainty to plain
     * `referenced` and states under-firing as its safe direction; building the level on the weaker
     * reading would invert that discipline. The tag still prints on the row.
     *
     * **Only a RUNNABLE test file counts, whatever kind of surface it is.** `fromTests()` indexes
     * every PHP file under tests/ — fixtures, helpers, traits, base cases — and a fixture containing
     * `route('posts.update')` or `artisan('reports:sync')` would otherwise verify a surface no test
     * exercises. That is a false LOW, the one direction this model must not fail in. The per-row
     * ANNOTATION keeps the broader reading, which is why the level asks for the files rather than for
     * `hasReference()`'s boolean.
     *
     * @param  list<string>  $members
     * @return array<string, bool>
     */
    private static function verify(array $members, ?TestReferenceIndex $tests): array
    {
        $verification = [];

        foreach ($members as $member) {
            if (! $tests instanceof TestReferenceIndex) {
                $verification[$member] = false;

                continue;
            }

            $verification[$member] = AppNamespace::isAppClass($member) && ! str_contains($member, '::')
                ? TestReferenceIndex::runnableOnly($tests->testsImporting($member)) !== []
                : self::routeIsVerified($member, $tests);
        }

        return $verification;
    }

    /**
     * A route, command or schedule node counts as verified only when a RUNNABLE test file references
     * it. Null means the check never ran, which is unverified for the same reason: the LOW path must
     * not open on a surface nothing checked.
     */
    private static function routeIsVerified(string $member, TestReferenceIndex $tests): bool
    {
        $files = $tests->testsReferencing($member);

        return $files !== null && TestReferenceIndex::runnableOnly($files) !== [];
    }
}
