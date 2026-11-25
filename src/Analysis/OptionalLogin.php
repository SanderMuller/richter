<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\Exit_;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Include_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Throw_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Catch_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Nop;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Node\Stmt\TryCatch;
use PhpParser\NodeFinder;
use ReflectionClass;
use SanderMuller\Richter\Analysis\Hazards\HazardSource;
use SanderMuller\Richter\Support\AppFiles;

/**
 * Whether a descendant of Laravel's `Authenticate` provably lets every guest through. The opposite
 * burden of proof from {@see GuestRefusal}: that one withholds evidence of a guard, so a doubt reads as
 * no refusal. This one contradicts a guard Brain found, so a doubt reads as not optional.
 *
 * Proven by a lone empty `unauthenticated()` override, or by a lone `handle()` override whose last
 * statement is `return $next($request)`, that returns nothing else, throws nothing, and calls only what reads the current user. What refuses
 * is allowed where a `catch` swallows it.
 *
 * @internal
 */
final class OptionalLogin
{
    private const string BASE = 'Illuminate\Auth\Middleware\Authenticate';

    /** Calls that refuse nobody: they read or set the current user, or `report()` an exception. */
    private const array READS = ['auth', 'guard', 'check', 'guest', 'user', 'id', 'hasuser', 'viaremember', 'shoulduse', 'setuser', 'bearertoken', 'report'];

    private const array AUTH_FACADES = ['Illuminate\Support\Facades\Auth', 'Auth'];

    private const array CATCH_AUTHENTICATION = ['Illuminate\Auth\AuthenticationException', 'Exception', 'Throwable'];

    /** @var array<string, bool> */
    private static array $proven = [];

    public static function by(string $class): bool
    {
        return self::$proven[$class] ??= AuthMiddlewareVocabulary::loadable($class) && is_subclass_of($class, self::BASE) && self::prove(new ReflectionClass($class));
    }

    /** @param  ReflectionClass<object>  $class */
    private static function prove(ReflectionClass $class): bool
    {
        $overridden = array_values(array_filter(
            ['handle', 'authenticate', 'unauthenticated'],
            static fn (string $name): bool => $class->hasMethod($name) && $class->getMethod($name)->getDeclaringClass()->getName() !== self::BASE,
        ));

        $method = $overridden !== [] ? GuestRefusal::sourceOf($class->getMethod($overridden[0])) : null;

        // With more than one of them replaced, what one does decides what the other means.
        return match (true) {
            ! $method instanceof ClassMethod => false,
            $overridden === ['unauthenticated'] => self::statementsOf($method) === [],
            default => $overridden === ['handle'] && self::alwaysContinues($method),
        };
    }

    private static function alwaysContinues(ClassMethod $handle): bool
    {
        [$request, $next] = [self::parameterName($handle, 0), self::parameterName($handle, 1)];
        $statements = self::statementsOf($handle);
        $last = array_last($statements);

        return is_string($request) && is_string($next)
            && $last instanceof Return_
            && self::isNext($last->expr, $next)
            && self::refusesNobody($statements, $request, $next);
    }

    private static function parameterName(ClassMethod $method, int $position): ?string
    {
        $variable = ($method->params[$position] ?? null)?->var;

        return $variable instanceof Variable && is_string($variable->name) ? $variable->name : null;
    }

    /**
     * Skips `Nop`, which php-parser makes of a comment on its own line.
     *
     * @return list<Stmt>
     */
    private static function statementsOf(ClassMethod $method): array
    {
        return array_values(array_filter($method->stmts ?? [], static fn (Stmt $statement): bool => ! $statement instanceof Nop));
    }

    /** @param  list<Stmt>  $statements */
    private static function refusesNobody(array $statements, string $request, string $next): bool
    {
        $nodes = new NodeFinder()->find($statements, static fn (Node $node): bool => true);

        // A closure runs only where something calls it, and nothing on the list of calls does.
        $closures = array_filter($nodes, static fn (Node $node): bool => $node instanceof FunctionLike);
        $tries = array_filter($nodes, self::catchesRefusal(...));

        return array_all($nodes, static fn (Node $node): bool => array_any($closures, static fn (Node $closure): bool => self::encloses([$closure], $node))
            || self::isHarmless($node, $request, $next, array_any($tries, static fn (TryCatch $try): bool => self::encloses($try->stmts, $node))));
    }

