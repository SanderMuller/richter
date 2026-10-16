<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Support;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use SanderMuller\Richter\Tests\TestCase;

/**
 * `Process::fake()` with the three git calls most fakes forget answered first.
 *
 * Patterns match in order, first hit wins. A `*show*` pattern for `git show` therefore also answers
 * `git rev-parse --show-prefix`, and hands file contents back as a path prefix. A fake with no
 * `*status*` or `*ls-tree*` pattern let `git status --porcelain` and the sibling-read lane's base-tree
 * listing run against whatever repository hosts the suite. The defaults go in front: a test that
 * passes its own answer for one of them keeps that answer, in that front position.
 *
 * The listing defaults to an empty directory, which is what the lane reads a failed listing as.
 *
 * {@see TestCase} turns on stray-process prevention, so any other unanswered command now fails the
 * test instead of running.
 *
 * @internal
 */
trait FakesGit
{
    /** @param  array<string, ProcessResult|string|array<array-key, mixed>|callable>  $patterns */
    protected function fakeGit(array $patterns): void
    {
        $defaults = [
            '*show-prefix*' => Process::result(),
            '*status*' => Process::result(''),
            '*ls-tree*' => Process::result(''),
        ];

        foreach (array_keys($defaults) as $pattern) {
            if (array_key_exists($pattern, $patterns)) {
                $defaults[$pattern] = $patterns[$pattern];
                unset($patterns[$pattern]);
            }
        }

        Process::fake($defaults + $patterns);
    }
}
