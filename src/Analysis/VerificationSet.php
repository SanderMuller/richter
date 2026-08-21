<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

use SanderMuller\Richter\Changes\ChangedFileSymbols;

/**
 * What the risk level grades, and whether the diff has anything to grade at all.
 *
 * The set is the reached entry points, plus — for each changed class that reached NONE of them — the
 * class itself, whose verification question becomes "does a runnable test import this class". The
 * fallback is per class, not per diff: one changed class reaching routes says nothing about a sibling
 * in the same diff that reaches nothing, and each is graded its own way.
 *
 * It is deliberately NOT the report's entry-point list. A frontend file's routes and a self-listed
 * entry class join that list after the level's own set is frozen, and a service or builder class is
 * not an entry surface at all — printing one under that heading would change what the list means in
 * every format. This set exists for the ladder alone.
 *
 * Lives beside {@see ImpactAnalyzer} for the same reason {@see ParityFindings} does: the analyzer sits
 * at its class complexity ceiling, so a new concept gets its own file rather than four more branches.
 */
final readonly class VerificationSet
{
    /**
     * @param  list<string>  $reachedEntryPoints  the entry points the walk actually reached, frozen
     *   before the self-listed and frontend surfaces join the printed list
     * @param  list<ChangedFileSymbols>  $changed
     * @param  array<string, list<string>>  $perFileSeeds
     */
    public function __construct(
        private array $reachedEntryPoints,
        private array $changed,
        private array $perFileSeeds,
    ) {}

    /**
     * @param  callable(list<string>): int  $ownEntryPointCount  how many entry points one file's own
     *   seeds reach, supplied by the analyzer so the memoised walk is shared rather than repeated
     * @return list<string>
     */
    public function members(callable $ownEntryPointCount): array
    {
        $members = $this->reachedEntryPoints;

        foreach ($this->changed as $file) {
            if ($this->hasNoAnchor($file) || in_array($file->fqcn, $members, strict: true)) {
                continue;
            }

            if ($ownEntryPointCount($this->perFileSeeds[$file->file] ?? []) === 0) {
                $members[] = $file->fqcn;
            }
        }

        return array_values(array_unique($members));
    }

    /**
     * A NEW file has no anchor: a class nothing has called yet cannot break behaviour that already
     * runs, so it belongs to ladder step 0 rather than to a verification question. Additive and
     * cosmetic-only files are excluded for the same reason — they seed nothing on purpose. A file with
     * no class at all (a Blade view, a frontend file) has no anchor to grade.
     */
    private function hasNoAnchor(ChangedFileSymbols $file): bool
    {
        return $file->isNewFile || $file->fqcn === '' || $file->hasOnlyAdditiveOrCosmeticChanges();
    }

    /**
     * Whether the diff analyses code that ALREADY EXISTED — ladder step 0's question.
     *
     * A REAL MEMBER CHANGE counts even when it resolves to no graph node. Whether a change resolves is
     * a placement question, and failing to place a real change is exactly what step 2 exists to
     * report: a modified member on a class the graph never charted must read MEDIUM ("could not
     * place"), not LOW ("nothing to assess").
     *
     * A WALKED SEED counts too, which is how a changed Blade view qualifies — it has no members at
     * all, only its own `view::` node.
     *
     * A FRONTEND file counts as neither, and that is why this is not
     * `hasOnlyAdditiveOrCosmeticChanges()`: that method reports false for any file carrying direct
     * seeds, and a frontend file's seeds are entry-prefixed routes that never become walk seeds. Both
     * `ImpactAnalyzer::withFrontendEntryPoints()` and `config/richter.php` promise a frontend change
     * does not move the level, and this is where that promise is kept.
     *
     * A NEW file is excluded for the reason in {@see hasNoAnchor()}. When such a class does reach
     * entry points the walk places it, and the caller reads that from the reached set instead.
     */
    public function analysesExistingCode(): bool
    {
        return $this->reachedEntryPoints !== [] || array_any(
            $this->changed,
            fn (ChangedFileSymbols $file): bool => ! $file->isNewFile
                && ! $file->cosmeticOnly
                && ($file->resolvableMembers() !== [] || $file->needsCoarseSeed() || ($this->perFileSeeds[$file->file] ?? []) !== []),
        );
    }
}
