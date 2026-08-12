<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;
use SanderMuller\Richter\Support\AppFiles;

/**
 * The keys of the array a named method returns, enumerated from SOURCE — the one algorithm behind
 * both contract parsers: a resource's `toArray()` ({@see ResourceKeyParser}) and a form request's
 * `rules()` ({@see RequestFieldParser}). Both ask the same question of a different method name.
 *
 * Null is the answer whenever the key set cannot be vouched for, and null means silence in every
 * lane that consumes it. Guessing here does not produce a weaker finding, it produces a finding
 * pointing at the wrong field.
 *
 * Strict mode additionally refuses an unkeyed item and a class-constant key. Both matter to a lane
 * that DIFFS two sides: a key moved into an unkeyed spread would read as removed, and a constant on
 * the base side would resolve against the head codebase, which is the only one loaded.
 *
 * @internal
 */
final class ArrayReturnKeys
{
    /**
     * @param  string  $method  the method whose returned array is enumerated
     * @return list<string>|null
     */
    public static function of(string $source, string $method, bool $strict = false): ?array
    {
        $ast = AppFiles::parseResolved($source);

        if ($ast === null) {
            return null;
        }

        $finder = new NodeFinder();
        /** @var list<ClassMethod> $candidates */
        $candidates = array_values(array_filter(
            $finder->findInstanceOf($ast, ClassMethod::class),
            static fn (ClassMethod $node): bool => $node->name->toString() === $method,
        ));

        if (count($candidates) !== 1) {
            return null;
        }

        $found = $candidates[0];

        // A plain `return [...];` body only — anything richer (logic before the return, multiple
        // returns) can't be statically enumerated with confidence, so the class is skipped rather
        // than guessed at.
        if (count($found->stmts ?? []) !== 1 || ! $found->stmts[0] instanceof Return_) {
            return null;
        }

        $return = $found->stmts[0]->expr;

        if (! $return instanceof Array_) {
            return null;
        }

        return self::keysOfArray($return, self::classFqcn($ast), $strict);
    }

    /**
     * The keys of an array literal passed as a rules argument, strictly. Exposed because inline
     * validation puts that array in an ARGUMENT rather than a return, and the enumeration rules —
     * literal keys only, no spread, no class constant — must not diverge between the two shapes.
     *
     * @return list<string>|null
     */
    public static function keysOfLiteral(Array_ $array, ?string $selfFqcn): ?array
    {
        return self::keysOfArray($array, $selfFqcn, strict: true);
    }

    /**
     * The file's single class FQCN, for resolving a `self::CONST` key.
     *
     * @param  list<Stmt>  $ast
     */
    public static function classFqcnOf(array $ast): ?string
    {
        return self::classFqcn($ast);
    }

    /** @return list<string>|null */
    private static function keysOfArray(Array_ $array, ?string $selfFqcn, bool $strict): ?array
    {
        $keys = [];

        foreach ($array->items as $item) {
            if ($item === null) {
                continue;
            }

            // A spread (`...`) can inject any number of keys this parser cannot enumerate —
            // `array_merge`/`mergeWhen`/nested spreads all take this shape. Abort the whole class.
            if ($item->unpack) {
                return null;
            }

            if ($item->key === null) {
                // Strict: an unkeyed item's keys are invisible — a key moved into it would
                // read as removed. Default: skipped, per the historical resource-lane behaviour.
                if ($strict) {
                    return null;
                }

                continue;
            }

            $resolved = self::resolveKey($item->key, $selfFqcn, $strict);

            // A dynamic key ($variable, concatenation, a function call) is exactly as unenumerable as
            // a spread — the value can be anything (`when()` as a VALUE is fine and counted normally
            // below; it is only the KEY that must be statically known).
            if ($resolved === null) {
                return null;
            }

            $keys[] = $resolved;
        }

        return array_values(array_unique($keys));
    }

    /**
     * A literal string key; in default mode also a class-constant key resolved by
     * reflection (strict mode never touches the autoloaded codebase — a base-side source
     * would resolve to head values); null for anything else.
     */
    private static function resolveKey(Node $node, ?string $selfFqcn, bool $strict): ?string
    {
        if ($node instanceof String_) {
            return $node->value;
        }

        if ($strict || ! $node instanceof ClassConstFetch || ! $node->class instanceof Name || ! $node->name instanceof Identifier) {
            return null;
        }

        $written = $node->class->toString();
        $class = in_array(strtolower($written), ['self', 'static'], true)
            ? $selfFqcn
            : AppFiles::resolveName($node->class);

        return $class === null ? null : AppFiles::stringConstantValue($class, $node->name->toString());
    }

    /** @param  list<Stmt>  $ast */
    private static function classFqcn(array $ast): ?string
    {
        /** @var list<ClassLike> $classes */
        $classes = array_values(new NodeFinder()->findInstanceOf($ast, ClassLike::class));

        return count($classes) === 1 ? $classes[0]->namespacedName?->toString() : null;
    }
}
