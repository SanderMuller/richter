<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

/**
 * The one place a `command::` node id is shortened for display.
 *
 * Brain builds the id as `command::{$signature}` in its own graph builder, so the whole
 * `$signature` — option definitions and their descriptions — is part of the address.
 * The id itself has to stay intact: `TestReferenceIndex::resolveCommand()` slices the signature back
 * out of it to resolve the command, and it is what a reader feeds to `richter:trace`. Only the
 * rendering shortens.
 *
 * Deliberately narrower than {@see Html::nodeLabel()}: this trims a command signature and nothing
 * else, so the text and markdown surfaces keep printing every other id verbatim.
 *
 * @internal
 */
final class NodeLabel
{
    /**
     * A `command::` node id with its signature dropped; every other id unchanged. The prefix stays —
     * text and markdown print ids as addresses a reader can feed back to `richter:trace`.
     */
    public static function display(string $node): string
    {
        $name = self::commandName($node);

        return $name === null ? $node : 'command::' . $name;
    }

    /**
     * The bare command name behind a `command::` id — null for any other id, and for one carrying no
     * name at all (`command::`, or a signature that opens with whitespace). Callers that render
     * without the prefix want that null: a bare prefix is an anonymous label, worse than the raw id.
     *
     * Splits on ANY whitespace, not on a space. A multi-line signature —
     *
     * ```php
     * protected $signature = 'reports:sync
     *     {--force : Skip the freshness check}';
     * ```
     *
     * — puts a newline inside the first space-delimited token, so splitting on `' '` yields a label
     * that still breaks across lines: the truncation reads as applied while the output it produces
     * is exactly the unwrapped one it exists to prevent.
     *
     * Not trimmed first, deliberately — unlike `TestReferenceIndex::resolveCommand()`, which trims
     * because it wants a lookup key. Trimming would promote the first option of a signature that
     * opens with whitespace (`command:: {opt}`) into a command name.
     */
    public static function commandName(string $node): ?string
    {
        if (! str_starts_with($node, 'command::')) {
            return null;
        }

        $name = preg_split('/\s/', substr($node, strlen('command::')), 2)[0] ?? '';

        return $name === '' ? null : $name;
    }
}
