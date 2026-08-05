<?php declare(strict_types=1);

namespace SanderMuller\Richter\Support;

use InvalidArgumentException;
use JsonException;

/**
 * The application's root namespace — the prefix every `app/`-tree class carries. `App\` is Laravel's
 * skeleton default, but nothing in the framework enforces it: an app may map any PSR-4 root to
 * `app/`, and one that does used to read as "no app classes at all" here, because path → FQCN, the
 * FQCN → path inverse, and every "is this an app class?" gate hardcoded the literal.
 *
 * Resolution order, most explicit first:
 *  1. `richter.root_namespace` — the escape hatch for a layout the derivation can't read.
 *  2. The PSR-4 entry in the project's `composer.json` whose target is `app/`. `App\` wins when
 *     present so a partially-migrated codebase (both `App\` and a new root mapped to `app/`) keeps
 *     tracing the half it traced before; a single non-`App\` root is used as-is; two-plus
 *     non-`App\` candidates are ambiguous and fall through.
 *  3. `App\`.
 *
 * Memoised per (project root, configured override): every changed file and every graph node runs
 * through a gate here, so re-reading `composer.json` per call would be pure overhead. Composer's
 * autoload map cannot change mid-run without the process being restarted; {@see flush()} exists for
 * tests, which do rewrite it.
 */
final class AppNamespace
{
    /** Laravel's skeleton default — the fallback, and the value on every conventional app. */
    public const string DEFAULT_ROOT = 'App\\';

    private static ?string $memoized = null;

    private static ?string $memoizedKey = null;

    /** The root namespace with a single trailing backslash, e.g. `App\` or `Acme\`. */
    public static function root(): string
    {
        $configured = RichterConfig::rootNamespace();
        $projectRoot = base_path();
        $key = $projectRoot . '|' . ($configured ?? '');

        if (self::$memoizedKey === $key && self::$memoized !== null) {
            return self::$memoized;
        }

        self::$memoizedKey = $key;

        return self::$memoized = $configured ?? self::derive($projectRoot) ?? self::DEFAULT_ROOT;
    }

    /** The root namespace prefixed onto a namespace-relative fragment: `qualify('Models\\')` → `App\Models\`. */
    public static function qualify(string $relative): string
    {
        return self::root() . ltrim($relative, '\\');
    }

    /**
     * Whether the id is a bare app-class node — `App\Models\Post`, not `App\Models\Post::save`,
     * `route::…`, or a vendor class. The gate in front of every "derive this class's file from its
     * FQCN" step, and the one that keeps a namespaced walk hop apart from an entry-point node id.
     */
    public static function isAppClass(string $node): bool
    {
        return preg_match('/^' . self::quotedRoot() . '[\w\\\\]+$/', $node) === 1;
    }

