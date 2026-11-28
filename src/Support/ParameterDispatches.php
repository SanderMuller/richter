<?php declare(strict_types=1);

namespace SanderMuller\Richter\Support;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Expr\Yield_;
use PhpParser\Node\Expr\YieldFrom;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;
use SanderMuller\Richter\Graph\CodeGraphBuilder;
use SanderMuller\Richter\Tracers\ClassHierarchyTracer;
use SanderMuller\Richter\Tracers\DispatchEdgeTracer;

/**
 * Follows a dispatch of a PARAMETER back to the callers that fill it.
 *
 * ```php
 * protected function configureBatch(array $jobs): PendingBatch
 * {
 *     return Bus::batch($jobs);
 * }
 * ```
 *
 * Nothing in that method names a job, so {@see DispatchEdgeTracer} cannot follow it alone. But a
 * private or protected method has a closed set of callers ({@see ClassHierarchyTracer::closedFamilyOf()}),
 * and when every one of them passes an array that provably holds named jobs, the dispatch holds
 * exactly those. The tracer records the dispatch, and this reads the callers once
 * {@see CodeGraphBuilder} has collected every class.
 *
 * The proof has three parts, and the record becomes an unfollowable site again when one fails.
 *
 * 1. The dispatching method passes the parameter straight through. {@see passThroughParameters()}
 *    and the tracer's mention count hold that.
 * 2. Every mention of the method's NAME in the family is its declaration or a plain
 *    `$this->method(...)` call. A call on another receiver, a first-class callable, a callable
 *    string, and any call whose method name is not written out can each reach the method with a
 *    value this did not read.
 * 3. Each of those calls passes a proven value: an array the existing proofs accept, or the result of
 *    a `$this->helper()` whose every declaration in the family returns one.
 *
 * One level of helper only. A helper that returns another helper's result is not followed.
 *
 * @phpstan-type ParameterDispatch array{file: string, line: int, dispatcher: string, position: int, mode: 'single'|'array', protected: bool}
 *
 * @internal
 */
final class ParameterDispatches
{
    /** @var array<string, ClassLike|false> class-like FQCN => its node, or false when it could not be read */
    private array $classLikes = [];

    /**
     * @param  array<string, string>  $files  named class-like FQCN => the file that declares it
     */
    private function __construct(private readonly ClassHierarchyTracer $hierarchy, private readonly array $files) {}

    /**
     * The parameters a method can dispatch for its callers, name => position.
     *
     * Only a private or protected instance method: a public or static one is reachable by a call
     * that names no `$this`. Only a plain by-value parameter with no default but `[]`: a reference
     * can be rewritten by the callee's callee, a variadic collects several arguments, and a default
     * is a value no caller passed. And not in a method that hands its locals out by name.
     *
     * The caller still has to show the parameter is passed straight through: every mention of the
     * name is its declaration or a dispatch argument.
     *
     * @return array<string, int>
     */
    public static function passThroughParameters(ClassMethod $method): array
    {
        if ($method->isPublic() || $method->isStatic() || $method->stmts === null || LocallyConstructedJobs::exposesLocals($method)) {
            return [];
        }

        $parameters = [];

        foreach ($method->params as $position => $param) {
            $default = $param->default;

            if ($param->byRef
                || $param->variadic
                || $param->flags !== 0
                || ! $param->var instanceof Variable
                || ! is_string($param->var->name)
                || ($default !== null && (! $default instanceof Array_ || $default->items !== []))
            ) {
                continue;
            }

            $parameters[$param->var->name] = $position;
        }

        return $parameters;
    }

    /**
     * @param  list<ParameterDispatch>  $records
     * @param  array<string, string>  $files  named class-like FQCN => the file that declares it
     * @return array{edges: list<array{source: string, target: string, type: string}>, unresolvedSites: list<array{file: string, line: int, dispatcher: string}>}
     */
    public static function resolve(array $records, ClassHierarchyTracer $hierarchy, array $files): array
    {
        $resolver = new self($hierarchy, $files);
        $edges = [];
        $unresolvedSites = [];

        foreach ($records as $record) {
            $jobs = $resolver->jobsPassedTo($record);

            if ($jobs === null) {
                $unresolvedSites[] = ['file' => $record['file'], 'line' => $record['line'], 'dispatcher' => $record['dispatcher']];

                continue;
            }

            foreach ($jobs as $job) {
                $edges[] = ['source' => $record['dispatcher'], 'target' => "{$job}::handle", 'type' => 'action-to-job'];
            }
        }

        return ['edges' => $edges, 'unresolvedSites' => $unresolvedSites];
    }

