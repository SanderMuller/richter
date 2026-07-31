<?php declare(strict_types=1);

namespace SanderMuller\Richter\Changes;

use PhpParser\Node\Const_;
use PhpParser\Node\PropertyItem;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\EnumCase;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Trait_;
use PhpParser\NodeFinder;
use SanderMuller\Richter\Support\AppFiles;

/**
 * Resolves a PHP source string's class members and class spans with their line ranges, so a diff
 * line range maps to the member it touched. Pure — used for both the HEAD and base side of a diff.
 */
final class MemberResolver
{
    /**
     * @return array{
     *     parsed: bool,
     *     members: list<array{name: string, kind: string, resolvable: bool, start: int, end: int}>,
     *     classRanges: list<array{start: int, end: int}>,
     * }
     */
    public static function resolve(string $source): array
    {
        $ast = AppFiles::parse($source);

        if ($ast === null) {
            // Parse failure is not "no members" — the caller must not read an unparseable changed
            // file as cosmetic. Signal it so classification can fall back to a coarse class seed.
            return ['parsed' => false, 'members' => [], 'classRanges' => []];
        }

        $members = [];
        $classRanges = [];

        /** @var ClassLike $class */
        foreach (new NodeFinder()->findInstanceOf($ast, ClassLike::class) as $class) {
            $classRanges[] = ['start' => $class->getStartLine(), 'end' => $class->getEndLine()];

            // A trait's constants are copied into each using class, not inherited, so
            // ConstantReferenceTracer skips them — keep them coarse here so a trait-constant change
            // still coarse-seeds the trait and reaches the using classes (rather than reading UNRESOLVED).
            $inTrait = $class instanceof Trait_;

            foreach ($class->stmts as $stmt) {
                foreach (self::membersOf($stmt, $inTrait) as $member) {
                    $members[] = $member;
                }
            }
        }

        return ['parsed' => true, 'members' => $members, 'classRanges' => $classRanges];
    }

    /** @return list<array{name: string, kind: string, resolvable: bool, start: int, end: int}> */
    private static function membersOf(Stmt $stmt, bool $inTrait): array
    {
        $start = self::startLineWithAttributes($stmt);
        $end = $stmt instanceof ClassMethod || $stmt instanceof Property || $stmt instanceof ClassConst || $stmt instanceof EnumCase
            ? $stmt->getEndLine()
            : 0;

        if ($stmt instanceof ClassMethod) {
            return [self::makeMember($stmt->name->toString(), MemberChange::KIND_METHOD, resolvable: true, start: $start, end: $end)];
        }

        if ($stmt instanceof EnumCase) {
            // Enum cases now get a member node via ConstantReferenceTracer (`Enum::Case` + reader
            // edges), so a case change pins to its readers instead of coarse-seeding the whole enum.
            return [self::makeMember($stmt->name->toString(), MemberChange::KIND_ENUM_CASE, resolvable: true, start: $start, end: $end)];
        }

        if ($stmt instanceof Property) {
            return array_values(array_map(static fn (PropertyItem $prop): array => self::makeMember($prop->name->toString(), MemberChange::KIND_PROPERTY, resolvable: false, start: $start, end: $end), $stmt->props));
        }

        if ($stmt instanceof ClassConst) {
            // Class constants get a member node via ConstantReferenceTracer (`Class::CONST` + reader
            // edges) → resolvable, so a change pins to its readers. Trait constants are the exception:
            // the tracer skips traits, so they stay coarse (`$inTrait`) rather than read UNRESOLVED.
            return array_values(array_map(static fn (Const_ $const): array => self::makeMember($const->name->toString(), MemberChange::KIND_CONSTANT, resolvable: ! $inTrait, start: $start, end: $end), $stmt->consts));
        }

        return [];
    }

    /** @return array{name: string, kind: string, resolvable: bool, start: int, end: int} */
    private static function makeMember(string $name, string $kind, bool $resolvable, int $start, int $end): array
    {
        return ['name' => $name, 'kind' => $kind, 'resolvable' => $resolvable, 'start' => $start, 'end' => $end];
    }

    /**
     * A member's leading attributes sit on lines above the declaration; include them so a
     * changed attribute line (e.g. `#[WithoutRelations]`) maps to its member.
     */
    private static function startLineWithAttributes(Stmt $stmt): int
    {
        $start = $stmt->getStartLine();

        if ($stmt instanceof ClassMethod || $stmt instanceof Property || $stmt instanceof ClassConst || $stmt instanceof EnumCase) {
            foreach ($stmt->attrGroups as $group) {
                $start = min($start, $group->getStartLine());
            }
        }

        return $start;
    }
}
