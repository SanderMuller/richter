<?php declare(strict_types=1);

namespace SanderMuller\Richter\Support;

use PhpParser\Node\ComplexType;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use ReflectionEnum;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

/**
 * The application type a dispatched factory call is declared to return.
 *
 * `dispatch(SomeJob::forRows($rows))`, `$job = $this->resolve(); dispatch($job);` and
 * `dispatch(SomeEnum::Case->job())` hide the job behind a call. The declared return type bounds the
 * dispatched object: PHP enforces it when the call returns, so the object IS an instance of that type
 * or of a subtype. The caller fans the type out to every dispatch target the application declares under it, which is the
 * same Class-Hierarchy-Analysis reading the graph applies to any call on an abstract type.
 *
 * Three receivers are read, and only these, because each one names the class whose method runs:
 *
 * - `$this->method()` and `self::`/`static::method()`: the class being traced. Its own AST is read
 *   first, because the class need not be loadable when it is traced; reflection covers an inherited
 *   method.
 * - `SomeClass::method()`: that class, by reflection.
 * - `SomeEnum::Case->method()`: the enum, by reflection. A class constant on a class that is no enum
 *   holds a value of a type this pass cannot see, so it is refused.
 *
 * Only a single named class type in the application namespace answers, nullable or not: a `null`
 * return dispatches nothing. A union, an intersection, a builtin, a missing type or a vendor type
 * (`ShouldQueue`, a framework base) answers null, and the caller keeps the site.
 *
 * @internal
 */
final class FactoryReturnType
{
    /**
     * @param  string|null  $scope  the class being traced, or null when the file cannot say which
     * @param  array<string, ClassMethod>  $ownMethods  that class's own methods, keyed by lowercased name
     */
    private static function of(?Expr $call, ?string $scope, array $ownMethods): ?string
    {
        $type = match (true) {
            $call instanceof MethodCall => self::ofMethodCall($call, $scope, $ownMethods),
            $call instanceof StaticCall => self::ofStaticCall($call, $scope, $ownMethods),
            default => null,
        };

        return $type !== null && AppNamespace::isInApp($type) ? $type : null;
    }

    /**
     * The type a dispatched value is declared as: a factory call written at the dispatch, or a local
     * bound once to one ABOVE the read ({@see LocallyConstructedJobs}). A binding below the read says
     * nothing about the value read, and a parser that attached no positions (-1) proves no order.
     *
     * @param  array<string, array{pos: int, call: MethodCall|StaticCall}>  $boundCalls
     * @param  array<string, ClassMethod>  $ownMethods
     */
    public static function ofDispatched(?Expr $value, array $boundCalls, ?string $scope, array $ownMethods): ?string
    {
        if ($value instanceof Variable && is_string($value->name)) {
            $bound = $boundCalls[$value->name] ?? null;
            $value = $bound !== null && $bound['pos'] >= 0 && $value->getStartFilePos() > $bound['pos'] ? $bound['call'] : null;
        }

        return self::of($value, $scope, $ownMethods);
    }

    /**
     * The traced class's own methods by lowercased name, or none when the file cannot say which class
     * `$this` is.
     *
     * @param  list<ClassLike>  $classLikes
     * @return array<string, ClassMethod>
     */
    public static function ownMethodsOf(array $classLikes, ?string $scope): array
    {
        if ($scope === null || count($classLikes) !== 1) {
            return [];
        }

        $methods = [];

        foreach ($classLikes[0]->getMethods() as $method) {
            $methods[$method->name->toLowerString()] = $method;
        }

        return $methods;
    }

    /** @param  array<string, ClassMethod>  $ownMethods */
    private static function ofMethodCall(MethodCall $call, ?string $scope, array $ownMethods): ?string
    {
        if (! $call->name instanceof Identifier) {
            return null;
        }

        $method = $call->name->toString();

        if ($call->var instanceof Variable && $call->var->name === 'this') {
            return $scope === null ? null : self::ofOwnMethod($scope, $method, $ownMethods);
        }

        // `SomeEnum::Case->method()`: an enum case is an instance of the enum, so its class is known.
        if ($call->var instanceof ClassConstFetch && $call->var->class instanceof Name && $call->var->name instanceof Identifier) {
            $class = self::receiverClass($call->var->class, $scope);

            return $class !== null && self::isEnumCase($class, $call->var->name->toString()) ? self::reflected($class, $method, $class) : null;
        }

        return null;
    }