    /**
     * Every job a caller can pass to the recorded parameter, or null when one caller is unknown.
     *
     * @param  ParameterDispatch  $record
     * @return list<string>|null
     */
    private function jobsPassedTo(array $record): ?array
    {
        [$class, $method] = [...explode('::', $record['dispatcher'], 2), ''];
        $family = $this->hierarchy->closedFamilyOf($class, withHierarchy: $record['protected']);

        if ($family === null) {
            return null;
        }

        $jobs = [];

        foreach ($family as $member) {
            $calls = $this->callsOf($method, $member);

            if ($calls === null) {
                return null;
            }

            foreach ($calls as ['call' => $call, 'in' => $caller]) {
                $passed = $this->jobsOfArgument($call, $record, $caller, $member);

                if ($passed === null) {
                    return null;
                }

                array_push($jobs, ...$passed);
            }
        }

        return array_values(array_unique($jobs));
    }

    /**
     * The plain `$this->method(...)` calls in one class-like, each with the method it sits in. Null
     * when the class-like mentions the name another way, or calls a method whose name is not written
     * out: either can reach the method with a value this never reads.
     *
     * @return list<array{call: MethodCall, in: ClassMethod}>|null
     */
    private function callsOf(string $method, string $member): ?array
    {
        $classLike = $this->classLike($member);

        if (! $classLike instanceof ClassLike) {
            return null;
        }

        $visitor = new class ($method) extends NodeVisitorAbstract {
            /** @var list<array{call: MethodCall, in: ClassMethod}> */
            public array $calls = [];

            public bool $open = false;

            /** @var list<ClassMethod> */
            private array $enclosing = [];

            public function __construct(private readonly string $method) {}

            public function enterNode(Node $node): null
            {
                if ($node instanceof ClassMethod) {
                    $this->enclosing[] = $node;

                    return null;
                }

                $this->open = $this->open || $this->reachesTheMethodUnseen($node);

                if ($node instanceof MethodCall && $node->name instanceof Identifier && $node->name->toString() === $this->method) {
                    $caller = end($this->enclosing);

                    if ($caller === false || ! $node->var instanceof Variable || $node->var->name !== 'this' || $node->isFirstClassCallable()) {
                        $this->open = true;

                        return null;
                    }

                    $this->calls[] = ['call' => $node, 'in' => $caller];
                }

                return null;
            }

            public function leaveNode(Node $node): null
            {
                if ($node instanceof ClassMethod) {
                    array_pop($this->enclosing);
                }

                return null;
            }

            private function reachesTheMethodUnseen(Node $node): bool
            {
                return match (true) {
                    // A method name that is not written out can be any name, this one included.
                    $node instanceof MethodCall, $node instanceof NullsafeMethodCall, $node instanceof StaticCall => ! $node->name instanceof Identifier
                        || (! $node instanceof MethodCall && $node->name->toString() === $this->method),
                    // The name as a string is how a callable names a method: `[$this, 'method']`.
                    $node instanceof String_ => $node->value === $this->method,
                    // `[$this, $name]` names a method of this object through a value.
                    $node instanceof Array_ => count($node->items) === 2
                        && $node->items[0]->value instanceof Variable
                        && $node->items[0]->value->name === 'this'
                        && ! $node->items[1]->value instanceof String_,
                    default => false,
                };
            }
        };

        new NodeTraverser($visitor)->traverse([$classLike]);

        return $visitor->open ? null : $visitor->calls;
    }

    /**
     * @param  ParameterDispatch  $record
     * @return list<string>|null
     */
    private function jobsOfArgument(MethodCall $call, array $record, ClassMethod $caller, string $callerClass): ?array
    {
        $args = $call->getArgs();

        // A named or spread argument puts a value at a position this does not compute.
        if (array_any($args, static fn (Node\Arg $arg): bool => $arg->unpack || $arg->name instanceof Identifier)) {
            return null;
        }

        $arg = $args[$record['position']] ?? null;

        // No argument: the parameter takes its default, which {@see passThroughParameters()} only
        // allows to be `[]`.
        if ($arg === null) {
            return $record['mode'] === 'array' ? [] : null;
        }

        $locals = LocallyConstructedJobs::in($caller);

        if ($record['mode'] === 'single') {
            return DispatchedElement::jobsOf($arg->value) ?? LocallyConstructedJobs::jobsBoundTo($arg->value, $locals['jobs']);
        }

        return $this->arrayOfJobs($arg->value, $locals)
            ?? $this->returnedBy($this->helperCallBehind($arg->value, $caller, $locals), $callerClass);
    }

    /**
     * The jobs an array-valued expression provably holds, by the proofs a dispatch written in the same
     * method would get: a literal of provable elements, a mapped collection, an accumulator.
     *
     * @param  array{jobs: array<string, array{pos: int, jobs: list<string>}>, collections: array<string, array{pos: int, jobs: list<string>}>, arrays: array{scopes: list<array{from: int, to: int}>, entries: array<string, array{pos: int, jobs: list<string>}>}, ...}  $locals
     * @return list<string>|null
     */
    private function arrayOfJobs(Expr $value, array $locals): ?array
    {
        if (! $value instanceof Array_) {
            return MappedCollectionJobs::in($value, $locals['collections']) ?? AccumulatedArrayJobs::dispatched($value, $locals['arrays']);
        }

        $jobs = [];

        foreach ($value->items as $item) {
            $element = DispatchedElement::jobsIn($item) ?? ($item->unpack ? null : LocallyConstructedJobs::jobsBoundTo($item->value, $locals['jobs']));

            if ($element === null) {
                return null;
            }

            array_push($jobs, ...$element);
        }

        return $jobs;
    }

