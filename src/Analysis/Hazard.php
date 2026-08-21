<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

/**
 * One change hazard: a property of the diff saying the change may break something. Tiered, unlike a
 * finding — a finding names what richter could not SEE, a hazard names what may BREAK.
 *
 * Built in two stages. A lane emits it at classification time, where base and head source exist, with
 * `reach` still null; {@see HazardFindings} filters it against the whole diff's added tokens, and the
 * reach lane fills `reach` in once the walk has run. Only a presenter flattens it to a string, so
 * nothing in the pipeline has to parse its own output.
 */
final readonly class Hazard
{
    public const string REACH_PUBLIC_WRITE = 'public-write';

    public const string REACH_GATED = 'gated';

    public const string REACH_NO_KNOWN_PATH = 'no-known-path';

    /**
     * @param  string  $lane  `auth`, `model`, `contract`, `boundary` or `parity`
     * @param  int  $tier  1, 2 or 3 — a fact about the hazard, never configurable
     * @param  string|null  $cwe  null wherever no clean mapping exists; a stretched CWE teaches a
     *   reader the mapping is decorative
     * @param  string  $member  the fully qualified member the hazard sits on, or the class where the
     *   hazard is class-level (a model's `$fillable`, a deleted policy)
     * @param  string  $evidence  one line naming what moved, base side to head side
     * @param  string  $removedToken  what the moved-not-removed guard looks for among the diff's
     *   ADDED tokens ({@see HazardFindings}); empty when the hazard is not a removal and the guard
     *   must never suppress it
     * @param  string|null  $reach  filled by the reach lane after the walk; null until then
     * @param  string|null  $ignoreKey  the `hazards.ignore` entry that silences this hazard; defaults
     *   to `$member` when null
     */
    public function __construct(
        public string $lane,
        public int $tier,
        public ?string $cwe,
        public string $member,
        public string $evidence,
        public string $removedToken = '',
        public ?string $reach = null,
        public ?string $ignoreKey = null,
    ) {}

    public function withReach(string $reach): self
    {
        return new self($this->lane, $this->tier, $this->cwe, $this->member, $this->evidence, $this->removedToken, $reach, $this->ignoreKey);
    }

    /** What `hazards.ignore` matches on — the member unless the lane named something narrower. */
    public function suppressionKey(): string
    {
        return $this->ignoreKey ?? $this->member;
    }
}
