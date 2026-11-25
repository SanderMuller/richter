<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis\Hazards;

use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;

/**
 * What a migration runs: `up()`, the methods of its class it calls, and the tables they create.
 *
 * @internal
 */
final class MigrationMethods
{
    /**
     * `up()` and every method of its class reachable from it through `$this->…()`.
     *
     * @return list<ClassMethod>
     */
    public static function calledFrom(ClassMethod $up, ?ClassLike $class): array
    {
        $methods = [$up];

        for ($index = 0; $index < count($methods); ++$index) {
            foreach (HazardSource::within($methods[$index], MethodCall::class) as $call) {
                $called = HazardSource::isThis($call->var) && $call->name instanceof Identifier ? $class?->getMethod($call->name->toString()) : null;

                if ($called instanceof ClassMethod && ! in_array($called, $methods, strict: true)) {
                    $methods[] = $called;
                }
            }
        }

        return $methods;
    }

    /**
     * @param  list<ClassMethod>  $methods
     * @return list<string>
     */
    public static function createdTables(array $methods, ?ClassLike $class): array
    {
        $tables = [];

        foreach (new NodeFinder()->findInstanceOf($methods, StaticCall::class) as $call) {
            if (MigrationHazards::isSchemaFacade($call) && $call->name instanceof Identifier && $call->name->toString() === 'create') {
                $tables[] = MigrationLiterals::string($call->args[0] ?? null, $class);
            }
        }

        return array_values(array_filter($tables, is_string(...)));
    }
}
