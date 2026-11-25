<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Throw_;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\TryCatch;
use PhpParser\NodeFinder;
use ReflectionClass;
use ReflectionMethod;
use SanderMuller\Richter\Analysis\Hazards\HazardSource;
use SanderMuller\Richter\Support\AppFiles;

/**
 * Whether a descendant of a framework auth middleware still refuses a guest. An optional-login
 * middleware extends the same base, overrides `handle()` and lets the guest through.
 *
 * Read from each override, up to the framework class: it must call the method it replaces, call what
 * refuses (`authenticate()`, `unauthenticated()`), or throw or abort by itself. A call a `catch` can
 * swallow is none of these, and neither is one in a closure. That a refusal sits behind a condition is
 * not read. An override whose source cannot be read does not count as one that refuses.
 *
 * @internal
 */
final class GuestRefusal
{
    private const array METHODS = ['handle', 'authenticate', 'unauthenticated'];

    private const array ABORTS = ['abort', 'abort_if', 'abort_unless'];

    /** @var array<class-string, bool> */
    private static array $refuses = [];

    /** @param  class-string  $class  one of {@see AuthMiddlewareVocabulary::AUTH_MIDDLEWARE_BASES}, or a descendant */
    public static function by(string $class): bool
    {
        return self::$refuses[$class] ??= array_all(self::METHODS, static fn (string $method): bool => self::refusesIn(new ReflectionClass($class), $method));
    }

    /** @param  ReflectionClass<object>  $class */
    private static function refusesIn(ReflectionClass $class, string $name): bool
    {
        if (! $class->hasMethod($name)) {
            return true;
        }

        $method = $class->getMethod($name);
        $declaring = $method->getDeclaringClass();

        if (in_array($declaring->getName(), AuthMiddlewareVocabulary::AUTH_MIDDLEWARE_BASES, strict: true)) {
            return true;
        }

        $calls = self::refusals($method);
        $parent = $declaring->getParentClass();

        return $calls['own'] || ($calls['parent'] && $parent !== false && self::refusesIn($parent, $name));
    }

    /** @return array{own: bool, parent: bool} */
    private static function refusals(ReflectionMethod $method): array
    {
        $node = self::sourceOf($method);
        $nodes = $node instanceof ClassMethod ? self::uncaught($node) : [];

        return [
            'own' => array_any($nodes, static fn (Node $node): bool => self::refuses($node, $method->getName())),
            'parent' => array_any($nodes, static fn (Node $node): bool => self::isCallTo($node, 'parent', [$method->getName()])),
        ];
    }

    /** The method as written, or null where its source cannot be read. */
    public static function sourceOf(ReflectionMethod $method): ?ClassMethod
    {
        $file = (string) $method->getFileName();
        $source = is_file($file) ? file_get_contents($file) : false;
        $ast = $source === false ? null : AppFiles::parseResolved($source);

        // By its last line, because an attribute moves the first one.
        $node = new NodeFinder()->findFirst($ast ?? [], static fn (Node $node): bool => $node instanceof ClassMethod
            && $node->name->toString() === $method->getName()
            && $node->getEndLine() === $method->getEndLine());

        return $node instanceof ClassMethod ? $node : null;
    }

    private static function refuses(Node $node, string $within): bool
    {
        return match (true) {
            $node instanceof Throw_ => true,
            $node instanceof FuncCall => $node->name instanceof Name && in_array($node->name->getLast(), self::ABORTS, strict: true),
            $node instanceof MethodCall => HazardSource::isThis($node->var) && self::isNamed($node, array_diff(['authenticate', 'unauthenticated'], [$within])),
            default => self::isCallTo($node, 'parent', array_diff(['authenticate', 'unauthenticated'], [$within])),
        };
    }

    /** @param  array<int, string>  $methods */
    private static function isCallTo(Node $node, string $class, array $methods): bool
    {
        return $node instanceof StaticCall
            && $node->class instanceof Name
            && $node->class->toLowerString() === $class
            && self::isNamed($node, $methods);
    }

    /** @param  array<int, string>  $methods */
    private static function isNamed(MethodCall|StaticCall $call, array $methods): bool
    {
        return ! $call->isFirstClassCallable() && $call->name instanceof Identifier && in_array($call->name->toString(), $methods, strict: true);
    }

    /**
     * The nodes that run as part of the method and whose refusal nothing catches.
     *
     * @return list<Node>
     */
    private static function uncaught(ClassMethod $method): array
    {
        $nodes = new NodeFinder()->find($method->stmts ?? [], static fn (Node $node): bool => true);
        $hidden = [];

        foreach ($nodes as $node) {
            if ($node instanceof FunctionLike) {
                $hidden[] = [$node->getStartFilePos(), $node->getEndFilePos()];
            } elseif ($node instanceof TryCatch && $node->catches !== [] && $node->stmts !== []) {
                $hidden[] = [$node->stmts[0]->getStartFilePos(), $node->stmts[array_key_last($node->stmts)]->getEndFilePos()];
            }
        }

        return array_values(array_filter($nodes, static fn (Node $node): bool => ! array_any(
            $hidden,
            static fn (array $range): bool => $range[0] <= $node->getStartFilePos() && $node->getEndFilePos() <= $range[1],
        )));
    }
}
