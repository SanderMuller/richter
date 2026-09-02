<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

/**
 * Why each file ended up in — or out of — an `affected-tests` selection, recorded by
 * {@see AffectedTests::select()} as it selects rather than reconstructed afterwards.
 *
 * Recorded rather than recomputed on purpose. A second pass that re-derived "which axis would have
 * matched this file" is a second implementation of the selection rule, and two implementations of
 * one rule drift — this codebase has paid for that once already, which is why
 * {@see EntryPointKeepSet} owns the entry-point ordering outright.
 *
 * It never enters the selection document. Every document field reaches MCP structured content
 * wholesale, so a key that rode along would have to be stripped by each consumer — the shape
 * `untrackedFiles` already works around.
 *
 * @internal
 */
final class SelectionProvenance
{
    /** A file the diff itself touched. Needs no graph reasoning, and gets none. */
    public const string AXIS_CHANGED_FILE = 'changed-file';

    /** A file referencing an entry point the change reaches. */
    public const string AXIS_ENTRY_POINT = 'entry-point';

    /** A file importing a changed class, or one that reaches the change. */
    public const string AXIS_IMPORT = 'import';

    /** @var array<string, list<array{axis: string, node?: string, class?: string, origin?: string}>> */
    private array $reasons = [];

    /** @var array<string, string> file => the configured glob that removed it */
    private array $excluded = [];

    private int $entryPointsConsidered = 0;

    private int $classesConsidered = 0;

    public function changedFile(string $file): void
    {
        $this->add($file, ['axis' => self::AXIS_CHANGED_FILE]);
    }

    /** @param  list<string>  $files */
    public function referencingEntryPoint(array $files, string $node): void
    {
        foreach ($files as $file) {
            $this->add($file, ['axis' => self::AXIS_ENTRY_POINT, 'node' => $node]);
        }
    }

    /**
     * @param  list<string>  $files
     * @param  'changed'|'caller'  $origin
     */
    public function importing(array $files, string $class, string $origin): void
    {
        foreach ($files as $file) {
            $this->add($file, ['axis' => self::AXIS_IMPORT, 'class' => $class, 'origin' => $origin]);
        }
    }

    /** @param  array<string, string>  $excluded */
    public function excludedBy(array $excluded): void
    {
        $this->excluded = [...$this->excluded, ...$excluded];
    }

    public function considered(int $entryPoints, int $classes): void
    {
        $this->entryPointsConsidered = $entryPoints;
        $this->classesConsidered = $classes;
    }

    /**
     * The answer for one queried path. Sparse: a key that does not apply is absent, never null.
     *
     * `notSelected` carries the whole set of answers this can give — `not-a-test-file`, `excluded`,
     * `no-axis-matched`. Anything past those would be a guess, and a diagnostic that guesses is
     * worse than one that stops.
     *
     * @return array{test: string, selected: bool, determinable: bool, reasons?: list<array{axis: string, node?: string, class?: string, origin?: string}>, notSelected?: string, excludedBy?: string, entryPointsConsidered: int, classesConsidered: int}
     */
    public function explain(string $query, bool $determinable): array
    {
        $file = self::normalise($query);
        $reasons = $this->reasons[$file] ?? [];
        $glob = $this->excluded[$file] ?? null;

        $document = ['test' => $file, 'selected' => $reasons !== [] && $glob === null, 'determinable' => $determinable];

        if ($reasons !== []) {
            // Listed even when the file was excluded: a caller who configured the glob wants to see
            // what the file nearly qualified on, not just that a glob won.
            $document['reasons'] = $reasons;
        }

        if ($glob !== null) {
            $document['notSelected'] = 'excluded';
            $document['excludedBy'] = $glob;
        } elseif ($reasons === []) {
            $document['notSelected'] = $this->isSelectable($file) ? 'no-axis-matched' : 'not-a-test-file';
        }

        return $document + ['entryPointsConsidered' => $this->entryPointsConsidered, 'classesConsidered' => $this->classesConsidered];
    }

    /**
     * Whether any axis could ever have named this path — a helper or a fixture gets a different
     * answer from a test nothing matched, which is a different problem for the reader.
     *
     * A PHP file qualifies only under {@see TestReferenceIndex::runnableOnly()}'s rule AND under
     * `tests/`, because that is the only tree the index is built from: a `src/Support/HelperTest.php`
     * is conventionally named and still unreachable by every axis. A frontend spec is matched on
     * {@see FrontendTestIndex}'s patterns alone, since specs live outside `tests/` by convention.
     */
    private function isSelectable(string $file): bool
    {
        if (FrontendTestIndex::isSpecFile($file)) {
            return true;
        }

        return str_starts_with($file, 'tests/') && TestReferenceIndex::runnableOnly([$file]) !== [];
    }

    /**
     * A queried path as the selection records it: forward slashes and no `./` prefix. Without this a
     * Windows caller pasting a path from their shell never matches, since every recorded path comes
     * from a git diff or from a normalised scan.
     */
    public static function normalise(string $path): string
    {
        $path = str_replace('\\', '/', trim($path));

        // Exactly `./`, repeated — not `ltrim($path, './')`, which is a character set and would eat
        // the leading dot of a legitimately dot-prefixed directory.
        while (str_starts_with($path, './')) {
            $path = substr($path, 2);
        }

        return $path;
    }

    /** @param  array{axis: string, node?: string, class?: string, origin?: string}  $reason */
    private function add(string $file, array $reason): void
    {
        $existing = $this->reasons[$file] ?? [];

        // A file reached twice through the same class or node is one reason, not two — the entry
        // point loop walks registry surfaces as well as plain ones and can revisit a node.
        if (! in_array($reason, $existing, strict: true)) {
            $existing[] = $reason;
        }

        $this->reasons[$file] = $existing;
    }
}
