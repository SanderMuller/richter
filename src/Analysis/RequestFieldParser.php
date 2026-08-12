<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Namespace_;
use SanderMuller\Richter\Support\AppFiles;

/**
 * Statically enumerates a form request's `rules()` field names from SOURCE — the request-side
 * counterpart to {@see ResourceKeyParser}. A field dropped from `rules()` stops being validated
 * and stops arriving in `validated()`, so a frontend that still sends it now sends it into
 * nothing: no error, no value, the same silent shape the response-side lane covers.
 *
 * Strict-only, unlike the resource parser's two modes. There is no historical caller wanting the
 * lenient reading, and this parser exists exclusively to DIFF two sides of a change — the case
 * where an unkeyed item or a base-side class constant would fabricate a removal.
 *
 * A `rules()` that builds its array up (`$rules = [...]; if (…) …; return $rules;`) is not
 * enumerable and yields nothing. That is a reach limit, never a wrong finding.
 */
final class RequestFieldParser
{
    /**
     * The request-parity lane's diff-time inputs for a changed form-request file: the field names
     * this diff removed from and added to `rules()`. Yields nothing for a non-request path, a
     * brand-new file (no consumer sends a field that never existed), an unreadable base, or a
     * `null` parse on either side.
     *
     * @return array{0: list<string>, 1: list<string>}  [removedFields, addedFields]
     */
    public static function diffFor(string $file, bool $isNew, string $headSrc, ?string $baseSrc): array
    {
        if ($isNew || $baseSrc === null || ! self::isRequestPath($file)) {
            return [[], []];
        }

        $headFields = self::fieldsOf($headSrc);
        $baseFields = self::fieldsOf($baseSrc);

        if ($headFields === null || $baseFields === null) {
            return [[], []];
        }

        return [
            array_values(array_diff($baseFields, $headFields)),
            array_values(array_diff($headFields, $baseFields)),
        ];
    }

    /**
     * Path-prefix matching, never an `App\` FQCN prefix, which would break a non-`App\` root
     * namespace. The directory Laravel's own `make:request` writes to is the whole convention
     * here — a rules() method elsewhere is not addressed by a request payload.
     */
    public static function isRequestPath(string $file): bool
    {
        return str_starts_with($file, 'app/Http/Requests/');
    }

    /**
     * The statically enumerable `rules()` field names of the source, or null when they cannot be
     * vouched for.
     *
     * @return list<string>|null
     */
    public static function fieldsOf(string $source): ?array
    {
        return ArrayReturnKeys::of($source, 'rules', strict: true);
    }

    /**
     * The same diff for validation written INLINE — `$request->validate([...])` and the
     * `ValidatesRequests` form `$this->validate($request, [...])` — keyed by the method it sits in.
     *
     * A form request is the documented convention, not the only place validation lives: an action
     * validating a handful of fields commonly does it in the controller instead. Dropping a key from
     * that array silently stops validating the field exactly as dropping it from `rules()` does.
     *
     * Keyed by the full member id (`App\\Http\\Controllers\\PostController::store`), not by method
     * name. Per method because a controller holds several actions with their own validation and a
     * file-level diff would report a field removed from one as removed from all; qualified by class
     * because a file may declare more than one, and a bare method name would both mis-anchor the
     * finding and let two same-named methods overwrite each other.
     *
     * A member the HEAD cannot enumerate is skipped even when the base could. Tempting to report the
     * base's fields as removed — the base is known, after all — but `$request->validate($rules)` is
     * opaque: those fields may all still be validated. Claiming a removal there would be the one
     * thing this lane never does, assert something it cannot establish.
     *
     * @return array<string, array{0: list<string>, 1: list<string>}>  member id => [removed, added]
     */
    public static function inlineDiffFor(string $file, bool $isNew, string $headSrc, ?string $baseSrc): array
    {
        if ($isNew || $baseSrc === null || self::isRequestPath($file)) {
            return [];
        }

        $head = self::inlineFieldsByMethod($headSrc);
        $base = self::inlineFieldsByMethod($baseSrc);
        $diff = [];

        foreach ($head as $member => $headFields) {
            $baseFields = $base[$member] ?? null;

            if ($baseFields === null) {
                continue;
            }

            $removed = array_values(array_diff($baseFields, $headFields));

            if ($removed !== []) {
                $diff[$member] = [$removed, array_values(array_diff($headFields, $baseFields))];
            }
        }

        return $diff;
    }

