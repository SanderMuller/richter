<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

/**
 * Turns a report's relative `file:line` into an editor deep link — `phpstorm://open?file=…&line=…`
 * and friends — so a reviewer clicks straight to the code. The editor-name → URL-scheme map is the
 * one debugbar and Ignition use, and the configured name is read from the same env chain.
 *
 * A null or unrecognised editor yields no linker, so those cases stay plain text. A link embeds an
 * ABSOLUTE local path that only resolves on the machine that generated the report — worth turning
 * off (`richter.editor = null`) for a shared CI artifact.
 *
 * @internal
 */
final readonly class EditorLink
{
    /** Editor name → URL template with `{file}` (absolute, encoded) and `{line}` placeholders. */
    private const array SCHEMES = [
        'phpstorm' => 'phpstorm://open?file={file}&line={line}',
        'idea' => 'idea://open?file={file}&line={line}',
        'vscode' => 'vscode://file/{file}:{line}',
        'vscode-insiders' => 'vscode-insiders://file/{file}:{line}',
        'vscode-remote' => 'vscode://vscode-remote/{file}:{line}',
        'vscodium' => 'vscodium://file/{file}:{line}',
        'sublime' => 'subl://open?url=file://{file}&line={line}',
        'textmate' => 'txmt://open?url=file://{file}&line={line}',
        'emacs' => 'emacs://open?url=file://{file}&line={line}',
        'macvim' => 'mvim://open/?url=file://{file}&line={line}',
        'atom' => 'atom://core/open/file?filename={file}&line={line}',
        'nova' => 'nova://core/open/file?filename={file}&line={line}',
        'netbeans' => 'netbeans://open/?f={file}:{line}',
        'xdebug' => 'xdebug://{file}@{line}',
    ];

    private function __construct(private string $scheme, private string $basePath) {}

    /**
     * Null when no editor is configured, or when the configured name has no known scheme — an
     * unrecognised editor falls back to plain text rather than emitting a link that opens nothing.
     */
    public static function fromConfig(?string $editor, string $basePath): ?self
    {
        $editor = $editor === null ? '' : strtolower(trim($editor));
        $scheme = self::SCHEMES[$editor] ?? null;

        return $scheme === null ? null : new self($scheme, $basePath);
    }

    /** @param  string  $relativeFile  a project-relative path as it appears in the report */
    public function url(string $relativeFile, ?int $line): string
    {
        $absolute = str_replace('\\', '/', $this->basePath) . '/' . ltrim(str_replace('\\', '/', $relativeFile), '/');

        return strtr($this->scheme, [
            // Encode a space or `&` that would break the URL, but keep `/` and a Windows drive `:`
            // readable — every scheme above accepts them unencoded.
            '{file}' => str_replace(['%2F', '%3A'], ['/', ':'], rawurlencode($absolute)),
            '{line}' => (string) ($line ?? 1),
        ]);
    }
}
