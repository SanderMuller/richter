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

    public const string REACH_NO_GUARD_FOUND = 'no-guard-found';

    public const string REACH_NO_KNOWN_PATH = 'no-known-path';

    /**
     * @param  string  $lane  `auth`, `model`, `contract`, `boundary`, `parity` or `migration`
     * @param  int  $tier  1, 2 or 3 — a fact about the hazard, never configurable
     * @param  string|null  $cwe  null wherever no clean mapping exists; a stretched CWE teaches a
     *   reader the mapping is decorative
     * @param  string  $member  the fully qualified member the hazard sits on, or the class where the
     *   hazard is class-level (a model's `$fillable`, a deleted policy)
     * @param  string  $evidence  one line naming what moved, base side to head side
     * @param  list<string>  $removedTokens  what the moved-not-removed guard looks for among the
     *   diff's ADDED tokens ({@see HazardFindings}); empty when the hazard is not a removal and the
     *   guard must never suppress it. A LIST because one removal can be named more than one way: a
     *   policy method is referenced both as a string ability and as the class constant standing for
     *   it, and either arriving elsewhere in the diff is the same guard moving.
     * @param  string|null  $reach  filled by the reach lane after the walk; null until then. Two of
     *   the four states are findings — `public-write` and `gated` — and two are admissions:
     *   `no-guard-found` (reached, nothing guarding it visible) and `no-known-path` (nothing reaching
     *   it visible). Neither admission is evidence of safety.
     * @param  string|null  $ignoreKey  the `hazards.ignore` entry that silences this hazard; defaults
     *   to `$member` when null
     * @param  bool  $reachViaDeclaringClass  whether `reach` came from a class's callers rather than
     *   from the walk's own chains ({@see HazardReach}) — the declaring class for a member, and the
     *   class itself where the hazard is class-level, which is why the label says "via its class"
     *   rather than naming a declaring class a `migration` or `contract` hazard may not have. It moves
     *   neither the class nor the level. It is reported because the condition that sets it — this
     *   member is in no chain — means the entry points the report lists are not this hazard's
     *   evidence, so the two read as a contradiction unexplained. A diff whose counts are zero
     *   because nothing seeded the walk is the sharpest case, not the only one.
     * @param  list<string>  $alsoIgnoredBy  further `hazards.ignore` entries that silence this hazard,
     *   for one that sits inside something a reader would silence whole. A migration's column drop is
     *   keyed `posts.subtitle` and answers to `posts` as well, so a noisy table is silenced once rather
     *   than column by column.
     */
    public function __construct(
        public string $lane,
        public int $tier,
        public ?string $cwe,
        public string $member,
        public string $evidence,
        public array $removedTokens = [],
        public ?string $reach = null,
        public ?string $ignoreKey = null,
        public bool $reachViaDeclaringClass = false,
        public array $alsoIgnoredBy = [],
    ) {}

    public function withReach(string $reach, bool $viaDeclaringClass = false): self
    {
        return new self($this->lane, $this->tier, $this->cwe, $this->member, $this->evidence, $this->removedTokens, $reach, $this->ignoreKey, $viaDeclaringClass, $this->alsoIgnoredBy);
    }

    /**
     * The reach class as a report prints it, with the provenance suffix where one applies. The raw
     * `reach` value stays the payload's — a consumer matches on the four states, so the sentence a
     * human needs must not become part of the value it matches.
     */
    public function reachLabel(): string
    {
        $reach = $this->reach ?? self::REACH_NO_KNOWN_PATH;

        return $this->reachViaDeclaringClass ? "{$reach} (via its class)" : $reach;
    }

    /** What `hazards.ignore` matches on — the member unless the lane named something narrower. */
    public function suppressionKey(): string
    {
        return $this->ignoreKey ?? $this->member;
    }

    /**
     * Every `hazards.ignore` entry that silences this hazard, narrowest first.
     *
     * @return list<string>
     */
    public function suppressionKeys(): array
    {
        return [$this->suppressionKey(), ...$this->alsoIgnoredBy];
    }
}
