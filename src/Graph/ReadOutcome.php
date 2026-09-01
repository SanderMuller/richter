<?php declare(strict_types=1);

namespace SanderMuller\Richter\Graph;

/**
 * The graph a cache entry revives to, or which of the six ways reading it failed.
 *
 * A boolean was not enough, and the way it was not enough is the point. {@see GraphCache::read()}
 * returns null for an absent file, an undecodable one, a fingerprint that differs, and three
 * separate payload rejections — while {@see GraphCache::mergeBase()} validates a smaller set, only
 * `brainGraph` and `inputs`. So an entry whose inputs are current but whose EDGE LIST is corrupt
 * fails the read, satisfies the merge base, and compares equal on inputs.
 *
 * A reporter working from the boolean would call that a fingerprint mismatch — a false statement
 * about a file whose fingerprint is fine, pointing at a rebuild that will not fix it. The reason is
 * the difference between "this is stale" and "this is broken", and only one of those is the user's
 * to act on.
 *
 * @internal the cache's own plumbing; not a consumer API
 */
final readonly class ReadOutcome
{
    private function __construct(
        public ?CodeGraph $graph = null,
        public ?string $refusal = null,
        public ?string $detail = null,
    ) {}

    public static function of(CodeGraph $graph): self
    {
        return new self(graph: $graph);
    }

    /**
     * One of `no-cache-entry`, `cache-unreadable`, `fingerprint-mismatch`, `edges-rejected`,
     * `metadata-rejected`, `dispatch-sites-rejected`.
     */
    public static function refused(string $reason, string $detail): self
    {
        return new self(refusal: $reason, detail: $detail);
    }

    /** Whether the entry revived — the same question a real run asks of the cache. */
    public function hit(): bool
    {
        return $this->graph instanceof CodeGraph;
    }

    /**
     * Whether the entry is broken rather than merely out of date.
     *
     * A stale entry is the ordinary case and the next run replaces it. A corrupt one is a fact about
     * the file, and a reader told "stale" would wait for a rebuild that cannot help.
     */
    public function corrupt(): bool
    {
        return $this->refusal !== null
            && ! in_array($this->refusal, ['no-cache-entry', 'fingerprint-mismatch'], strict: true);
    }
}
