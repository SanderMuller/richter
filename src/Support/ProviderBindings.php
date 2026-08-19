<?php declare(strict_types=1);

namespace SanderMuller\Richter\Support;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\PropertyItem;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Property;
use PhpParser\NodeFinder;
use SanderMuller\Richter\Tracers\EntryPointTracer;
use SanderMuller\Richter\Tracers\FacadeEdgeTracer;

/**
 * One walk of `app/Providers`, read twice over: as the abstract → concrete `binding` edges
 * {@see EntryPointTracer::trace()} appends, and as the container-key map
 * {@see FacadeEdgeTracer} resolves a string accessor through.
 *
 * Scanned natively rather than via laravel-brain's container-binding analyzer, which skips
 * providers whose AST starts with Declare_ — every `declare(strict_types=1)` provider would
 * silently contribute zero binding edges. Two deliberate deltas vs Brain: providers with a
 * leading declare are scanned, and every class in a provider file is scanned, not only the first.
 *
 * The two products differ in what they will accept as the abstract. An edge needs a node id, so
 * {@see self::classLikeName()} keeps the namespace-separator rule that stops a container alias
 * (`'cache'`, `'db'`) from becoming a graph node. The map has no such constraint — it is a lookup
 * consumed at edge-emission time, never a node — so it keys the abstract as written: a string
 * literal verbatim, or the FQCN of a `Xxx::class`, which is how the container itself keys it.
 *
 * @internal
 */
