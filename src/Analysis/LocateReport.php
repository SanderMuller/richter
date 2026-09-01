<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

use SanderMuller\Richter\Graph\CodeGraph;

/**
 * Renders a {@see SymbolLocator} result as prose and as markdown.
 *
 * It lives beside {@see ImpactFormatter} and {@see MarkdownFormatter} rather than inside them
 * because `locate`'s rendering is all branching and almost no shared vocabulary: two lanes, two miss
 * leads, and four optional fields that each drop their own segment. Folded into either formatter it
 * pushed the class past the cognitive-complexity budget without sharing a single helper with the
 * impact reports. Those two classes keep the public `locate()` entry points, so every surface still
 * reaches its output through the formatter it reaches every other output through.
 *
 * The two renderings must agree. A reader who runs the command and a reviewer who reads the pasted
 * markdown are looking at the same lookup, so the segments are decided here once.
 *
 * @internal one report's rendering, shared by the two formatters; not public package API
 *
 * @phpstan-import-type LocateResult from SymbolLocator
 * @phpstan-import-type LocateMatch from SymbolLocator
 */
final class LocateReport
{
    /** @param  LocateResult  $result */
    public static function text(array $result): string
    {
        if ($result['matches'] === []) {
            return implode("\n", $result['by'] === 'file' ? self::fileMissText($result) : self::symbolMissText($result));
        }

        $subject = $result['by'] === 'file' ? "defined in \"{$result['query']}\"" : "matching \"{$result['query']}\"";
        $lines = [
            $result['total'] . ' node(s) ' . $subject . ':',
            ...array_map(self::textLine(...), $result['matches']),
        ];

        if ($result['bounded']) {
            $lines[] = '  … and ' . self::held($result) . ' more; raise the limit to see them.';
        }

        return implode("\n", $lines);
    }

    /** @param  LocateResult  $result */
    public static function markdown(array $result): string
    {
        // Both lanes escape. A query is CALLER input, not a repo-derived identifier, so a symbol
        // can carry a backtick just as a path can — and an unescaped one closes the span early.
        $subject = MarkdownFormatter::pathCell($result['query']);
        $lines = ["## Richter locate: {$subject}", ''];

        if ($result['matches'] === []) {
            return implode("\n", [...$lines, ...self::missMarkdown($result)]);
        }

        $lines[] = $result['total'] . ' node(s) ' . ($result['by'] === 'file' ? 'defined here' : 'matched') . ':';
        $lines[] = '';

        foreach ($result['matches'] as $match) {
            $lines[] = '- ' . self::markdownLine($match);
        }

        if ($result['bounded']) {
            $lines[] = '';
            $lines[] = '_… and ' . self::held($result) . ' more; raise the limit to see them._';
        }

        return implode("\n", $lines);
    }

    /**
     * The symbol lane goes through the SHARED diagnostic — the same lead `impact` and `trace` give,
     * so one typo reads identically wherever it is made.
     *
     * @param  LocateResult  $result
     * @return list<string>
     */
    private static function symbolMissText(array $result): array
    {
        return [
            "No graph nodes matched \"{$result['query']}\"."
                . ImpactFormatter::missDiagnostic($result['suggestions'] ?? [], $result['graphNodeCount'] ?? null),
        ];
    }

    /**
     * The file lane cannot use it: that helper says "Nearest graph nodes" and "none share an
     * identifier with it", and both are false statements about a path.
     *
     * The no-lead branch is deliberately ONE message for two causes. A path the graph knows nothing
     * about and a file whose nodes carry no edge are the same absence in the file index, and
     * claiming to tell them apart would need the index {@see CodeGraph}
     * refuses to build.
     *
     * @param  LocateResult  $result
     * @return list<string>
     */
    private static function fileMissText(array $result): array
    {
        $lines = ["No graph nodes are defined in \"{$result['query']}\"."];
        $suggestion = self::lead($result);

        if ($suggestion !== null) {
            $lines[] = "The graph knows {$suggestion}, which has the same file name.";

            return $lines;
        }

        $lines[] = 'The graph pins nodes to ' . ($result['graphFileCount'] ?? 0) . ' file(s), and none of them is that one. It lists a file only when the file defines a node that carries an edge, so an unknown path and a file whose nodes carry no edge look the same here.';
        $lines[] = 'A path is matched exactly as the graph recorded it, and then again after stripping a leading "./" and the project root. Repeated separators and ".." segments are not resolved.';

        return $lines;
    }

    /**
     * @param  LocateResult  $result
     * @return list<string>
     */
    private static function missMarkdown(array $result): array
    {
        if ($result['by'] === 'file') {
            $suggestion = self::lead($result);

            return [$suggestion === null
                ? '_No graph nodes are defined there. The graph pins nodes to ' . ($result['graphFileCount'] ?? 0) . ' file(s), and none of them is that one — it lists a file only when the file defines a node that carries an edge._'
                : '_No graph nodes are defined there. The graph knows ' . MarkdownFormatter::pathCell($suggestion) . ', which has the same file name._'];
        }

        $suggestions = $result['suggestions'] ?? [];

        if ($suggestions !== []) {
            return ['_No graph nodes matched. Nearest: `' . implode('`, `', $suggestions) . '`._'];
        }

        return ['_No graph nodes matched. Scanned ' . ($result['graphNodeCount'] ?? 0) . ' graph nodes; none share an identifier with it._'];
    }

    /**
     * Every field but `node` is optional, so each absent one drops its own segment rather than
     * rendering a blank. `kind` is set off from the id because it repeats the id's own prefix — a
     * label beside it, never a substitute for part of it. The id prints verbatim so the reader can
     * pass it straight to `impact` or `trace`.
     *
     * @param  LocateMatch  $match
     */
    private static function textLine(array $match): string
    {
        $label = isset($match['kind']) ? "[{$match['kind']}] " : '';
        $file = $match['file'] ?? null;

        if ($file === null) {
            return "  {$label}{$match['node']} — location unknown";
        }

        return "  {$label}{$match['node']} — {$file}" . (isset($match['line']) ? ":{$match['line']}" : '');
    }

    /**
     * The same segments, escaped. A file path goes through {@see MarkdownFormatter::pathCell()} because
     * it can legally carry a backtick or a pipe; a node id is identifier-shaped and cannot.
     *
     * @param  LocateMatch  $match
     */
    private static function markdownLine(array $match): string
    {
        $label = isset($match['kind']) ? "**{$match['kind']}** " : '';
        $file = $match['file'] ?? null;

        if ($file === null) {
            return "{$label}`{$match['node']}` — _location unknown_";
        }

        return "{$label}`{$match['node']}` — " . MarkdownFormatter::pathCell($file . (isset($match['line']) ? ":{$match['line']}" : ''));
    }

    /** @param  LocateResult  $result */
    private static function held(array $result): int
    {
        return $result['total'] - count($result['matches']);
    }

    /**
     * The file lane's single suggestion, when there is one. Typed as a list of paths, so the first
     * entry is the whole lead.
     *
     * @param  LocateResult  $result
     */
    private static function lead(array $result): ?string
    {
        return ($result['suggestions'] ?? [])[0] ?? null;
    }
}
