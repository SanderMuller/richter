<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tracers;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;
use SanderMuller\Richter\Analysis\TestReferenceIndex;

/**
 * App methods that return the URL of a named route: `$post->editUrl()` returning
 * `route('posts.edit', $this)`. A test that calls one drives that route without naming it, so the
 * test index read the test as unrelated to the route ({@see TestReferenceIndex}).
 *
 * A method counts when one of its `return` statements holds `route('name')`, `X::route('name')`
 * (`Uri`, `URL`), or a signed route, with a literal name. A redirect (`redirect()->route()`,
 * `to_route()`) is not a URL a test calls for.
 *
 * Only a name that every app method declaring it is such a helper is kept. A test is matched to a
 * helper by the name it calls alone, and a name another app method shares (`url`, `index`) matches
 * calls that have nothing to do with the route.
 *
 * @internal
 */
final class RouteUrlHelpers
{
    private const array ROUTE_STATICS = ['route', 'signedRoute', 'temporarySignedRoute'];

    private const array SIGNED = ['signedRoute', 'temporarySignedRoute'];

    /** @var array<string, int> method name => the app methods with a body that declare it */
    private array $declarers = [];

    /** @var array<string, int> method name => the helpers among those declarers */
    private array $helperDeclarers = [];

    /** @var array<string, array<string, true>> method name => the route names its helpers return */
    private array $routes = [];

    /** @param  list<ClassMethod>  $methods */
    public function collect(array $methods): void
    {
        $finder = new NodeFinder();

        foreach ($methods as $method) {
            if ($method->stmts === null) {
                continue;
            }

            $name = $method->name->toString();
            $this->declarers[$name] = ($this->declarers[$name] ?? 0) + 1;
            $routes = [];

            foreach ($finder->findInstanceOf($method->stmts, Return_::class) as $return) {
                foreach ($finder->find([$return], static fn (Node $node): bool => self::routeName($node) !== null) as $call) {
                    $routes[(string) self::routeName($call)] = true;
                }
            }

            if ($routes !== []) {
                $this->helperDeclarers[$name] = ($this->helperDeclarers[$name] ?? 0) + 1;
                $this->routes[$name] = ($this->routes[$name] ?? []) + $routes;
            }
        }
    }

    /**
     * The helper names that no other app method shares, each with the route names it returns.
     *
     * @return array<string, list<string>>
     */
    public function helpers(): array
    {
        $helpers = [];

        foreach ($this->routes as $name => $routes) {
            if ($this->helperDeclarers[$name] === $this->declarers[$name]) {
                $names = array_map(strval(...), array_keys($routes));
                sort($names);
                $helpers[$name] = $names;
            }
        }

        ksort($helpers);

        return $helpers;
    }

    /**
     * A {@see helpers()} map read back from a payload that crossed a process or cache boundary, or
     * null when the value is not one.
     *
     * @return array<string, list<string>>|null
     */
    public static function fromPayload(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $helpers = [];

        foreach ($value as $method => $routes) {
            if (! is_string($method) || ! is_array($routes) || ! array_is_list($routes) || ! array_all($routes, static fn (mixed $route): bool => is_string($route))) {
                return null;
            }

            /** @var list<string> $routes */
            $helpers[$method] = $routes;
        }

        return $helpers;
    }

    private static function routeName(Node $node): ?string
    {
        $isRouteCall = match (true) {
            $node instanceof FuncCall => $node->name instanceof Name && $node->name->toLowerString() === 'route',
            $node instanceof StaticCall => $node->name instanceof Identifier && in_array($node->name->toString(), self::ROUTE_STATICS, strict: true),
            $node instanceof MethodCall => $node->name instanceof Identifier && in_array($node->name->toString(), self::SIGNED, strict: true),
            default => false,
        };

        if (! $isRouteCall || ! $node instanceof CallLike || $node->isFirstClassCallable()) {
            return null;
        }

        $first = $node->getArgs()[0] ?? null;

        return $first instanceof Arg && $first->value instanceof String_ ? $first->value->value : null;
    }
}
