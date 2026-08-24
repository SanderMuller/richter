<?php declare(strict_types=1);

namespace SanderMuller\Richter\Console\Concerns;

/**
 * Writes a document to the `OutputInterface` UNDERNEATH the `OutputStyle`, where a host package that
 * rebinds `OutputStyle` cannot rewrite it.
 *
 * `laravel/pao` is the case this exists for. It swaps in a writer whose cleaner strips ANSI, control
 * characters, box-drawing, the arrow and warning glyphs, and collapses whitespace runs and blank lines —
 * deliberately, to save an agent tokens, and only when an agent is driving. On richter's prose output that
 * is exactly the right trade, and those sites keep using {@see line()}. On a report whose whitespace and
 * symbols carry meaning it is destructive: a markdown fold loses the blank line GitHub needs to parse it,
 * every nested list item loses the second space that makes it a child, and every `→` and `⚠` is deleted.
 *
 * So this is for documents only, judged per surface by whether cleaning changes MEANING — never by whether
 * the format merely looks structured. `--json` is the instructive case: cleaning leaves a measured payload
 * decoding identically, because only whitespace inside string values counts, yet it is written through here
 * anyway. Error documents embed exception text verbatim and payloads embed repo-derived paths and findings
 * quoting source, none of which richter controls, so losslessness cannot be guaranteed for the one surface
 * that is a machine contract.
 *
 * With no such rebind in place — every consumer today — this reaches the same stream `line()` would have,
 * so nothing changes. Verbosity is unaffected: the inner output honours `--quiet` exactly as `line()` does,
 * and tag-shaped content (`<details>`, `<summary>`) passes through unchanged.
 *
 * @internal
 */
trait WritesDocuments
{
    protected function writeDocument(string $document): void
    {
        $this->getOutput()->getOutput()->writeln($document);
    }
}
