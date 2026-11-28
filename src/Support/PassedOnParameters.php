<?php declare(strict_types=1);

namespace SanderMuller\Richter\Support;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use SanderMuller\Richter\Tracers\DispatchEdgeTracer;

/**
 * The dispatches of a method's own parameter, as {@see DispatchEdgeTracer} meets them in one file.
 *
 * `Bus::batch($jobs)` on a parameter names no job. When the method only passes the parameter on,
 * the callers decide what it holds, and {@see ParameterDispatches} reads them once every class is
 * collected. This is the half that one file can answer: is the parameter passed straight through?
 *
 * @internal
 */
final class PassedOnParameters
{
    /**
     * The dispatch calls of one method that send a pass-through parameter, split into the ones that
     * hold (`records`) and the ones that do not (`sites`). `calls` is what is left for the tracer: a
     * call that is set aside here must not also be read as an ordinary dispatch.
     *
     * A parameter is passed straight through when its declaration and its dispatch reads are the only
     * mentions of the name. One more, and something in the method can change what the name holds.
     *
     * @param  list<Node>  $calls  the method's calls
     * @param  bool  $dispatches  whether the method holds a dispatch at all; when not, nothing is read
     * @param  callable(Node): (array{mode: 'single'|'array', arg: Arg|null}|array{mode: 'class', class: string}|null)  $dispatchSite  the tracer's own reading of a call
     * @return array{records: list<array{line: int, dispatcher: string, position: int, mode: 'single'|'array', protected: bool}>, sites: list<array{line: int, dispatcher: string}>, calls: list<Node>}
     */
    public static function in(ClassMethod $method, array $calls, callable $dispatchSite, string $dispatcher, bool $dispatches): array
    {
        $result = ['records' => [], 'sites' => [], 'calls' => $calls];
        $parameters = $dispatches ? self::passThroughParameters($method) : [];

        if ($parameters === []) {
            return $result;
        }

        $handled = [];

        $passedOn = [];

        foreach ($calls as $call) {
            $site = $call instanceof CallLike && $call->isFirstClassCallable() ? null : $dispatchSite($call);
            $arg = $site !== null && $site['mode'] !== 'class' ? $site['arg'] : null;

            if (! $arg instanceof Arg || $arg->unpack || $arg->name instanceof Identifier || ! $arg->value instanceof Variable || ! is_string($arg->value->name)) {
                continue;
            }

            $position = $parameters[$arg->value->name] ?? null;

            if ($position !== null) {
                $handled[spl_object_id($call)] = true;
                $passedOn[$arg->value->name][] = ['line' => $call->getStartLine(), 'dispatcher' => $dispatcher, 'position' => $position, 'mode' => $site['mode'], 'protected' => $method->isProtected()];
            }
        }

        foreach ($passedOn as $name => $records) {
            $mentions = count(new NodeFinder()->find([$method], static fn (Node $node): bool => $node instanceof Variable && $node->name === $name));
            $straightThrough = $mentions === 1 + count($records);

            foreach ($records as $record) {
                $straightThrough
                    ? $result['records'][] = $record
                    : $result['sites'][] = ['line' => $record['line'], 'dispatcher' => $record['dispatcher']];
            }
        }

        $result['calls'] = array_values(array_filter($calls, static fn (Node $call): bool => ! isset($handled[spl_object_id($call)])));

        return $result;
    }

    /**
     * The parameters a method can dispatch for its callers, name => position.
     *
     * Only a private or protected instance method: a public or static one is reachable by a call
     * that names no `$this`. Only a plain by-value parameter with no default but `[]`: a reference
     * can be rewritten by the callee's callee, a variadic collects several arguments, and a default
     * is a value no caller passed. And not in a method that hands its locals out by name.
     *
     * {@see passedOnBy()} still has to show the parameter is passed straight through.
     *
     * @return array<string, int>
     */
    private static function passThroughParameters(ClassMethod $method): array
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
}