    /**
     * The `$this->helper(...)` call whose result the argument is: written at the call, or bound once
     * to a local the caller only reads to pass on.
     *
     * An array is a value, so the local can be appended to between the binding and the call with no
     * new assignment to it. The local may therefore be mentioned exactly twice: the binding and the
     * argument.
     *
     * @param  array{calls: array<string, array{pos: int, call: MethodCall|StaticCall}>, ...}  $locals
     */
    private function helperCallBehind(Expr $value, ClassMethod $caller, array $locals): ?MethodCall
    {
        if ($value instanceof Variable && is_string($value->name)) {
            $bound = $locals['calls'][$value->name] ?? null;

            if ($bound === null
                || $bound['pos'] < 0
                || $value->getStartFilePos() <= $bound['pos']
                || LocallyConstructedJobs::exposesLocals($caller)
                || count(new NodeFinder()->find([$caller], static fn (Node $node): bool => $node instanceof Variable && $node->name === $value->name)) !== 2
            ) {
                return null;
            }

            $value = $bound['call'];
        }

        return $value instanceof MethodCall
            && $value->var instanceof Variable
            && $value->var->name === 'this'
            && $value->name instanceof Identifier
            && ! $value->isFirstClassCallable()
                ? $value
                : null;
    }

    /**
     * The jobs a helper returns, over EVERY declaration of it the call can run: the caller's own, an
     * ancestor's, a subclass override, a trait's. Null when one of them returns something unproven,
     * and when none has a body to read.
     *
     * @return list<string>|null
     */
    private function returnedBy(?MethodCall $helper, string $callerClass): ?array
    {
        if (! $helper instanceof MethodCall || ! $helper->name instanceof Identifier) {
            return null;
        }

        $family = $this->hierarchy->closedFamilyOf($callerClass, withHierarchy: true);

        if ($family === null) {
            return null;
        }

        $name = $helper->name->toString();
        $jobs = [];
        $read = 0;

        foreach ($family as $member) {
            $classLike = $this->classLike($member);

            if (! $classLike instanceof ClassLike) {
                return null;
            }

            $declaration = $classLike->getMethod($name);

            if (! $declaration instanceof ClassMethod || $declaration->stmts === null) {
                continue;
            }

            $returned = $this->jobsReturnedBy($declaration);

            if ($returned === null) {
                return null;
            }

            ++$read;
            array_push($jobs, ...$returned);
        }

        return $read === 0 ? null : $jobs;
    }

    /**
     * What one method body returns, when every `return` in it returns a proven array. Null for a
     * generator, a return by reference, a bare `return;`, and a body with no return.
     *
     * @return list<string>|null
     */
    private function jobsReturnedBy(ClassMethod $method): ?array
    {
        if ($method->returnsByRef()) {
            return null;
        }

        $visitor = new class extends NodeVisitorAbstract {
            /** @var list<Return_> */
            public array $returns = [];

            public bool $yields = false;

            public function enterNode(Node $node): ?int
            {
                // A nested function or class has its own returns.
                if ($node instanceof FunctionLike || $node instanceof ClassLike) {
                    return NodeVisitor::DONT_TRAVERSE_CHILDREN;
                }

                if ($node instanceof Return_) {
                    $this->returns[] = $node;
                }

                $this->yields = $this->yields || $node instanceof Yield_ || $node instanceof YieldFrom;

                return null;
            }
        };

        new NodeTraverser($visitor)->traverse($method->stmts ?? []);

        if ($visitor->yields || $visitor->returns === []) {
            return null;
        }

        $locals = LocallyConstructedJobs::in($method);
        $jobs = [];

        foreach ($visitor->returns as $return) {
            $returned = $return->expr instanceof Expr ? $this->arrayOfJobs($return->expr, $locals) : null;

            if ($returned === null) {
                return null;
            }

            array_push($jobs, ...$returned);
        }

        return $jobs;
    }

    private function classLike(string $fqcn): ?ClassLike
    {
        if (! array_key_exists($fqcn, $this->classLikes)) {
            $path = $this->files[$fqcn] ?? null;
            $ast = $path === null || ! is_file($path) ? null : AppFiles::parseResolved((string) file_get_contents($path));
            $found = $ast === null ? null : new NodeFinder()->findFirst(
                $ast,
                static fn (Node $node): bool => $node instanceof ClassLike && $node->namespacedName?->toString() === $fqcn,
            );

            $this->classLikes[$fqcn] = $found instanceof ClassLike ? $found : false;
        }

        return $this->classLikes[$fqcn] ?: null;
    }
}
