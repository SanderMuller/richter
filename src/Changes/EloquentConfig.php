<?php declare(strict_types=1);

namespace SanderMuller\Richter\Changes;

use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\PropertyItem;
use PhpParser\Node\Scalar\Float_;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;
use PhpParser\PrettyPrinter\Standard;
use SanderMuller\Richter\Support\AppFiles;

/**
 * Recognises an *addition-only* edit to a model's `$fillable`/`$casts`/`casts()` (elements added,
 * none removed or changed) so {@see ChangedSymbols} reads a "just added a column" PR as additive
 * (LOW) not a coarse MEDIUM. `$guarded` is excluded — it is a block-list, so adding to it restricts
 * mass-assignment (the opposite of harmless). Compares AST-canonical key/value pairs, not text;
 * anything non-enumerable, or a member declared by more than one class in the file, is
 * conservatively not addition-only.
 */
final class EloquentConfig
{
    /** @var list<string> */
    private const array CONFIG_PROPERTIES = ['fillable', 'casts'];

    public static function isConfigMember(string $name, string $kind): bool
    {
        return ($kind === MemberChange::KIND_PROPERTY && in_array($name, self::CONFIG_PROPERTIES, strict: true))
            || ($kind === MemberChange::KIND_METHOD && $name === 'casts');
    }

    public static function isAdditionOnlyEdit(string $headSrc, string $baseSrc, string $name, string $kind): bool
    {
        $headNode = self::memberNodeFor($headSrc, $name, $kind);
        $baseNode = self::memberNodeFor($baseSrc, $name, $kind);

        if ($headNode === null || $baseNode === null) {
            return false;
        }

        $headMap = self::toMap(self::arrayOf($headNode, $kind));
        $baseMap = self::toMap(self::arrayOf($baseNode, $kind));

        if ($headMap === null || $baseMap === null) {
            return false;
        }

        // Only the array contents may differ — a co-occurring change to the member's declaration
        // (visibility/modifiers/type/return/params/attributes) means it isn't a pure column add.
        if (self::skeleton($headNode, $kind) !== self::skeleton($baseNode, $kind)) {
            return false;
        }

        // Addition-only: every base entry survives unchanged in head (head may add more).
        return array_all($baseMap, fn (string $value, string $key): bool => array_key_exists($key, $headMap) && $headMap[$key] === $value);
    }

    private static function memberNodeFor(string $source, string $name, string $kind): Property|ClassMethod|null
    {
        $ast = AppFiles::parse($source);

        if ($ast === null) {
            return null;
        }

        /** @var list<Property|ClassMethod> $matches */
        $matches = [];

        /** @var ClassLike $class */
        foreach (new NodeFinder()->findInstanceOf($ast, ClassLike::class) as $class) {
            foreach ($class->stmts as $stmt) {
                if (($stmt instanceof Property || $stmt instanceof ClassMethod) && self::declaresMember($stmt, $name, $kind)) {
                    $matches[] = $stmt;
                }
            }
        }

        // Exactly one declaring class — otherwise the array can't be attributed to the changed
        // member's class, so bail conservatively (the caller treats null as "not addition-only").
        return count($matches) === 1 ? $matches[0] : null;
    }

    private static function declaresMember(Node $stmt, string $name, string $kind): bool
    {
        if ($kind === MemberChange::KIND_PROPERTY && $stmt instanceof Property) {
            return array_any($stmt->props, static fn (PropertyItem $prop): bool => $prop->name->toString() === $name && $prop->default instanceof Array_);
        }

        // A `casts()` whose body is a single `return [...]` — anything richer isn't statically comparable.
        return $kind === MemberChange::KIND_METHOD
            && $stmt instanceof ClassMethod
            && $stmt->name->toString() === $name
            && count($stmt->stmts ?? []) === 1
            && $stmt->stmts[0] instanceof Return_
            && $stmt->stmts[0]->expr instanceof Array_;
    }

    private static function arrayOf(Property|ClassMethod $node, string $kind): Array_
    {
        if ($kind === MemberChange::KIND_PROPERTY && $node instanceof Property) {
            // Guaranteed by declaresMember: one of the props defaults to an array literal.
            $prop = array_find($node->props, static fn (PropertyItem $prop): bool => $prop->default instanceof Array_);
            assert($prop?->default instanceof Array_);

            return $prop->default;
        }

        // Guaranteed by declaresMember: the matched casts() wraps a single `return [...]`.
        $return = $node->stmts[0] ?? null;
        assert($return instanceof Return_ && $return->expr instanceof Array_);

        return $return->expr;
    }

    /**
     * The member's declaration with its config array emptied — a stable fingerprint of everything
     * *except* the array contents (visibility, modifiers, type, return type, params, attributes).
     * The parsed AST is local to this call, so emptying the array in place is safe.
     */
    private static function skeleton(Property|ClassMethod $node, string $kind): string
    {
        self::arrayOf($node, $kind)->items = [];

        return new Standard()->prettyPrint([$node]);
    }

    /**
     * Canonical key→value map (list items key on their value), collapsing duplicate keys
     * last-write-wins as PHP does. Null when any element is non-canonical (non-enumerable).
     *
     * @return array<string, string>|null
     */
    private static function toMap(Array_ $array): ?array
    {
        $map = [];

        foreach ($array->items as $item) {
            $value = self::canonical($item->value);

            if ($value === null) {
                return null;
            }

            if ($item->key === null) {
                $map[$value] = '__present__';

                continue;
            }

            $key = self::canonical($item->key);

            if ($key === null) {
                return null;
            }

            $map[$key] = $value;
        }

        return $map;
    }

    private static function canonical(Node $node): ?string
    {
        return match (true) {
            $node instanceof String_ => 's:' . $node->value,
            $node instanceof Int_ => 'i:' . $node->value,
            $node instanceof Float_ => 'd:' . $node->value,
            $node instanceof ConstFetch => 'c:' . $node->name->toString(),
            $node instanceof ClassConstFetch && $node->class instanceof Name && $node->name instanceof Identifier => 'cc:' . $node->class->toString() . '::' . $node->name->toString(),
            default => null,
        };
    }
}