    /**
     * Every method whose inline validation this can fully enumerate, mapped to its field names. A
     * method is ABSENT rather than empty when any of its validate calls passes rules it cannot read
     * (a variable, a merge) — absent means "cannot vouch", and the diff skips it, where an empty
     * list would read as "every field was removed".
     *
     * @return array<string, list<string>>  member id => field names
     */
    private static function inlineFieldsByMethod(string $source): array
    {
        $ast = AppFiles::parseResolved($source);

        if ($ast === null) {
            return [];
        }

        $selfFqcn = ArrayReturnKeys::classFqcnOf($ast);
        $byMethod = [];

        // Top-level named classes only. `findInstanceOf` also returns an anonymous class nested in a
        // method, and mapping its methods here would key them under the file's own FQCN — an anchor
        // like `Registry::run` that does not exist, or a silent collision when two classes share a
        // method name. Calls inside such a class are not lost: {@see AppFiles::nodesOwnedBy()} hands
        // them to the method that builds it.
        foreach (self::topLevelClasses($ast) as $classLike) {
            $onThis = self::usesValidatesRequests($classLike);

            foreach ($classLike->getMethods() as $method) {
                $fields = self::inlineFieldsIn($method, $selfFqcn, $onThis);

                // Every readable method gets an entry, including one that validates nothing. Keeping only
                // the non-empty ones conflated two different answers: `validate([])` and a method whose
                // validate call was deleted outright both enumerate to zero fields, and both are a real
                // removal of everything the base validated. Dropping them reported no change at all.
                if ($fields !== null) {
                    $member = ltrim((string) $classLike->namespacedName, '\\') . '::' . $method->name->toString();
                    $byMethod[$member] = $fields;
                }
            }
        }

        return $byMethod;
    }

    /**
     * The file's own class declarations, unwrapping a `namespace` block. Anything nested inside a
     * method is deliberately not one of them.
     *
     * @param  list<Stmt>  $ast
     * @return list<ClassLike>
     */
    private static function topLevelClasses(array $ast): array
    {
        $classes = [];

        foreach ($ast as $statement) {
            foreach ($statement instanceof Namespace_ ? $statement->stmts : [$statement] as $candidate) {
                if ($candidate instanceof ClassLike && $candidate->namespacedName instanceof Name) {
                    $classes[] = $candidate;
                }
            }
        }

        return $classes;
    }

    /**
     * Whether `$this->validate(...)` in this class is Laravel's, rather than a method of the same
     * name the class happens to define.
     *
     * `validate` is an ordinary name, and a class with its own two-argument `validate($value,
     * $options)` would otherwise have its options array read as request rules — a confident finding
     * about an unrelated API. The trait is the only local proof, so a controller that inherits it
     * from a base class is missed: silence, which is the direction this lane errs in everywhere else.
     */
    private static function usesValidatesRequests(ClassLike $classLike): bool
    {
        foreach ($classLike->getTraitUses() as $use) {
            foreach ($use->traits as $trait) {
                if (str_ends_with(AppFiles::resolveName($trait), 'Foundation\\Validation\\ValidatesRequests')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @return list<string>|null  null ONLY when a validate call here cannot be enumerated. An empty
     *   list is a real answer: this method validates nothing, which against a base that validated
     *   something is the removal of every field.
     */
    private static function inlineFieldsIn(ClassMethod $method, ?string $selfFqcn, bool $onThis): ?array
    {
        $fields = [];
        $matches = static fn (mixed $node): bool => self::isValidateCall($node, $onThis);

        foreach (AppFiles::nodesOwnedBy($method, $matches) as $call) {
            /** @var MethodCall $call */
            $rules = self::rulesArgument($call);

            if (! $rules instanceof Array_) {
                return null;
            }

            $keys = ArrayReturnKeys::keysOfLiteral($rules, $selfFqcn);

            if ($keys === null) {
                return null;
            }

            $fields = [...$fields, ...$keys];
        }

        return array_values(array_unique($fields));
    }

    /**
     * `$request->validate([...])` puts the rules first; `$this->validate($request, [...])` puts the
     * request first. Told apart by the receiver, since both are a `validate` method call.
     */
    private static function rulesArgument(MethodCall $call): ?Expr
    {
        $args = $call->getArgs();

        // A named argument can appear anywhere, so the name wins over the position when present.
        foreach ($args as $arg) {
            if ($arg->name instanceof Identifier && $arg->name->toString() === 'rules') {
                return $arg->value;
            }
        }

        $index = $call->var instanceof Variable && $call->var->name === 'this' ? 1 : 0;

        return ($args[$index] ?? null)?->value;
    }

    /**
     * `validate` is a common method name, so the receiver decides. Only `$request->validate([...])`
     * and the `ValidatesRequests` `$this->validate(...)` are request validation; `$service->validate(
     * ['option' => …])` is somebody else's API, and reading its array as request fields would invent
     * a frontend-parity finding out of an unrelated call.
     *
     * A request held in a differently named variable is missed. That is the safe direction here —
     * silence rather than a confident finding about the wrong thing.
     *
     * `$this->validate(...)` only counts when the class pulls in `ValidatesRequests` itself
     * ({@see usesValidatesRequests()}); without that the method could be the class's own.
     */
    private static function isValidateCall(mixed $node, bool $onThis): bool
    {
        if (! $node instanceof MethodCall || ! $node->name instanceof Identifier) {
            return false;
        }

        if ($node->name->toLowerString() !== 'validate' || $node->getArgs() === []) {
            return false;
        }

        if (! $node->var instanceof Variable) {
            return false;
        }

        return $node->var->name === 'request' || ($onThis && $node->var->name === 'this');
    }
}
