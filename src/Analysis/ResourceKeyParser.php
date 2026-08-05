<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;
use SanderMuller\Richter\Support\AppFiles;
use SanderMuller\Richter\Tracers\ReferenceEdgeTracer;

/**
 * Statically enumerates an API resource's `toArray()` keys from SOURCE, in two modes.
 *
 * Default mode is {@see PayloadParityChecker}'s historical behaviour, byte-for-byte: an
 * unkeyed item (`$this->mergeWhen(...)` and friends) is skipped, and a class-constant key
 * resolves through the autoloaded codebase. That is safe in the model→resource direction,
 * where a dropped key can at worst change which findings fire — never fabricate one from
 * a key that is actually emitted conditionally.
 *
 * Strict mode serves the classification-time key DIFF (consumer parity), where those two
 * behaviours would fabricate findings: a key moved *into* `mergeWhen` must not read as
 * removed, and a base-side constant key must never resolve against the *head* codebase.
 * Literal string keys only — anything else aborts to null, and null means silence.
 */
final class ResourceKeyParser
{
    /**
     * The consumer-parity lane's diff-time inputs for a changed resource file: the keys
     * this diff removed from and added to `toArray()`, strict-mode-parsed. Yields nothing
     * for a non-resource path (path-prefix matching — never an `App\` FQCN prefix, which
     * would break non-`App\` root namespaces), a brand-new file (no consumer relies on a
     * key that never shipped), an unreadable base, or a `null` strict parse on either
     * side (a deleted file's head degrades to `''`, which parses to null).
     *
     * @return array{0: list<string>, 1: list<string>}  [removedKeys, addedKeys]
     */
    public static function diffFor(string $file, bool $isNew, string $headSrc, ?string $baseSrc): array
    {
        if ($isNew || $baseSrc === null || ! self::isResourcePath($file)) {
            return [[], []];
        }

        $headKeys = self::keysOf($headSrc, strict: true);
        $baseKeys = self::keysOf($baseSrc, strict: true);

        if ($headKeys === null || $baseKeys === null) {
            return [[], []];
        }

        return [
            array_values(array_diff($baseKeys, $headKeys)),
            array_values(array_diff($headKeys, $baseKeys)),
        ];
    }

    /**
     * The two directories {@see ReferenceEdgeTracer} maps
     * to the `resource` edge, as paths.
     */
    public static function isResourcePath(string $file): bool
    {
        return str_starts_with($file, 'app/Http/Resources/') || str_starts_with($file, 'app/Transformers/');
    }

    /**
     * The statically enumerable `toArray()` keys of the source, or null when they cannot
     * be vouched for: no single `toArray()`, a body richer than one `return [...]`, a
     * spread, a dynamic key — and in strict mode any non-literal-string key or unkeyed
     * item (see the class docblock for why the modes differ).
     *
     * @return list<string>|null
     */
    public static function keysOf(string $source, bool $strict = false): ?array
    {
        $ast = AppFiles::parseResolved($source);

        if ($ast === null) {
            return null;
        }

        $finder = new NodeFinder();
        /** @var list<ClassMethod> $toArrayMethods */
        $toArrayMethods = array_values(array_filter(
            $finder->findInstanceOf($ast, ClassMethod::class),
            static fn (ClassMethod $method): bool => $method->name->toString() === 'toArray',
        ));

        if (count($toArrayMethods) !== 1) {
            return null;
        }

        $method = $toArrayMethods[0];

        // A plain `return [...];` body only — anything richer (logic before the return, multiple
        // returns) can't be statically enumerated with confidence, so the resource is skipped rather
        // than guessed at.
        if (count($method->stmts ?? []) !== 1 || ! $method->stmts[0] instanceof Return_) {
            return null;
        }

        $return = $method->stmts[0]->expr;

        if (! $return instanceof Array_) {
            return null;
        }

        return self::keysOfArray($return, self::classFqcn($ast), $strict);
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
            // `array_merge`/`mergeWhen`/nested spreads all take this shape. Abort the whole resource.
            if ($item->unpack) {
                return null;
            }

            if ($item->key === null) {
                // Strict: an unkeyed item's keys are invisible — a key moved into it would
                // read as removed. Default: skipped, per the historical lane behaviour.
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

    /** @param  list<Node\Stmt>  $ast */
    private static function classFqcn(array $ast): ?string
    {
        /** @var list<ClassLike> $classes */
        $classes = array_values(new NodeFinder()->findInstanceOf($ast, ClassLike::class));

        return count($classes) === 1 ? $classes[0]->namespacedName?->toString() : null;
    }
}