    /**
     * The declaring class of a member node (`App\Models\Post::save` → `App\Models\Post`), or null
     * when the id is not an app-class member node.
     */
    public static function declaringClassOf(string $node): ?string
    {
        if (preg_match('/^(' . self::quotedRoot() . '[\w\\\\]+)::\w+$/', $node, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    public static function isInApp(string $fqcn): bool
    {
        return str_starts_with(ltrim($fqcn, '\\'), self::root());
    }

    /**
     * The `app/`-relative file path an app FQCN maps to, PSR-4 style, without the `.php` extension:
     * `App\Jobs\SendMail` → `Jobs/SendMail`. Callers prepend `app/` (or `{$projectRoot}/app/`) and
     * the extension themselves. A class outside the root namespace keeps all its segments — the
     * resulting path won't exist, which every caller already handles (they existence-check), and that
     * beats silently slicing the head off a vendor FQCN.
     */
    public static function relativePath(string $fqcn): string
    {
        $fqcn = ltrim($fqcn, '\\');

        if (self::isInApp($fqcn)) {
            $fqcn = substr($fqcn, strlen(self::root()));
        }

        return str_replace('\\', '/', $fqcn);
    }

    /** `preg_quote`d root for embedding in a pattern — the backslash separator needs escaping. */
    public static function quotedRoot(): string
    {
        return preg_quote(self::root(), '/');
    }

    /**
     * The PSR-4 roots the project's `composer.json` maps to `app/`, verbatim. Empty when there is no
     * readable `composer.json` or no entry targets `app/` — which is also when the effective root is
     * a guess rather than a reading, so this doubles as the input to {@see unmatchedRootNote()}.
     *
     * @return list<string>
     */
    public static function psr4AppRoots(?string $projectRoot = null): array
    {
        $path = ($projectRoot ?? base_path()) . '/composer.json';

        if (! is_file($path)) {
            return [];
        }

        $contents = @file_get_contents($path);

        if ($contents === false) {
            return [];
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($contents, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        if (! is_array($decoded)) {
            return [];
        }

        $autoload = $decoded['autoload'] ?? null;

        if (! is_array($autoload)) {
            return [];
        }

        $psr4 = $autoload['psr-4'] ?? null;

        if (! is_array($psr4)) {
            return [];
        }

        $roots = [];

        /** @var mixed $targets */
        foreach ($psr4 as $prefix => $targets) {
            if (! is_string($prefix)) {
                continue;
            }

            if ($prefix === '') {
                continue;
            }

            foreach (is_array($targets) ? $targets : [$targets] as $target) {
                if (! is_string($target)) {
                    continue;
                }

                if (! self::targetsAppDirectory($target)) {
                    continue;
                }

                // A prefix this can't read is skipped, not fatal: `composer.json` belongs to the host
                // app, and an unusable entry there must degrade to the fallback root, never throw
                // out of a report. The configured override is the one that validates loudly.
                $normalized = self::isValidPrefix($prefix) ? self::normalize($prefix) : null;

                if ($normalized !== null) {
                    $roots[] = $normalized;
                }

                continue 2;
            }
        }

        return $roots;
    }

    /**
     * An advisory note about the root namespace in use, or null when it needs no comment. Two cases
     * earn one, both of which otherwise show up only as an inexplicably thin report:
     *
     *  - the effective root matches no PSR-4 root the project maps to `app/` — richter is looking
     *    under a namespace no class in the app carries, so it can only under-report;
     *  - `composer.json` maps `app/` under two-plus roots, so the one traced covers part of the tree.
     *
     * Null when `composer.json` yields no `app/` mapping at all: there is nothing to corroborate the
     * root against, and a note would fire on every project without a readable one.
     */
    public static function unmatchedRootNote(): ?string
    {
        $roots = self::psr4AppRoots();

        if ($roots === []) {
            return null;
        }

        $quoted = implode(', ', array_map(static fn (string $root): string => '"' . $root . '"', $roots));

        if (! in_array(self::root(), $roots, strict: true)) {
            return sprintf(
                'Note: richter traced the root namespace "%s", which composer.json does not map to app/ (it maps %s). '
                . 'Results will under-report — set richter.root_namespace to the right one.',
                self::root(),
                $quoted,
            );
        }

        if (count($roots) > 1) {
            return sprintf(
                'Note: composer.json maps app/ under %d root namespaces (%s); richter traced "%s" and classes under the '
                . 'others are not analysed. Set richter.root_namespace to trace a different one.',
                count($roots),
                $quoted,
                self::root(),
            );
        }

        return null;
    }

    /** Drops the memoised root — for tests that rewrite `composer.json` or the config under one process. */
    public static function flush(): void
    {
        self::$memoized = null;
        self::$memoizedKey = null;
    }

    /** Null when `composer.json` names no unambiguous root for `app/` — the caller falls back. */
    private static function derive(string $projectRoot): ?string
    {
        $roots = self::psr4AppRoots($projectRoot);

        if (in_array(self::DEFAULT_ROOT, $roots, strict: true)) {
            return self::DEFAULT_ROOT;
        }

        return count($roots) === 1 ? $roots[0] : null;
    }

    private static function targetsAppDirectory(string $target): bool
    {
        return trim(str_replace('\\', '/', $target), '/') === 'app';
    }

    /** @throws InvalidArgumentException on a prefix that cannot be a namespace root */
    public static function normalize(string $prefix): string
    {
        if (! self::isValidPrefix($prefix)) {
            throw new InvalidArgumentException("\"{$prefix}\" is not a valid PHP namespace prefix.");
        }

        return trim($prefix, '\\') . '\\';
    }

    private static function isValidPrefix(string $prefix): bool
    {
        $trimmed = trim($prefix, '\\');

        return $trimmed !== '' && preg_match('/^[A-Za-z_]\w*(?:\\\\[A-Za-z_]\w*)*$/', $trimmed) === 1;
    }
}
