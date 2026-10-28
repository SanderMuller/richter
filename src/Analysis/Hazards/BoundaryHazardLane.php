<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis\Hazards;

use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use SanderMuller\Richter\Analysis\Hazard;
use SanderMuller\Richter\Analysis\ValidationRules;

/**
 * The two edges of the application where a change lets something through that did not get through
 * before: input that is no longer validated, and a queued payload whose in-flight jobs were
 * serialised against the old constructor.
 *
 * A field removed from a rules array entirely is the parity lane's business (§4.6) and is not
 * repeated here — this lane only looks at a field BOTH sides still validate, and only at the
 * constraints it lost.
 *
 * The queued half is decided from the parsed AST and the FQCN string, never by reflection. The base
 * side of a diff is a git blob: the base class is not autoloadable, and on a deletion neither side
 * is, so `is_subclass_of()` could not answer for the side that matters. A class whose `ShouldQueue`
 * arrives through a parent is beyond what an AST can prove, and the lane stays silent there rather
 * than guessing — a constructor change on it still surfaces through the contract lane at tier 1.
 */
final class BoundaryHazardLane implements HazardLane
{
    /**
     * Constraints whose absence lets a previously-rejected value through. Kept to rules with exactly
     * that meaning: a formatting or normalising rule dropping out is not a boundary hazard, and
     * listing it would make the lane fire on cosmetic rule edits.
     *
     * @var list<string>
     */
    private const array GUARDING_RULES = [
        'required', 'confirmed', 'max', 'min', 'size', 'in', 'exists', 'unique', 'mimes', 'image', 'url', 'email', 'uuid',
    ];

    public static function for(string $file, string $headSrc, string $baseSrc): array
    {
        if (! str_starts_with($file, 'app/')) {
            return [[], []];
        }

        return [[...self::validationLoosened($headSrc, $baseSrc), ...self::queuedPayloadChanged($headSrc, $baseSrc)], []];
    }

    /**
     * @return list<Hazard>
     */
    private static function validationLoosened(string $headSrc, string $baseSrc): array
    {
        $head = ValidationRules::rulesByMember($headSrc);
        $base = ValidationRules::rulesByMember($baseSrc);
        $hazards = [];

        foreach ($base as $member => $baseFields) {
            $headFields = $head[$member] ?? null;

            if ($headFields === null) {
                continue;
            }

            foreach ($baseFields as $field => $baseRules) {
                $headRules = $headFields[$field] ?? null;

                // Absent: the field went away entirely, which the parity lane reports. Null: the head
                // side of this field could not be enumerated, and a removal cannot be claimed from a
                // side that was never read.
                if ($headRules === null) {
                    continue;
                }

                // A base side that could not be enumerated has nothing to have lost, the same refusal
                // the head side gets above.
                if ($baseRules === null) {
                    continue;
                }

                $lost = array_values(array_intersect(array_diff($baseRules, $headRules), self::GUARDING_RULES));

                if ($lost !== []) {
                    sort($lost);
                    $hazards[] = new Hazard('boundary', 2, 'CWE-20', $member, "`{$field}` no longer validates " . implode(', ', $lost), ignoreKey: "{$member}::{$field}");
                }
            }
        }

        return $hazards;
    }

    /**
     * A queued class whose constructor signature moved. Jobs already on the queue were serialised
     * against the old one and are deserialised against the new: a parameter that changed name, type,
     * order or default is a payload mismatch the moment the release lands, not on the next call.
     *
     * `DispatchTarget::matches()` is deliberately not the predicate — it also accepts a sync-dispatched
     * command with no in-flight state, whose constructor change is the contract lane's business.
     *
     * @return list<Hazard>
     */
    private static function queuedPayloadChanged(string $headSrc, string $baseSrc): array
    {
        $headClasses = HazardSource::classLikes($headSrc);
        $baseClasses = HazardSource::classLikes($baseSrc);
        $hazards = [];

        foreach ($baseClasses as $fqcn => $baseClass) {
            $headClass = $headClasses[$fqcn] ?? null;

            if ($headClass === null || (! self::isQueued($fqcn, $baseClass) && ! self::isQueued($fqcn, $headClass))) {
                continue;
            }

            $baseConstructor = $baseClass->getMethod('__construct');
            $headConstructor = $headClass->getMethod('__construct');

            if (! $baseConstructor instanceof ClassMethod || ! $headConstructor instanceof ClassMethod) {
                continue;
            }

            $before = HazardSource::signature($baseConstructor);
            $after = HazardSource::signature($headConstructor);

            if ($before !== $after) {
                $hazards[] = new Hazard('boundary', 2, null, "{$fqcn}::__construct", "the queued payload changed from ({$before}) to ({$after})");
            }
        }

        return $hazards;
    }

    /**
     * Queued by declaration: a `\Jobs\` FQCN, or an `implements` list naming `ShouldQueue`. Public so
     * the contract lane can defer a queued constructor to this lane rather than reporting the same
     * change twice at two tiers.
     */
    public static function isQueued(string $fqcn, ClassLike $classLike): bool
    {
        if (str_contains($fqcn, '\\Jobs\\')) {
            return true;
        }

        if (! $classLike instanceof Class_) {
            return false;
        }

        return array_any($classLike->implements, static fn (Name $name): bool => str_ends_with($name->toString(), '\\ShouldQueue') || $name->toString() === 'ShouldQueue');
    }
}
