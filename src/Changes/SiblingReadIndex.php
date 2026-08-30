<?php declare(strict_types=1);

namespace SanderMuller\Richter\Changes;

use Closure;
use Illuminate\Support\Facades\Process;
use SanderMuller\Richter\Support\AppNamespace;
use SanderMuller\Richter\Support\GitProjectPaths;

/**
 * The two source-side facts the sibling-read lane compares against, built where the trees are
 * reachable and carried to the analyzer, which cannot reach them.
 *
 * `ImpactAnalyzer` receives a graph and already-classified changes; it holds no ref and no source.
 * Every base-tree read and all merge-base knowledge lives in {@see ChangedSymbols}, so the evidence
 * index and the nullability index are built there and the checker consumes them without touching a
 * file.
 *
 * The two sides deliberately come from different trees. EVIDENCE is read from the BASE tree, because
 * the claim the lane makes is "the code that was already beside this resolves the value" — a fallback
 * the same diff introduces is not evidence about what was there. NULLABILITY is read from HEAD,
 * because the reported read is a head-side read and the question is whether the value can be absent
 * in the code being shipped.
 *
 * Memoized for one run and replaced on the next {@see ChangedSymbols::resolveWithScope()}, the same
 * discipline the model-table lane uses: the analyzer is reached through several commands and an MCP
 * tool, and threading a new return key through all of them would carry this to some callers and not
 * others.
 *
 * @internal
 */
final class SiblingReadIndex
{
    /** Properties whose documented `|null` records "not yet persisted", never an absent value. */
    private const array NEVER_ABSENT = ['id', 'created_at', 'updated_at', 'deleted_at'];

    /** A `|null` on anything else is about a relation or a cast object, which is a different question. */
    private const array SCALARS = ['string', 'int', 'integer', 'float', 'double', 'bool', 'boolean', 'array', 'mixed'];

    /** The run's index. Not readonly-class state: a `readonly` class cannot hold a static default. */
    private static ?self $current = null;

    /**
     * @param  array<string, array<string, list<string>>>  $evidence  `Fqcn->property` => style => site
     * @param  array<string, string>  $nullableScalars  `Fqcn->property` => the source that proved it
     */
    public function __construct(public readonly array $evidence, public readonly array $nullableScalars) {}

    public static function forRun(): self
    {
        return self::$current ?? new self([], []);
    }

    public static function remember(self $index): void
    {
        self::$current = $index;
    }

    public static function forget(): void
    {
        self::$current = null;
    }

    /**
     * @param  list<ChangedFileSymbols>  $changed
     * @param  Closure(string): list<string>  $listBaseDirectory  base-tree file paths in one directory
     * @param  Closure(string): (string|null)  $baseSource
     * @param  Closure(string): (string|null)  $headSource
     */
    public static function build(array $changed, Closure $listBaseDirectory, Closure $baseSource, Closure $headSource): self
    {
        $changedPaths = [];
        $receivers = [];

        foreach ($changed as $file) {
            $changedPaths[$file->file] = true;

            foreach (array_keys($file->siblingReads) as $key) {
                $receivers[explode('->', $key, 2)[0]] = true;
            }
        }

        $evidencePaths = self::evidencePaths($changedPaths, $receivers, $listBaseDirectory);
        $evidence = [];

        foreach (array_keys($evidencePaths) as $path) {
            $source = $baseSource($path);

            if ($source === null) {
                continue;       // absent from the base tree, or unreadable: silence, never a guess
            }

            foreach (SiblingReads::in($source) as $key => $byStyle) {
                foreach ($byStyle as $style => $sites) {
                    $evidence[$key][$style] = array_merge($evidence[$key][$style] ?? [], $sites);
                }
            }
        }

        return new self($evidence, self::nullableScalars($receivers, $headSource));
    }

    /**
     * The base-tree files whose reads count as evidence: every file in a directory the diff touched,
     * plus each receiver's own declaring class — measured, because without the declaring class a real
     * mismatch is found only when an unrelated file in the same directory happens to be in the commit.
     *
     * A file the DIFF CHANGED is still evidence, through its BASE version. That is the whole point of
     * reading this side from base: the fallback in a model this same commit also edits described the
     * convention the changed code was written against, and skipping it would drop the most common
     * shape — a feature that touches its model and an action beside it. What must not count is the
     * reported file's OWN earlier reads, and that exclusion belongs at comparison time, where the file
     * being reported on is known ({@see SiblingReadParity}).
     *
     * @param  array<string, true>  $changedPaths
     * @param  array<string, true>  $receivers
     * @param  Closure(string): list<string>  $listBaseDirectory
     * @return array<string, true>
     */
    private static function evidencePaths(array $changedPaths, array $receivers, Closure $listBaseDirectory): array
    {
        $paths = [];

        foreach (array_keys($changedPaths) as $path) {
            if (! str_starts_with($path, 'app/')) {
                continue;
            }

            foreach ($listBaseDirectory(dirname($path)) as $candidate) {
                if (str_ends_with($candidate, '.php')) {
                    $paths[$candidate] = true;
                }
            }
        }

        foreach (array_keys($receivers) as $fqcn) {
            $path = self::pathFor($fqcn);

            if ($path !== null) {
                $paths[$path] = true;
            }
        }

        return $paths;
    }

