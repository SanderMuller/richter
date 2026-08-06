<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

/**
 * The two presentation primitives {@see HtmlFormatter} and {@see BlastDiagram} both need. Escaping
 * lives here rather than in each renderer so there is exactly one of it — a second copy is how one
 * surface ends up with an exception list the other does not have.
 *
 * @internal
 */
final class Html
{
    /**
     * Every value either renderer interpolates is project-derived and untrusted: diff paths, node
     * ids carrying route URIs, finding and security text straight out of the analysed source.
     */
    public static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Node ids are internal addresses — `route::GET::/checkout`, `view::mail.welcome`. Show the form
     * a reader recognises. Only the HTML surface rewrites them wholesale: JSON prints ids verbatim by
     * contract, text and markdown trim nothing but the command signature ({@see NodeLabel}), and the
     * diagram still carries the raw id for anyone who needs it.
     */
    public static function nodeLabel(string $node): string
    {
        return self::humanised($node) ?? $node;
    }

    /**
     * Null when the id does not shorten, or when shortening it would leave nothing — a partial id
     * like a bare `view::` must keep rendering as itself rather than as an empty label, which is the
     * anonymous dot this surface exists to avoid.
     */
    private static function humanised(string $node): ?string
    {
        if (str_starts_with($node, 'route::')) {
            [$method, $uri] = array_pad(explode('::', substr($node, 7), 2), 2, '');

            return match (true) {
                $method === '' => null,
                $uri === '' => $method,
                default => "{$method} {$uri}",
            };
        }

        // Only a command node carries a signature; the rest keep whatever follows the prefix, so a
        // view or model whose name contains a space is not silently truncated at it.
        if (str_starts_with($node, 'command::')) {
            return NodeLabel::commandName($node);
        }

        foreach (['schedule::', 'view::', 'model::'] as $prefix) {
            if (str_starts_with($node, $prefix)) {
                $name = substr($node, strlen($prefix));

                return $name === '' ? null : $name;
            }
        }

        return null;
    }

    /** @param  array{file: string, line?: int}|null  $location */
    public static function locationText(?array $location): string
    {
        if ($location === null) {
            return '';
        }

        return $location['file'] . (isset($location['line']) ? ":{$location['line']}" : '');
    }

    /**
     * A `.loc` span for a location, wrapped in an editor link when one is configured.
     *
     * @param  array{file: string, line?: int}|null  $location
     */
    public static function location(?EditorLink $editor, ?array $location): string
    {
        if ($location === null) {
            return '';
        }

        $span = '<span class="loc">' . self::e(self::locationText($location)) . '</span>';

        return self::link($editor, $location['file'], $location['line'] ?? null, $span);
    }

    /**
     * Wrap already-escaped HTML in an editor deep link, or return it unchanged when no editor is
     * configured — the single place the report decides whether a file reference is clickable.
     *
     * `self::e($url)` here is only the HTML-attribute layer: it keeps the URL from breaking out of
     * `href="…"`. The URL's own safety — no `javascript:` scheme, every path metacharacter encoded —
     * is established upstream in {@see EditorLink::url()} by a fixed scheme allow-list plus
     * `rawurlencode`. `e()` is not a substitute for that: HTML-escaping a URL does not make the URL
     * itself safe.
     */
    public static function link(?EditorLink $editor, string $file, ?int $line, string $inner): string
    {
        $url = $editor?->url($file, $line);

        return $url === null ? $inner : '<a class="ref" href="' . self::e($url) . '">' . $inner . '</a>';
    }
}
