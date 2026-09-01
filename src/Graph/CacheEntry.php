<?php declare(strict_types=1);

namespace SanderMuller\Richter\Graph;

/**
 * The cache file, decoded once — or why there is nothing to decode.
 *
 * It exists because two readers wanted the same file. {@see GraphCache::read()} answers "is this
 * entry the graph for these inputs" and {@see GraphCache::mergeBase()} answers "can this entry be
 * built onto", and each used to open and decode `graph.json` for itself. A caller that wants both
 * answers — `richter:warm --check` wants the verdict from one and the reason from the other — could
 * therefore straddle a concurrent write and report a verdict from one entry beside a reason from
 * another: an answer describing a file that never existed.
 *
 * One decode, one observation, two questions asked of it.
 *
 * @internal the cache's own plumbing; not a consumer API
 */
final readonly class CacheEntry
{
    /** @param  array<string, mixed>|null  $data */
    private function __construct(
        public ?array $data = null,
        public ?string $refusal = null,
        public ?string $detail = null,
    ) {}

    /** @param  array<string, mixed>  $data */
    public static function of(array $data): self
    {
        return new self(data: $data);
    }

    /**
     * `no-cache-entry` is the ordinary first run and fixes itself on the next write.
     * `cache-unreadable` is a file that is there and does not decode, which repeats forever.
     */
    public static function refused(string $reason, string $detail): self
    {
        return new self(refusal: $reason, detail: $detail);
    }
}
