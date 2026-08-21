<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis\Hazards;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Param;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Node\UnionType;
use PhpParser\Node\VariadicPlaceholder;
use PhpParser\NodeFinder;
use PhpParser\PrettyPrinter\Standard;
use SanderMuller\Richter\Support\AppFiles;

/**
 * The parsing every hazard lane shares: one side of a diff reduced to its methods, keyed by fully
 * qualified member id so the two sides can be compared member by member.
 *
 * Name resolution matters here — a lane compares `Gate::denies()` against an imported `Gate`, and a
 * lane scoped to `FormRequest` subclasses needs the resolved parent. {@see AppFiles::parseResolved()}
 * supplies both, and an unparseable side yields an empty map rather than a guess, which makes every
 * predicate silently no-op on it (Edge Cases: "an unparseable base or head side").
 */
final class HazardSource
{
    private static ?Standard $printer = null;

    /**
     * Every method one side declares, keyed `App\Foo::bar`.
     *
     * @return array<string, ClassMethod>
     */
    public static function methods(string $source): array
    {
        $methods = [];

        foreach (self::classLikes($source) as $fqcn => $classLike) {
            foreach ($classLike->getMethods() as $method) {
                $methods["{$fqcn}::{$method->name->toString()}"] = $method;
            }
        }

        return $methods;
    }

    /**
     * Every member one side declares that a caller outside the class could depend on, keyed
     * `App\Foo::bar` (a property keeps its `$`). Carries the visibility {@see MemberResolver} does
     * not record — its records are name, kind, resolvable and line span, so a lane needing `public`
     * versus `private` parses for itself, as the resource and request parsers already do.
     *
     * @return array<string, array{visibility: string, kind: string, node: Stmt}>
     */
    public static function members(string $source): array
    {
        $members = [];

        foreach (self::classLikes($source) as $fqcn => $classLike) {
            foreach ($classLike->stmts as $stmt) {
                foreach (self::memberIds($stmt) as $id => $kind) {
                    $members["{$fqcn}::{$id}"] = ['visibility' => self::visibilityOf($stmt), 'kind' => $kind, 'node' => $stmt];
                }
            }
        }

        return $members;
    }

    /**
     * One class statement's member ids. A grouped declaration (`public $a, $b;`) is several members
     * on one node, which is why this returns a map rather than a single id.
     *
     * @return array<string, string>
     */
    private static function memberIds(Stmt $stmt): array
    {
        if ($stmt instanceof ClassMethod) {
            return [$stmt->name->toString() => 'method'];
        }

        if ($stmt instanceof Property) {
            $ids = [];

            foreach ($stmt->props as $prop) {
                $ids['$' . $prop->name->toString()] = 'property';
            }

            return $ids;
        }

        if ($stmt instanceof ClassConst) {
            $ids = [];

            foreach ($stmt->consts as $const) {
                $ids[$const->name->toString()] = 'constant';
            }

            return $ids;
        }

        return [];
    }

    private static function visibilityOf(Stmt $stmt): string
    {
        if (! $stmt instanceof ClassMethod && ! $stmt instanceof Property && ! $stmt instanceof ClassConst) {
            return 'public';
        }

        return match (true) {
            $stmt->isPrivate() => 'private',
            $stmt->isProtected() => 'protected',
            default => 'public',
        };
    }

    /**
     * A method's parameter list as one comparable string — count, name, type, default, by-reference,
     * variadic and constructor promotion all in it, so any of them changing shows up as a difference.
     * Built from AST fields rather than printed source, so reformatting or moving an attribute draws
     * nothing.
     */
    public static function signature(ClassMethod $method): string
    {
        $printer = self::$printer ??= new Standard();

        return implode(', ', array_map(static function (Param $param) use ($printer): string {
            $name = $param->var instanceof Variable && is_string($param->var->name) ? $param->var->name : '?';
            $type = $param->type instanceof Node ? self::typeName($param->type) : 'mixed';
            $default = $param->default instanceof Expr ? $printer->prettyPrintExpr($param->default) : 'required';
            $flags = ($param->byRef ? '&' : '') . ($param->variadic ? '...' : '') . ($param->flags !== 0 ? 'promoted' : '');

            return "{$flags}{$type} \${$name} = {$default}";
        }, $method->params));
    }

    /** A type node as source-shaped text — nullable, union and intersection types included. */
    private static function typeName(Node $type): string
    {
        return match (true) {
            $type instanceof NullableType => '?' . self::typeName($type->type),
            $type instanceof UnionType => implode('|', array_map(self::typeName(...), $type->types)),
            $type instanceof IntersectionType => implode('&', array_map(self::typeName(...), $type->types)),
            $type instanceof Name, $type instanceof Identifier => $type->toString(),
            default => 'mixed',
        };
    }

    /**
     * Every named class-like one side declares, keyed by FQCN. Anonymous classes are skipped — they
     * have no name to attribute a hazard to, the same refusal the instance-call lane makes.
     *
     * @return array<string, ClassLike>
     */
    public static function classLikes(string $source): array
    {
        $ast = AppFiles::parseResolved($source);

        if ($ast === null) {
            return [];
        }

        $found = [];

        foreach (new NodeFinder()->findInstanceOf($ast, ClassLike::class) as $classLike) {
            if (! $classLike instanceof ClassLike || ! $classLike->namespacedName instanceof Name) {
                continue;
            }

            $found[$classLike->namespacedName->toString()] = $classLike;
        }

        return $found;
    }

    /**
     * The parent class name of a class-like, fully qualified, or null.
     *
     * Through {@see AppFiles::resolveName()} rather than `toString()`: an imported name stays the bare
     * token in the AST, so `toString()` returns `FormRequest` where the file wrote
     * `use Illuminate\Foundation\Http\FormRequest;`. Two lanes have already been caught matching a
     * facade that way and silently never firing on an importing file.
     */
    public static function parentOf(ClassLike $classLike): ?string
    {
        return $classLike instanceof Class_ && $classLike->extends instanceof Name
            ? AppFiles::resolveName($classLike->extends)
            : null;
    }

    /**
     * Whether a method body is exactly `return true;` — the shape a neutered `authorize()` takes. A
     * body with anything else in it, or an abstract/interface method with no body at all, is not.
     */
    public static function returnsTrueOnly(ClassMethod $method): bool
    {
        if ($method->stmts === null || count($method->stmts) !== 1) {
            return false;
        }

        $only = $method->stmts[0];

        return $only instanceof Return_
            && $only->expr instanceof ConstFetch
            && strtolower($only->expr->name->toString()) === 'true';
    }

    /** A node printed back to source, for an evidence line. Single-lined so it fits one report row. */
    public static function print(Node $node): string
    {
        self::$printer ??= new Standard();

        return (string) preg_replace('/\s+/', ' ', self::$printer->prettyPrintExpr(
            $node instanceof Expr ? $node : new String_(''),
        ));
    }

    /** The literal string value of an argument, or null when it is not written out at the call site. */
    public static function literalArgument(Arg|VariadicPlaceholder|null $arg): ?string
    {
        return $arg instanceof Arg && $arg->value instanceof String_ ? $arg->value->value : null;
    }

    /** Whether an expression is `$this`. */
    public static function isThis(Node $node): bool
    {
        return $node instanceof Variable && $node->name === 'this';
    }

    /**
     * Every node of one type inside a method body.
     *
     * @template T of Node
     *
     * @param  class-string<T>  $type
     * @return list<T>
     */
    public static function within(ClassMethod $method, string $type): array
    {
        /** @var list<T> */
        return new NodeFinder()->findInstanceOf($method->stmts ?? [], $type);
    }
}
