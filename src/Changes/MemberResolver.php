<?php declare(strict_types=1);

namespace SanderMuller\Richter\Changes;

use PhpParser\Comment\Doc;
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
        $start = self::memberStartLine($stmt);

        if ($stmt instanceof ClassMethod) {
            return [self::makeMember($stmt->name->toString(), MemberChange::KIND_METHOD, resolvable: true, start: $start, end: $stmt->getEndLine())];
        }

        if ($stmt instanceof EnumCase) {
            // Enum cases now get a member node via ConstantReferenceTracer (`Enum::Case` + reader
            // edges), so a case change pins to its readers instead of coarse-seeding the whole enum.
            return [self::makeMember($stmt->name->toString(), MemberChange::KIND_ENUM_CASE, resolvable: true, start: $start, end: $stmt->getEndLine())];
        }

        if ($stmt instanceof Property) {
            return self::declarationItems($stmt->props, MemberChange::KIND_PROPERTY, resolvable: false, statementStart: $start);
        }

        if ($stmt instanceof ClassConst) {
            // Class constants get a member node via ConstantReferenceTracer (`Class::CONST` + reader
            // edges) → resolvable, so a change pins to its readers. Trait constants are the exception:
            // the tracer skips traits, so they stay coarse (`$inTrait`) rather than read UNRESOLVED.
            return self::declarationItems($stmt->consts, MemberChange::KIND_CONSTANT, resolvable: ! $inTrait, statementStart: $start);
        }

        return [];
    }

    /**
     * One member per item in a declaration group (`const A = 1, B = 2;` or `public $a, $b;`), each
     * with its OWN line span rather than the shared statement span. Without this, touching one item —
     * or adding a sibling to the group — marks EVERY co-declared member changed, so seeding one
     * constant fans out to every constant's readers. The first item absorbs the declaration's leading
     * region ($statementStart: the `const`/visibility keyword, attribute groups, and doc comment) so a
     * changed attribute or docblock line still maps to the member.
     *
     * @template T of Const_|PropertyItem
     *
     * @param  array<T>  $items
     * @return list<array{name: string, kind: string, resolvable: bool, start: int, end: int}>
     */
    private static function declarationItems(array $items, string $kind, bool $resolvable, int $statementStart): array
    {
        $members = [];

        foreach (array_values($items) as $index => $item) {
            $start = $index === 0 ? min($statementStart, $item->getStartLine()) : $item->getStartLine();
            $members[] = self::makeMember($item->name->toString(), $kind, $resolvable, $start, $item->getEndLine());
        }

        return $members;
    }

    /** @return array{name: string, kind: string, resolvable: bool, start: int, end: int} */
    private static function makeMember(string $name, string $kind, bool $resolvable, int $start, int $end): array
    {
        return ['name' => $name, 'kind' => $kind, 'resolvable' => $resolvable, 'start' => $start, 'end' => $end];
    }

    /**
     * A member's declaration can be preceded by lines that belong to it — `#[Attr]` attribute groups
     * and a leading doc comment both sit above the `function`/property keyword that php-parser reports
     * as the start line. Include them so a changed attribute or docblock line maps to its member, and
     * so a new method added together with its docblock reads as one additive member rather than a
     * class-level modification (which would coarse-seed the class and raise a false low-confidence flag).
     */
    private static function memberStartLine(Stmt $stmt): int
    {
        $start = $stmt->getStartLine();

        if (! ($stmt instanceof ClassMethod || $stmt instanceof Property || $stmt instanceof ClassConst || $stmt instanceof EnumCase)) {
            return $start;
        }

        foreach ($stmt->attrGroups as $group) {
            $start = min($start, $group->getStartLine());
        }

        $doc = $stmt->getDocComment();

        return $doc instanceof Doc ? min($start, $doc->getStartLine()) : $start;
    }
}
