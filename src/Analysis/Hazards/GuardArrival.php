<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis\Hazards;

/**
 * Where a diff added a guard: the token a removal elsewhere is matched on, the place that makes the
 * check now, and the check as written there.
 *
 * @internal
 */
final readonly class GuardArrival
{
    /**
     * @param  string  $site  the member that gained the guard, or the file where no member is known
     * @param  string|null  $call  the check as written; null for a guard that is not a call
     * @param  bool  $inNewFile  whether the diff also added the file. Such an arrival silences a removal
     *   only where a path proves the move and the check refuses there.
     * @param  bool  $refuses  whether this is a form request's `authorize()` that certainly refuses on it
     *   ({@see RefusingChecks})
     * @param  bool  $userProven  whether the receiver is a proven user. Without that, the arrival answers
     *   for its own class only: elsewhere, the same word may ask something else.
     */
    public function __construct(
        public string $token,
        public string $site,
        public ?string $call = null,
        public bool $inNewFile = false,
        public bool $refuses = false,
        public bool $userProven = true,
    ) {}

    /** Whether the check can be the one that left this member. */
    public function canAnswerFor(string $member): bool
    {
        return $this->userProven || explode('::', $this->site, 2)[0] === explode('::', $member, 2)[0];
    }

    public function label(): string
    {
        return $this->call === null ? $this->site : "{$this->site} (`{$this->call}`)";
    }
}