    /** @phpstan-assert-if-true TryCatch $node */
    private static function catchesRefusal(Node $node): bool
    {
        return $node instanceof TryCatch && array_any($node->catches, static fn (Catch_ $catch): bool => array_any(
            $catch->types,
            static fn (Name $type): bool => in_array(AppFiles::resolveName($type), self::CATCH_AUTHENTICATION, strict: true),
        ));
    }

    /** @param  array<array-key, Node>  $nodes  in the order they are written */
    private static function encloses(array $nodes, Node $inner): bool
    {
        $first = array_first($nodes);
        $last = array_last($nodes);

        return $first instanceof Node && $last instanceof Node
            && $first->getStartFilePos() <= $inner->getStartFilePos()
            && $inner->getEndFilePos() <= $last->getEndFilePos();
    }

    private static function isHarmless(Node $node, string $request, string $next, bool $caught): bool
    {
        return match (true) {
            $node instanceof Throw_, $node instanceof Exit_, $node instanceof Include_ => false,
            $node instanceof Return_ => self::isNext($node->expr, $next) || ($caught && self::isParentHandle($node->expr)),
            $node instanceof CallLike => self::isNext($node, $next) || self::isRead($node, $request) || ($caught && (self::isParentHandle($node) || self::isRefusal($node))),
            default => true,
        };
    }

    private static function isNext(?Node $node, string $next): bool
    {
        return $node instanceof FuncCall && $node->name instanceof Variable && $node->name->name === $next && ! $node->isFirstClassCallable();
    }

    /** A call of a name on the list, made on the request or the auth manager. Anything else can do anything. */
    private static function isRead(CallLike $call, string $request): bool
    {
        return match (true) {
            $call instanceof MethodCall, $call instanceof NullsafeMethodCall => self::isNamed($call->name, self::READS) && self::isAuthReceiver($call->var, $request),
            $call instanceof StaticCall => self::isAuthFacade($call) && self::isNamed($call->name, self::READS),
            $call instanceof FuncCall => self::isNamed($call->name, ['auth', 'report']),
            default => false,
        };
    }

    private static function isAuthReceiver(Expr $receiver, string $request): bool
    {
        return match (true) {
            $receiver instanceof Variable => $receiver->name === $request,
            $receiver instanceof FuncCall => self::isNamed($receiver->name, ['auth']),
            $receiver instanceof PropertyFetch => HazardSource::isThis($receiver->var) && self::isNamed($receiver->name, ['auth']),
            // A guard is the auth manager too; a user is a model, and a model's method can do anything.
            $receiver instanceof MethodCall, $receiver instanceof NullsafeMethodCall, $receiver instanceof StaticCall => self::isNamed($receiver->name, ['guard']) && self::isRead($receiver, $request),
            default => false,
        };
    }

    private static function isAuthFacade(StaticCall $call): bool
    {
        return $call->class instanceof Name && in_array(AppFiles::resolveName($call->class), self::AUTH_FACADES, strict: true);
    }

    private static function isRefusal(CallLike $call): bool
    {
        return ($call instanceof MethodCall || $call instanceof StaticCall) && self::isNamed($call->name, ['authenticate', 'unauthenticated']);
    }

    private static function isParentHandle(?Node $node): bool
    {
        return $node instanceof StaticCall
            && $node->class instanceof Name
            && $node->class->toLowerString() === 'parent'
            && self::isNamed($node->name, ['handle']);
    }

    /** @param  list<string>  $names */
    private static function isNamed(Node $name, array $names): bool
    {
        return ($name instanceof Identifier && in_array($name->toLowerString(), $names, strict: true))
            || ($name instanceof Name && in_array(strtolower($name->getLast()), $names, strict: true));
    }
}
