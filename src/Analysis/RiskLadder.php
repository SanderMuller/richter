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
     * @return array{0: RiskLevel, 1: string, 2: array<string, bool|null>}  level, its cause, and each
     *   verification-set member's state: true referenced, false unreferenced, NULL not checked
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

        return [...self::fromVerification($verification), $verification];
    }

    /**
     * The level for a change carrying no hazard, and a cause that distinguishes what was CHECKED and
     * found wanting from what was never checked at all.
     *
     * Both read MEDIUM — the safe direction does not change, because a surface nothing looked at is
     * not a verified one. What changes is the sentence. "3 of 3 reached surfaces have no test
     * referencing them" is a claim ABOUT TESTS, and stating it when no index was consulted reads as
     * evidence about tests when none was read. It also hides a defect — a caller that forgets to pass the index
     * produces a report indistinguishable from a project with no tests.
     *
     * @param  array<string, bool|null>  $verification
     * @return array{0: RiskLevel, 1: string}
     */
    private static function fromVerification(array $verification): array
    {
        $total = count($verification);
        $unchecked = count(array_filter($verification, static fn (?bool $state): bool => $state === null));
        $unreferenced = count(array_filter($verification, static fn (?bool $state): bool => $state === false));

        if ($unchecked === $total && $total > 0) {
            return [RiskLevel::Medium, 'no hazard; test references were not checked, so nothing here is verified'];
        }

        if ($unreferenced === 0 && $unchecked === 0) {
            return [RiskLevel::Low, 'no hazard; every reached surface is referenced by a test'];
        }

        $parts = [];

        if ($unreferenced > 0) {
            $parts[] = "{$unreferenced} of {$total} reached surfaces have no test referencing them";
        }

        if ($unchecked > 0) {
            $parts[] = "{$unchecked} could not be checked";
        }

        return [RiskLevel::Medium, 'no hazard; ' . implode(', and ', $parts)];
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

    /**
     * `no-guard-found` scoring exactly as `gated` is deliberate, not an oversight. It is an admission,
     * and an admission must move the level in neither direction: raising it would report HIGH across
     * every codebase whose surfaces Brain cannot classify, punishing a coverage gap as though it were
     * a security one, and lowering it would treat a missing classification as proof of a guard. The two differ in
     * what the report SAYS.
     *
     * `no-known-path` is the one admission that does move a cell, and only at tier 1: a signature
     * change nothing reaches is genuinely low, where one reached by an unclassified surface is not.
     */
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
     * **An absent index is UNCHECKED, not unreferenced.** Both keep the level at MEDIUM, so the safe
     * direction is identical — but only one of them is a statement about tests. `fromTests()` returns
     * an EMPTY index rather than null when there is no `tests/` directory, so a project genuinely
     * without tests still grades `unreferenced`, which is a real answer. A missing index is not.
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
     * @return array<string, bool|null>  true referenced, false unreferenced, null not checked
     */
    private static function verify(array $members, ?TestReferenceIndex $tests): array
    {
        $verification = [];

        foreach ($members as $member) {
            if (! $tests instanceof TestReferenceIndex) {
                // NOT false. No index means the question was never put, and answering "unreferenced"
                // would assert something about tests that nothing read.
                $verification[$member] = null;

                continue;
            }

            $verification[$member] = AppNamespace::isAppClass($member) && ! str_contains($member, '::')
                // A class import is a pure text scan, so it always has an answer.
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
    private static function routeIsVerified(string $member, TestReferenceIndex $tests): ?bool
    {
        $files = $tests->testsReferencing($member);

        // Null propagates: the index's own tri-state says the check could not run — an unrecognised
        // node shape, or a route miss while the router was unavailable. That is not "unreferenced".
        return $files === null ? null : TestReferenceIndex::runnableOnly($files) !== [];
    }
}
