<?php declare(strict_types=1);

namespace SanderMuller\Richter\Graph;

/**
 * What a deliberate warm left on disk.
 *
 * {@see GraphCache::write()} swallows its failures on purpose: for every other caller a failed write
 * only costs the next run a rebuild, and a cache must never break a report or pollute its stdout. A
 * warm is the one caller for which that silence is wrong — writing the entry IS the job, so
 * {@see GraphCache::warm()} re-reads the file and reports what it found rather than trusting that
 * nothing was thrown.
 *
 * `written` is a claim about THIS call's observation: the entry on disk revived under the
 * fingerprint this call computed. It is not a promise that the next run will hit, and nothing could
 * make it one — a tree that changes during the build moves the fingerprint out from under any
 * answer, and a second sweep to notice that would double the call's dominant cost while still
 * racing. A deploy warming a tree that is changing under it has a problem this command cannot fix.
 *
 * `written`, `built` and `repaired` are three different questions, and a deploy step wants all of
 * them. `written` is "is there a usable entry here now", true after a bake, after a repair, and
 * after a hit that needed neither. `built` is "did a BUILD run" — false for a hit, and false for a
 * repair, because rewriting a graph already in memory is not building one. `repaired` is that third
 * case: the entry was missing or unusable while this process held the graph for it, so the call
 * wrote what it had rather than reporting a failure it was carrying the fix for.
 *
 * @internal the warm command's plumbing, shaped by it rather than by consumers
 */
final readonly class WarmResult
{
    private function __construct(
        public string $fingerprint,
        public int $nodeCount,
        public string $file,
        public ?int $bytes,
        public bool $written,
        public bool $built,
        public bool $repaired,
        public float $seconds,
    ) {}

    /** An entry that revived under this call's fingerprint after the call. */
    public static function stored(string $fingerprint, int $nodeCount, string $file, ?int $bytes, bool $built, bool $repaired, float $seconds): self
    {
        return new self($fingerprint, $nodeCount, $file, $bytes, written: true, built: $built, repaired: $repaired, seconds: $seconds);
    }

    /**
     * No usable entry landed — the one outcome {@see GraphCache::write()}'s deliberate silence would
     * otherwise hide.
     *
     * `$built` and `$repaired` are carried through rather than assumed: a failed REPAIR ran no
     * builder, and hard-coding `built: true` here would have the report claim a build that never
     * happened, in the one response where the reader is already being told something went wrong.
     */
    public static function unwritten(string $fingerprint, int $nodeCount, string $file, bool $built, bool $repaired, float $seconds): self
    {
        return new self($fingerprint, $nodeCount, $file, bytes: null, written: false, built: $built, repaired: $repaired, seconds: $seconds);
    }
}