    /**
     * Which `Fqcn->property` is a nullable SCALAR in the HEAD tree, and what proved it.
     *
     * Both sources count, and they are not equal evidence. A `?string $x` is an author saying the
     * value can be absent. An `@property string|null $x` is usually generated from the schema — it was
     * the only source that fired at all on two production codebases, so dropping it silences the lane,
     * but read literally it reports relations, cast objects, keys and timestamps. Hence scalar-only,
     * and the finding names which source it used.
     *
     * @param  array<string, true>  $receivers
     * @param  Closure(string): (string|null)  $headSource
     * @return array<string, string>
     */
    private static function nullableScalars(array $receivers, Closure $headSource): array
    {
        $nullable = [];

        foreach (array_keys($receivers) as $fqcn) {
            $path = self::pathFor($fqcn);
            $source = $path === null ? null : $headSource($path);

            if ($source === null) {
                continue;
            }

            foreach (self::documentedTypes($source) as $property => [$type, $source_]) {
                if (in_array($property, self::NEVER_ABSENT, strict: true) || ! self::isNullableScalar($type)) {
                    continue;
                }

                $nullable[$fqcn . '->' . $property] = $source_;
            }
        }

        return $nullable;
    }

    /**
     * `property => [type, which source said so]` from `@property`/`@property-read` lines and typed
     * property declarations.
     *
     * The source travels WITH the type rather than being inferred from its shape. `@property ?string`
     * is valid PHPDoc, so reading the `?` back as "a declared type" would put a claim in the finding
     * that no declaration supports.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    private static function documentedTypes(string $source): array
    {
        $types = [];

        if (preg_match_all('/@property(?:-read)?\s+([^\s$]+)\s+\$(\w+)/', $source, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as $match) {
                $types[$match[2]] = [$match[1], 'docblock'];
            }
        }

        // A real declaration outranks a generated docblock line for the same name.
        if (preg_match_all('/(?:public|protected|private)\s+(?:readonly\s+)?(\?[\\\\\w]+)\s+\$(\w+)/', $source, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as $match) {
                $types[$match[2]] = [$match[1], 'declared type'];
            }
        }

        return $types;
    }

    /**
     * PHP writes nullability two ways and both count: the `?` shorthand of a declared type, and a
     * `null` member of a union, which is the form a generated `@property` block uses.
     *
     * Testing only for the union member is what a first version did, and it made the whole
     * declared-type source unreachable: `?string` has no `null` part, so every promoted constructor
     * property in an application read as non-nullable and the lane could report nothing outside the
     * models that carry docblocks. Found by a consumer asking whether the two sources were even
     * comparable — they were not, for this reason rather than for any rule about models.
     */
    private static function isNullableScalar(string $type): bool
    {
        $type = trim($type);
        $members = explode('|', $type);
        $nullable = str_starts_with($type, '?');

        $parts = [];

        foreach ($members as $member) {
            $member = strtolower(ltrim(trim($member), '?\\'));

            if ($member === 'null') {
                $nullable = true;

                continue;
            }

            $parts[] = $member;
        }

        if (! $nullable || $parts === []) {
            return false;
        }

        return array_all($parts, static fn (string $part): bool => in_array($part, self::SCALARS, strict: true));
    }

    /** The application path a class FQCN lives at, by the same convention {@see Fqcn::fromPath()} reverses. */
    private static function pathFor(string $fqcn): ?string
    {
        $root = AppNamespace::root();

        if ($root === '' || ! str_starts_with($fqcn, $root)) {
            return null;
        }

        return 'app/' . str_replace('\\', '/', substr($fqcn, strlen($root))) . '.php';
    }

    /**
     * The base tree's own PHP files in one directory, project-relative.
     *
     * `git show` reads one blob; a comparison set needs the listing beside it, and only the base tree
     * has the right one — a file the diff deletes or renames still described the convention the
     * changed code was written against, while a file the diff ADDS is not evidence about what was
     * already there and is absent here by construction.
     *
     * @return list<string>
     */
    public static function baseDirectoryListing(string $mergeBase, string $directory, string $prefix): array
    {
        if ($mergeBase === '') {
            return [];
        }

        $listing = Process::path(base_path())->run([
            'git', 'ls-tree', '--name-only', '--end-of-options', $mergeBase, GitProjectPaths::objectPath($prefix, $directory) . '/',
        ]);

        if (! $listing->successful()) {
            return [];
        }

        $paths = [];

        $lines = preg_split('/\r?\n/', trim($listing->output()));

        foreach ($lines === false ? [] : $lines as $path) {
            if ($path === '') {
                continue;
            }

            // Back to project-relative, the form every other path in this class carries.
            $paths[] = $prefix === '' ? $path : (str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path);
        }

        return $paths;
    }
}
