<?php declare(strict_types=1);

namespace SanderMuller\Richter\Changes;

use SanderMuller\Richter\Analysis\ImpactAnalyzer;

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
     * @param  list<string>  $directSeeds  graph node ids to seed as-is (changed Blade views); empty for PHP files
     * @param  list<string>  $findings  advisory notes about the changed source itself (e.g. an eager-load string matching no relation)
     */
    public function __construct(
        public string $file,
        public string $fqcn,
        public array $members,
        public bool $cosmeticOnly,
        public array $directSeeds = [],
        public array $findings = [],
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
        if ($this->cosmeticOnly) {
            return false;
        }

        return array_any($this->members, fn (MemberChange $member): bool => ! $member->isAdditive() && ! $member->resolvable);
    }

    public function hasOnlyAdditiveOrCosmeticChanges(): bool
    {
        if ($this->directSeeds !== []) {
            return false;
        }

        if ($this->cosmeticOnly) {
            return true;
        }

        return array_all($this->members, fn (MemberChange $member): bool => $member->isAdditive());
    }
}
