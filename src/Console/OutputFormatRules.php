<?php declare(strict_types=1);

namespace SanderMuller\Richter\Console;

/**
 * Which combinations of `--json`, `--markdown`, `--html` and `--open` are usable, and the message for
 * each that is not.
 *
 * Its own class because {@see DetectChangesCommand} has no headroom — it measured 81 against
 * phpstan.neon's class cognitive-complexity ceiling of 80 with these four rules inline. The split is
 * forced rather than stylistic, and it earns its keep: the rules are one subject, they are all
 * fail-closed for the same reason, and a report of the wrong FORMAT is the same class of quiet wrong
 * answer as a report of the wrong content.
 *
 * @internal
 */
final class OutputFormatRules
{
    /**
     * The first rule the given flags break, or null when they are usable.
     *
     * @param  string|null  $html  the `--html` value; null covers BOTH an absent flag and a bare
     *                             `--html`, which is why `$htmlRequested` is a separate parameter
     * @param  bool  $htmlRequested  whether the `--html` token was present on the command line at all
     */
    public static function firstError(bool $json, bool $markdown, ?string $html, bool $htmlRequested, bool $open): ?string
    {
        // With --json present the usage error honours the JSON contract: stdout stays one parseable
        // document.
        if ($json && $markdown) {
            return 'The --json and --markdown options are mutually exclusive.';
        }

        if ($html !== null && ($json || $markdown)) {
            return 'The --html option cannot be combined with --json or --markdown.';
        }

        // `--html=` gives an empty string. A BARE `--html` gives null, which the option bag cannot tell
        // from an absent flag — so without `$htmlRequested` it falls through to the TEXT report and
        // writes no file, silently: a reader who asked for HTML gets something else and no word about it.
        // Same fail-closed rule the `--fail-on` flags use, for the same reason.
        if ($htmlRequested && ($html === null || $html === '')) {
            return 'The --html option requires a path: --html=<path>.';
        }

        // --open without --html would silently do nothing; fail instead.
        if ($open && $html === null) {
            return 'The --open option requires --html=<path>.';
        }

        return null;
    }
}