    /** @param  array<string, ClassMethod>  $ownMethods */
    private static function ofStaticCall(StaticCall $call, ?string $scope, array $ownMethods): ?string
    {
        if (! $call->class instanceof Name || ! $call->name instanceof Identifier) {
            return null;
        }

        $method = $call->name->toString();
        $written = $call->class->toLowerString();

        // A subclass can declare its own method of the same name as a private one here, with any return
        // type, and `static::` then calls the subclass's.
        if ($written === 'static' && ($ownMethods[strtolower($method)] ?? null)?->isPrivate() === true) {
            return null;
        }

        if ($written === 'self' || $written === 'static') {
            return $scope === null ? null : self::ofOwnMethod($scope, $method, $ownMethods);
        }

        $class = self::receiverClass($call->class, $scope);

        // `static` in the declaration is the called class, which is the receiver written here.
        return $class === null ? null : self::reflected($class, $method, $class);
    }

    /** @param  array<string, ClassMethod>  $ownMethods */
    private static function ofOwnMethod(string $scope, string $method, array $ownMethods): ?string
    {
        $declared = $ownMethods[strtolower($method)] ?? null;

        if ($declared instanceof ClassMethod) {
            return self::fromAst($declared->returnType, $scope);
        }

        // Inherited: `static` binds to the traced class, so it is the type even though a parent declares it.
        return self::reflected($scope, $method, $scope);
    }

    private static function receiverClass(Name $name, ?string $scope): ?string
    {
        $written = $name->toLowerString();

        if ($written === 'parent') {
            return null;
        }

        if ($written === 'self' || $written === 'static') {
            return $scope;
        }

        return AppFiles::resolveName($name);
    }

    /**
     * `self` and `static` name the declaring class. For `static` that is the nearest class this can
     * prove: late static binding can name a subclass, and the caller's fan-out over subtypes covers it.
     */
    private static function fromAst(Identifier|Name|ComplexType|null $type, string $owner): ?string
    {
        if ($type instanceof NullableType) {
            $type = $type->type;
        }

        if ($type instanceof Identifier) {
            return $type->toLowerString() === 'static' ? $owner : null;
        }

        if (! $type instanceof Name) {
            return null;
        }

        $written = $type->toLowerString();

        if ($written === 'self' || $written === 'static') {
            return $owner;
        }

        return $written === 'parent' ? null : AppFiles::resolveName($type);
    }

    /**
     * The declared return type by reflection. Any failure to load or reflect is "cannot say": the
     * caller then keeps the site, which is the direction that costs nothing but a wider selection.
     *
     * @param  string  $calledClass  what `static` binds to at this call
     */
    private static function reflected(string $class, string $method, string $calledClass): ?string
    {
        try {
            if (! class_exists($class) && ! interface_exists($class)) {
                return null;
            }

            $reflection = new ReflectionMethod($class, $method);
            $type = $reflection->getReturnType();

            if (! $type instanceof ReflectionNamedType) {
                return null;
            }

            // Checked before isBuiltin(): PHP reports `static` as a builtin type.
            return match (strtolower($type->getName())) {
                'static' => $calledClass,
                'self' => $reflection->getDeclaringClass()->getName(),
                'parent' => null,
                default => $type->isBuiltin() ? null : $type->getName(),
            };
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * A case, not any constant: an enum constant can hold another enum's case.
     */
    private static function isEnumCase(string $class, string $constant): bool
    {
        try {
            return enum_exists($class) && new ReflectionEnum($class)->hasCase($constant);
        } catch (Throwable) {
            return false;
        }
    }
}
