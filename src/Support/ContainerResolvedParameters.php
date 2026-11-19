<?php declare(strict_types=1);

namespace SanderMuller\Richter\Support;

use Illuminate\Contracts\Container\ContextualAttribute;
use PhpParser\Node;
use PhpParser\Node\ComplexType;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Param;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\UnionType;

/**
 * The app classes the container builds for a method's parameters. No call in the body shows them,
 * and a form request built this way is validated, so its `rules()` and `authorize()` run before the
 * method body.
 *
 * - A parameter typed as an app form request (`StorePostRequest $request`).
 * - Each class a {@see ContextualAttribute} on a parameter names. Laravel fills such a parameter
 *   through the attribute's `resolve()`, and an attribute that names a class
 *   (`#[Give(SomeService::class)]`, or an app attribute that builds a form request) has the container
 *   build it. Every `X::class` argument counts, because what `resolve()` does with it is not visible
 *   here.
 *
 * @internal
 */
final class ContainerResolvedParameters
{
    private const string FORM_REQUEST = 'Illuminate\Foundation\Http\FormRequest';

    /** @return list<string> */
    public static function of(ClassMethod $method): array
    {
        $classes = [];

        foreach ($method->params as $param) {
            $classes = [...$classes, ...self::typedRequests($param), ...self::attributeClasses($param)];
        }

        return array_values(array_unique($classes));
    }

    /** @return list<string> */
    private static function typedRequests(Param $param): array
    {
        return array_values(array_filter(
            self::typeNames($param->type),
            static fn (string $type): bool => AppNamespace::isInApp($type) && self::isSubclass($type, self::FORM_REQUEST),
        ));
    }

    /** @return list<string> */
    private static function attributeClasses(Param $param): array
    {
        $classes = [];

        foreach ($param->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                if (! self::isSubclass(AppFiles::resolveName($attribute->name), ContextualAttribute::class)) {
                    continue;
                }

                foreach ($attribute->args as $arg) {
                    $class = self::namedClass($arg->value);

                    if ($class !== null && AppNamespace::isInApp($class) && LoadableClass::exists($class)) {
                        $classes[] = $class;
                    }
                }
            }
        }

        return $classes;
    }

    /**
     * The class names a parameter type can hold. An intersection names no single class to build.
     *
     * @return list<string>
     */
    private static function typeNames(Identifier|Name|ComplexType|null $type): array
    {
        if ($type instanceof NullableType) {
            $type = $type->type;
        }

        if ($type instanceof Name) {
            return [AppFiles::resolveName($type)];
        }

        if (! $type instanceof UnionType) {
            return [];
        }

        return array_values(array_map(AppFiles::resolveName(...), array_filter($type->types, static fn (Node $member): bool => $member instanceof Name)));
    }

    private static function isSubclass(string $class, string $parent): bool
    {
        // `exists()` loads the class, and `is_subclass_of` does not autoload its second argument.
        return LoadableClass::exists($class) && is_subclass_of($class, $parent);
    }

    private static function namedClass(Expr $value): ?string
    {
        if (! $value instanceof ClassConstFetch || ! $value->class instanceof Name || ! $value->name instanceof Identifier) {
            return null;
        }

        return $value->name->toLowerString() === 'class' ? AppFiles::resolveName($value->class) : null;
    }
}
