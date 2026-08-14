<?php declare(strict_types=1);

namespace SanderMuller\Richter\Support;

use SanderMuller\Richter\Graph\MergeBase;

/**
 * Which files a scoped Brain rebuild may re-trace, or why it may not run at all.
 *
 * The reason is the whole point of this type. {@see ScopedRebuild} has six ways to refuse and
 * {@see MergeBase} another five, and while all eleven collapsed into the same `full` label there was
 * no way to tell "no cache entry yet" from "the entry is there and one precondition fails" — the two
 * demand opposite responses, and a report of "never scoped" could not separate them from outside the
 * process.
 *
 * `$detail` is a full sentence naming the *specific* input that refused: which non-file input
 * differs, which path sits outside `app/`, which changed file the previous graph attributes nothing
 * to. A slug alone moves the guessing one level down instead of ending it.
 *
 * @internal
 */
final readonly class ScopedRebuildDecision
{
    /** @param  list<string>|null  $files */
    private function __construct(
        public ?array $files = null,
        public ?string $reason = null,
        public ?string $detail = null,
    ) {}

    /** @param  non-empty-list<string>  $files */
    public static function scoped(array $files): self
    {
        return new self(files: $files);
    }

    public static function refused(string $reason, ?string $detail = null): self
    {
        return new self(reason: $reason, detail: $detail);
    }
}