final readonly class ProviderBindings
{
    /**
     * Container registration methods that take (abstract, concrete) arguments. All kinds collapse
     * into the same `binding` edge — the graph cares about reach, not lifecycle.
     *
     * @var list<string>
     */
    private const array BINDING_METHODS = ['bind', 'singleton', 'scoped', 'bindIf', 'singletonIf', 'scopedIf'];

    /**
     * @param  list<array{source: string, target: string, type: string}>  $edges  in the order the providers declare them
     * @param  array<string, string>  $keys  container key => concrete FQCN
     */
    private function __construct(public array $edges, public array $keys) {}

    public static function forProject(string $projectRoot): self
    {
        $registrations = [];

        foreach (AppFiles::phpClasses($projectRoot . '/app/Providers', $projectRoot) as $class) {
            $ast = AppFiles::parseResolved((string) file_get_contents($class['path']));

            if ($ast === null) {
                continue;
            }

            // Duplicate edges across providers are fine — trace() dedupes downstream.
            $registrations = [...$registrations, ...self::methodCallRegistrations($ast), ...self::propertyRegistrations($ast)];
        }

        return new self(self::edgesFrom($registrations), self::keysFrom($registrations));
    }

    /** An empty scan, for a caller that has no project to read (tests, a tracer used stand-alone). */
    public static function none(): self
    {
        return new self([], []);
    }

    /**
     * The `binding` edges, in registration order — the sequence {@see EntryPointTracer::trace()}
     * appends and the serial/concurrent byte-equality gate pins.
     *
     * @param  list<array{key: string|null, node: string|null, concrete: string|null}>  $registrations
     * @return list<array{source: string, target: string, type: string}>
     */
    private static function edgesFrom(array $registrations): array
    {
        $edges = [];

        foreach ($registrations as $registration) {
            if ($registration['node'] === null || $registration['concrete'] === null) {
                continue;
            }

            $edges[] = ['source' => $registration['node'], 'target' => $registration['concrete'], 'type' => 'binding'];
        }

        return $edges;
    }

    /**
     * The container keys that name exactly one concrete.
     *
     * A key two registrations disagree on is dropped rather than resolved by precedence: the
     * runtime winner of `bindIf('reports', A::class)` followed by `bind('reports', B::class)` is
     * knowable, but modelling it would put richter one registration shape away from naming the
     * wrong file. The same abort every contract parser here makes on an unenumerable key set.
     *
     * @param  list<array{key: string|null, node: string|null, concrete: string|null}>  $registrations
     * @return array<string, string>
     */
    private static function keysFrom(array $registrations): array
    {
        $concretesByKey = [];

        foreach ($registrations as $registration) {
            if ($registration['key'] === null || $registration['concrete'] === null) {
                continue;
            }

            $concretesByKey[$registration['key']][$registration['concrete']] = true;
        }

        $keys = [];

        foreach ($concretesByKey as $key => $concretes) {
            if (count($concretes) === 1) {
                $keys[(string) $key] = array_key_first($concretes);
            }
        }

        return $keys;
    }

    /**
     * Registrations made by call — `->bind(Abstract::class, Concrete::class)` and friends — on an
     * app-like receiver: `$this->app`, an `$app` variable (closure-injected container), or the
     * `app()` helper.
     *
     * @param  list<Stmt>  $ast  a name-resolved AST ({@see AppFiles::parseResolved()})
     * @return list<array{key: string|null, node: string|null, concrete: string|null}>
     */
    private static function methodCallRegistrations(array $ast): array
    {
        $registrations = [];

        foreach (new NodeFinder()->findInstanceOf($ast, MethodCall::class) as $call) {
            $registration = self::methodCallRegistration($call);

            if ($registration !== null) {
                $registrations[] = $registration;
            }
        }

        return $registrations;
    }

    /**
     * The registration one method call makes, or null when it isn't a two-plus-argument binding
     * call on an app-like receiver. A one-argument bind (concrete self-binding) adds nothing the
     * class node doesn't already imply.
     *
     * @return array{key: string|null, node: string|null, concrete: string|null}|null
     */
    private static function methodCallRegistration(MethodCall $call): ?array
    {
        // `->bind(...)` (first-class callable) registers nothing, and getArgs() on it throws.
        if (! $call->name instanceof Identifier
            || ! in_array($call->name->toString(), self::BINDING_METHODS, true)
            || ! self::isAppLikeReceiver($call->var)
            || $call->isFirstClassCallable()) {
            return null;
        }

        $args = $call->getArgs();

        return count($args) < 2 ? null : self::registration($args[0]->value, $args[1]->value);
    }

    /**
     * Registrations declared via the non-static `$bindings` / `$singletons` provider properties,
     * where each array item maps abstract (key) to concrete (value).
     *
     * @param  list<Stmt>  $ast  a name-resolved AST ({@see AppFiles::parseResolved()})
     * @return list<array{key: string|null, node: string|null, concrete: string|null}>
     */
    private static function propertyRegistrations(array $ast): array
    {
        $registrations = [];

        foreach (new NodeFinder()->findInstanceOf($ast, Property::class) as $property) {
            foreach ($property->props as $prop) {
                $registrations = [...$registrations, ...self::declaredRegistrations($property, $prop)];
            }
        }

        return $registrations;
    }

    /**
     * The registrations one declared property contributes — a non-static `$bindings`/`$singletons`
     * with an array default; each item maps abstract (key) to concrete (value).
     *
     * @return list<array{key: string|null, node: string|null, concrete: string|null}>
     */
    private static function declaredRegistrations(Property $property, PropertyItem $prop): array
    {
        if ($property->isStatic()
            || ! in_array($prop->name->toString(), ['bindings', 'singletons'], true)
            || ! $prop->default instanceof Array_) {
            return [];
        }

        $registrations = [];

        foreach ($prop->default->items as $item) {
            // A keyless item (list-style entry) names no abstract, so it registers nothing.
            $registration = self::registration($item->key, $item->value);

            if ($registration['node'] !== null || $registration['key'] !== null) {
                $registrations[] = $registration;
            }
        }

        return $registrations;
    }

    /**
     * One registration read three ways: the key the container files it under, the node id an edge
     * may use for it, and the concrete FQCN. Any of them may be null — a closure concrete, an alias
     * abstract, a dynamic expression.
     *
     * @return array{key: string|null, node: string|null, concrete: string|null}
     */
    private static function registration(?Expr $abstract, Expr $concrete): array
    {
        return [
            'key' => self::containerKey($abstract),
            'node' => self::classLikeName($abstract),
            'concrete' => self::classLikeName($concrete),
        ];
    }

    /**
     * The string the container files a registration under: a string literal verbatim (an alias like
     * `'reports'`, and equally a key that happens to contain a backslash), or the resolved FQCN of
     * a `Xxx::class`. Anything else → null.
     *
     * No spelling test, unlike {@see self::classLikeName()}: this value never becomes a node, and
     * excluding class-looking strings would drop keys a facade accessor can legitimately return.
     */
    private static function containerKey(?Expr $expr): ?string
    {
        if ($expr instanceof String_) {
            return $expr->value;
        }

        return $expr instanceof ClassConstFetch ? self::classLikeName($expr) : null;
    }

    /**
     * A class-like expression's FQCN: `Xxx::class` (resolved through imports) or a string literal
     * naming a class — it must contain a namespace separator, so container aliases like `'cache'`
     * never become graph nodes. Anything else (null included) → null.
     */
    private static function classLikeName(?Expr $expr): ?string
    {
        if ($expr instanceof ClassConstFetch && $expr->name instanceof Identifier && $expr->name->toString() === 'class' && $expr->class instanceof Name) {
            return AppFiles::resolveName($expr->class);
        }

        if ($expr instanceof String_ && str_contains($expr->value, '\\') && preg_match('/^\\\\?[\w\\\\]+$/', $expr->value) === 1) {
            return ltrim($expr->value, '\\');
        }

        return null;
    }

    /** The three receiver shapes a container registration is made on: `$this->app`, `$app`, `app()`. */
    private static function isAppLikeReceiver(Expr $receiver): bool
    {
        if ($receiver instanceof PropertyFetch
            && $receiver->var instanceof Variable
            && $receiver->var->name === 'this'
            && $receiver->name instanceof Identifier
            && $receiver->name->toString() === 'app') {
            return true;
        }

        if ($receiver instanceof Variable && $receiver->name === 'app') {
            return true;
        }

        return $receiver instanceof FuncCall && $receiver->name instanceof Name && $receiver->name->toString() === 'app';
    }
}
