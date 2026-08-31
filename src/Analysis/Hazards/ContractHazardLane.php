<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis\Hazards;

use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use SanderMuller\Richter\Analysis\Hazard;

/**
 * A caller outside the diff breaks. A member that was `public` or `protected` in the BASE source and
 * is gone from head is tier 2; a surviving member whose parameter list changed is tier 1, because a
 * new optional parameter or a widened type is often absorbed by every caller.
 *
 * **The caller check is an annotation, never a gate.** A removed member has no node in the head
 * graph, and since 0.39 `InstanceCallResolution` drops an `instance-call` edge whose method no app
 * class declares — so a subclass still calling a removed parent method has its dangling edge filtered
 * out at merge. A suppression built on "every caller is inside this diff" would therefore be
 * vacuously satisfied for exactly the removals that break someone. Noise is `hazards.ignore`'s job.
 *
 * Rename detection is deliberately absent: pairing a removal with an addition is a guess, so a rename
 * surfaces as a removal, the strongest claim this lane can prove. That is also why removals here
 * carry no moved-not-removed
 * token: unlike a guard, a member that reappears under another name has not moved, it has changed.
 */
final class ContractHazardLane implements HazardLane
{
    public static function for(string $file, string $headSrc, string $baseSrc): array
    {
        if (! str_starts_with($file, 'app/')) {
            return [[], []];
        }

        $head = HazardSource::members($headSrc);
        $base = HazardSource::members($baseSrc);
        $headClasses = HazardSource::classLikes($headSrc);
        $baseClasses = HazardSource::classLikes($baseSrc);
        $hazards = [];

        foreach ($base as $id => $member) {
            $class = explode('::', $id, 2)[0];

            // The whole class is gone. Its members went with it, and one row per member would bury
            // the one fact that matters — the same collapse the auth lane makes for a deleted policy.
            if (! isset($headClasses[$class])) {
                continue;
            }

            if (! isset($head[$id])) {
                $hazards = [...$hazards, ...self::removed($file, $id, $member)];

                continue;
            }

            $hazards = [...$hazards, ...self::signatureChanged($id, $member, $head[$id], $headClasses[$class], $baseClasses[$class] ?? null)];
        }

        return [[...$hazards, ...self::deletedClasses($file, $headClasses, $baseClasses)], []];
    }

    /**
     * @param  array{visibility: string, kind: string, node: Stmt}  $member
     * @return list<Hazard>
     */
    private static function removed(string $file, string $id, array $member): array
    {
        if ($member['visibility'] === 'private') {
            return [];
        }

        // A removed policy method is already reported by the auth lane at tier 3, where it belongs:
        // it is a guard, not just a contract. Reporting it twice at two tiers reads as two problems.
        if (str_starts_with($file, 'app/Policies/') && $member['kind'] === 'method' && $member['visibility'] === 'public') {
            return [];
        }

        return [new Hazard('contract', 2, null, $id, "the {$member['visibility']} {$member['kind']} is gone")];
    }

    /**
     * @param  array{visibility: string, kind: string, node: Stmt}  $base
     * @param  array{visibility: string, kind: string, node: Stmt}  $head
     * @return list<Hazard>
     */
    private static function signatureChanged(string $id, array $base, array $head, ClassLike $class, ?ClassLike $baseClass): array
    {
        if (! $base['node'] instanceof ClassMethod || ! $head['node'] instanceof ClassMethod) {
            return [];
        }

        // A queued class's constructor is the boundary lane's, at tier 2: jobs already on the queue
        // were serialised against the old signature. Reporting the same change again here, at tier 1,
        // would read as two problems — the same collapse the removed-policy-method case makes.
        //
        // EITHER side being queued defers it, matching the boundary lane's own predicate. A class that
        // drops `ShouldQueue` while changing its constructor is still the in-flight-payload problem:
        // the jobs already queued against the old signature do not know the class stopped being one.
        $fqcn = explode('::', $id, 2)[0];

        if ($head['node']->name->toString() === '__construct'
            && (BoundaryHazardLane::isQueued($fqcn, $class) || ($baseClass instanceof ClassLike && BoundaryHazardLane::isQueued($fqcn, $baseClass)))) {
            return [];
        }

        // A method that was private on both sides can only be called from inside the class, which the
        // diff contains by definition.
        if ($base['visibility'] === 'private' && $head['visibility'] === 'private') {
            return [];
        }

        $baseSignature = HazardSource::signature($base['node']);
        $headSignature = HazardSource::signature($head['node']);

        return $baseSignature === $headSignature
            ? []
            : [new Hazard('contract', 1, null, $id, "the parameter list changed from ({$baseSignature}) to ({$headSignature})")];
    }

    /**
     * A class the diff deleted whole, reported once. `app/Policies/` is left to the auth lane, which
     * reports the same deletion at tier 3.
     *
     * Compared class map to class map, NOT derived from the members: a class with no methods,
     * properties or constants — a marker, an empty exception, a value object built entirely from
     * promoted constructor properties the member map does not track — has no member rows to derive a
     * deletion from, and would go unreported while every caller of `new` on it breaks.
     *
     * @param  array<string, ClassLike>  $headClasses
     * @param  array<string, ClassLike>  $baseClasses
     * @return list<Hazard>
     */
    private static function deletedClasses(string $file, array $headClasses, array $baseClasses): array
    {
        if (str_starts_with($file, 'app/Policies/')) {
            return [];
        }

        return array_values(array_map(
            static fn (string $class): Hazard => new Hazard('contract', 2, null, $class, 'the class is gone'),
            array_keys(array_diff_key($baseClasses, $headClasses)),
        ));
    }
}
