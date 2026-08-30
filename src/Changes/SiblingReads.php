<?php declare(strict_types=1);

namespace SanderMuller\Richter\Changes;

use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Use_;
use PhpParser\NodeFinder;
use SanderMuller\Richter\Support\AppFiles;
use SanderMuller\Richter\Support\DeclaredTypes;

/**
 * How a method TREATS each property it reads: bare, or guarded, and by which form of guard.
 *
 * This is the one side of `sibling-read-parity` that reads source. It answers "`App\Models\Order`'s
 * `external_id` is read here, with no fallback" for one file, and the checker compares two of those
 * answers — one from the diff, one from the code that was already beside it.
 *
 * Only a read whose RECEIVER has a declared class type is recorded. Name-only matching was measured
 * and is useless: a property called `value` tied three unrelated classes together. {@see DeclaredTypes}
 * resolves the type and drops a union rather than guessing, which is the same no-guess bargain the
 * rest of the package makes.
 *
 * Only a READ position counts. `$order->external_id = $value`, an `unset()`, and a by-reference
 * argument are not reads of the value, and reporting one would be a claim about code that never looked
 * at it. A read-modify-write does read, and counts.
 *
 * @internal
 */
final class SiblingReads
{
    public const string STYLE_BARE = 'bare';

    public const string STYLE_FALLBACK = 'fallback';

    public const string STYLE_EMPTINESS = 'emptiness';

    public const string STYLE_NULL_TEST = 'null-test';

    /** The styles that supply or tolerate an absent value — the evidence side of a finding. */
    public const array SOFT_STYLES = [self::STYLE_FALLBACK, self::STYLE_EMPTINESS];

    /**
     * Reads in one file's source.
     *
     * @param  list<string>|null  $onlyMembers  when given, only these member names are read — the
     *   changed side passes the members the diff touched, the evidence side passes null for all
     * @return array<string, array<string, list<string>>> `Fqcn->property` => style => `Class::member`
     */
    public static function in(string $source, ?array $onlyMembers = null): array
    {
        $ast = AppFiles::parseResolved($source);

        if ($ast === null) {
            return [];
        }

        $finder = new NodeFinder();
        $types = new DeclaredTypes();
        $types->readImports(array_values($finder->findInstanceOf($ast, Use_::class)));

        $reads = [];

        foreach ($finder->findInstanceOf($ast, Class_::class) as $class) {
            if (! $class->name instanceof Identifier) {
                continue;
            }

            // `namespacedName` is set by the name resolver; a class declared outside any namespace
            // has none, and its own name is then the whole FQCN.
            $own = $class->namespacedName instanceof Name
                ? AppFiles::resolveName($class->namespacedName)
                : $class->name->toString();
            $properties = $types->propertyTypesOf($class);

            foreach ($class->getMethods() as $method) {
                $name = $method->name->toString();

                if ($onlyMembers !== null && ! in_array($name, $onlyMembers, strict: true)) {
                    continue;
                }

                self::readMethod($method, $own . '::' . $name, ['this' => $own] + $properties + $types->parameterTypesOf($method), $reads);
            }
        }

        return $reads;
    }

    /**
     * @param  array<string, string>  $receiverTypes  variable name => declared class
     * @param  array<string, array<string, list<string>>>  $reads
     */
    private static function readMethod(ClassMethod $method, string $where, array $receiverTypes, array &$reads): void
    {
        [$styles, $notReads] = ReadStyles::of($method);

        foreach (new NodeFinder()->findInstanceOf($method, PropertyFetch::class) as $fetch) {
            if (! $fetch->name instanceof Identifier || isset($notReads[spl_object_id($fetch)])) {
                continue;
            }

            if (! $fetch->var instanceof Variable || ! is_string($fetch->var->name)) {
                continue;
            }

            $receiver = $receiverTypes[$fetch->var->name] ?? null;

            if ($receiver === null) {
                continue;
            }

            $style = $styles[spl_object_id($fetch)] ?? self::STYLE_BARE;
            $reads[$receiver . '->' . $fetch->name->toString()][$style][] = $where;
        }
    }

    /**
     * The reads the CHANGED members perform, for the sibling-read parity lane.
     *
     * Head-side and whole-member: a method the diff touched is a method whose body the author just
     * worked on, so every read in it counts, not only the ones on changed lines. A guard the diff adds
     * elsewhere in the same method therefore disarms a read it did not touch, which is the direction
     * that fails toward silence.
     *
     * A REMOVED member contributes nothing — there is no head-side code to report on — and neither
     * does a class-level change with no changed member.
     *
     * @param  list<MemberChange>  $members
     * @return array<string, array<string, list<string>>>
     */
    public static function forChangedMembers(string $file, string $headSrc, array $members): array
    {
        if ($headSrc === '' || ! str_starts_with($file, 'app/')) {
            return [];
        }

        $names = [];

        foreach ($members as $member) {
            if ($member->kind === 'method' && $member->change !== MemberChange::CHANGE_REMOVED && $member->name !== '') {
                $names[] = $member->name;
            }
        }

        return $names === [] ? [] : self::in($headSrc, $names);
    }
}
