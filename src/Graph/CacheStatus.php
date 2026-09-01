<?php declare(strict_types=1);

namespace SanderMuller\Richter\Graph;

/**
 * Whether a run would hit the stored entry, and — when it would not — which input differs.
 *
 * A miss is otherwise invisible. The cache is failure-tolerant by design, so a hosted process whose
 * baked entry no longer matches rebuilds the graph on every cold container, silently, with nothing
 * in the logs to say so. This is what makes that state observable, and it names the cause rather
 * than reporting that there is one: `php (8.5.8 → 8.5.9)` is the whole answer to why a deploy's
 * prebuilt cache stopped being used.
 *
 * Both fingerprints ride along because neither is recoverable from outside {@see GraphCache} —
 * `hashRecord()` is private — and a mismatch is exactly the case a reader needs to see.
 *
 * @internal the warm command's plumbing, shaped by it rather than by consumers
 */
final readonly class CacheStatus
{
    /**
     * @param  bool  $matches  whether a run would hit this entry — decided by the real read path, never by the record diff
     * @param  string  $fingerprint  what this tree hashes to now
     * @param  string|null  $storedFingerprint  what the entry carries, when there is a readable entry
     * @param  string|null  $reason  the miss cause, absent on a match
     * @param  string|null  $detail  a full sentence naming the specific differing input
     * @param  bool  $corrupt  whether the entry is broken rather than merely out of date
     */
    private function __construct(
        public bool $matches,
        public string $fingerprint,
        public ?string $storedFingerprint,
        public string $file,
        public ?int $bytes,
        public ?string $reason = null,
        public ?string $detail = null,
        public bool $corrupt = false,
    ) {}

    /** A run would hit this entry — decided by the real read path, never by the record diff. */
    public static function matched(string $fingerprint, ?string $storedFingerprint, string $file, ?int $bytes): self
    {
        return new self(matches: true, fingerprint: $fingerprint, storedFingerprint: $storedFingerprint, file: $file, bytes: $bytes);
    }

    /**
     * A run would miss, and this is why. `$corrupt` separates an entry that is BROKEN from one that
     * is merely out of date: a rebuild fixes the second and cannot fix the first, so a reader told
     * the wrong one waits for the wrong thing.
     */
    public static function missed(string $fingerprint, ?string $storedFingerprint, string $file, ?int $bytes, ?string $reason, ?string $detail, bool $corrupt): self
    {
        return new self(
            matches: false,
            fingerprint: $fingerprint,
            storedFingerprint: $storedFingerprint,
            file: $file,
            bytes: $bytes,
            reason: $reason,
            detail: $detail,
            corrupt: $corrupt,
        );
    }
}
