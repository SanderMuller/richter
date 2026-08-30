<?php declare(strict_types=1);

namespace SanderMuller\Richter\Changes;

use SanderMuller\Richter\Analysis\Hazard;
use SanderMuller\Richter\Analysis\HazardFindings;
use SanderMuller\Richter\Analysis\Hazards\HazardLanes;
use SanderMuller\Richter\Analysis\ImpactAnalyzer;
use SanderMuller\Richter\Analysis\PayloadParityChecker;
use SanderMuller\Richter\Analysis\ResourceKeyParser;

/**
 * The member-level change set for one changed PHP file. `cosmeticOnly` files seed nothing; a file
 * with no resolvable member change but a real non-resolvable one (enum case / constant / property /
 * class modifier) drives the coarse, low-confidence class seed in {@see ImpactAnalyzer}.
 *
 * A changed file with no PHP members — a Blade view — instead carries `directSeeds`: graph node ids
 * to seed verbatim (its `view::…` node). Such a file is always a real, precise change, never additive.
 */
final readonly class ChangedFileSymbols
{
    /**
     * @param  list<MemberChange>  $members
     * @param  list<string>  $directSeeds  graph node ids to seed as-is (changed Blade views, frontend-referenced routes); empty for PHP files
     * @param  list<string>  $findings  advisory notes about the changed source itself (e.g. an eager-load string matching no relation)
     * @param  bool  $unresolvedFrontendReferences  a frontend file contained endpoint references the
     *   scan could not resolve (a dynamic route() argument, an unmatched Wayfinder import) — the
     *   file must read as UNRESOLVED, never as "touches nothing"
     * @param  list<string>  $modelFieldSet  a changed `app/Models` file's head-side `$fillable`/`$casts`/`casts()`
     *   field union — the {@see PayloadParityChecker} denominator; empty
     *   for a non-model file, a new model file, or an unreadable base
     * @param  list<string>  $addedModelFields  the subset of `$modelFieldSet` added by this diff (present
     *   at head, absent at base) — the check's trigger; empty unless the file is an existing model
     *   whose base source was readable
     * @param  bool  $isNewFile  the diff's old side was `/dev/null` — a genuinely new file, which is a
     *   real change even though every member of it reads as added ({@see hasOnlyAdditiveOrCosmeticChanges()})
     * @param  list<string>  $removedResourceKeys  a changed resource file's `toArray()` keys present at
     *   base and absent at head, strict-mode-parsed ({@see ResourceKeyParser}) —
     *   the consumer-parity lane's trigger; empty for a non-resource file, a new file, an unreadable
     *   base, or a `null` strict parse on either side
     * @param  list<string>  $addedResourceKeys  the inverse diff (head − base), feeding the finding's
     *   rename hint; same emptiness rules as `$removedResourceKeys`
     * @param  list<string>  $removedRequestFields  a changed form-request file's `rules()` field names
     *   present at base and absent at head ({@see RequestFieldParser}) — the request-parity lane's
     *   trigger, with the same emptiness rules as `$removedResourceKeys`
     * @param  array<string, array{0: list<string>, 1: list<string>}>  $inlineRequestFields  the same
     *   diff for validation written inline in a method (`$request->validate([...])`), keyed by the
     *   fully qualified member id so a controller's several actions — and two classes in one file —
     *   stay apart
     * @param  list<string>  $addedRequestFields  the inverse diff (head − base), feeding that finding's
     *   rename hint
     * @param  list<Hazard>  $hazards  this file's change hazards, already parsed from both sides by
     *   {@see HazardLanes} — source text is not carried, so a lane that ran later would have nothing
     *   left to compare. Still unfiltered: {@see HazardFindings} runs the whole-diff
     *   moved-not-removed guard over them.
     * @param  list<string>  $addedHazardTokens  the guard tokens this file ADDED, which suppress a
     *   matching removal reported by any other file in the same diff
     */
    public function __construct(
        public string $file,
        public string $fqcn,
        public array $members,
        public bool $cosmeticOnly,
        public array $directSeeds = [],
        public array $findings = [],
        public bool $unresolvedFrontendReferences = false,
        public array $modelFieldSet = [],
        public array $addedModelFields = [],
        public bool $isNewFile = false,
        public array $removedResourceKeys = [],
        public array $addedResourceKeys = [],
        public array $removedRequestFields = [],
        public array $addedRequestFields = [],
        public array $inlineRequestFields = [],
        public array $hazards = [],
        public array $addedHazardTokens = [],
        /** @var array<string, array<string, list<string>>> `Fqcn->property` => style => site, for the members this diff changed */
        public array $siblingReads = [],
    ) {}

    /** @return list<MemberChange> */
    public function resolvableMembers(): array
    {
        return array_values(array_filter(
            $this->members,
            static fn (MemberChange $member): bool => $member->resolvable && ! $member->isAdditive(),
        ));
    }

    /**
     * Has a non-additive change the graph cannot pin to a member node — the trigger for the
     * coarse, MEDIUM-capped class seed.
     */
    public function needsCoarseSeed(): bool
    {
        return $this->unpinnableMembers() !== [];
    }

    /**
     * The members that trigger the coarse seed: changed, not merely added, and with no member node for
     * the graph to pin them to.
     *
     * Expressed as the list rather than as a second copy of the predicate, and {@see needsCoarseSeed()}
     * now asks it, so the two cannot drift. The reason `richter:affected-tests` prints for a
     * low-confidence run names these, because a bare "a changed member could not be pinned" leaves a
     * reader with nothing to look at — no member, no file, no way to judge whether the veto is right.
     * The KIND is the actionable half: a property or a class-level modifier has no member node by
     * design ({@see MemberChange}), so there is nothing in the code to change to clear it.
     *
     * @return list<MemberChange>
     */
    public function unpinnableMembers(): array
    {
        if ($this->cosmeticOnly) {
            return [];
        }

        return array_values(array_filter(
            $this->members,
            static fn (MemberChange $member): bool => ! $member->isAdditive() && ! $member->resolvable,
        ));
    }

    public function hasOnlyAdditiveOrCosmeticChanges(): bool
    {
        // A genuinely new file is never "additive with no impact". Its members all read CHANGE_ADDED
        // for one reason only — there is no base side to diff them against — but the class itself is
        // new: it can be an entry surface (a command, job, listener) and it can reach existing code.
        // Checked first, so a new file with no members at all (a marker interface, an empty class),
        // which classifies `cosmeticOnly`, is covered too.
        if ($this->isNewFile) {
            return false;
        }

        if ($this->directSeeds !== [] || $this->unresolvedFrontendReferences) {
            return false;
        }

        if ($this->cosmeticOnly) {
            return true;
        }

        return array_all($this->members, fn (MemberChange $member): bool => $member->isAdditive());
    }
}
