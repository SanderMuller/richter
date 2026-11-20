<?php declare(strict_types=1);

namespace SanderMuller\Richter\Support;

use ReflectionClass;
use Throwable;

/**
 * App methods that package code calls: a method that overrides one a package parent declares, or that
 * implements a package interface. A global scope's `apply()`, a cast's `get()` and a template-method
 * override all run this way, so no app call site names them and a change to one reached nothing.
 *
 * The nearest thing the source shows is the object being built. Each such method gets a `hook` edge
 * from its class's `__construct` node, which the `constructs` edges of every `new X(...)` already
 * reach: the code that builds the object reaches the hooks the package runs on it.
 *
 * The package side is read by reflection, because package source is not parsed.
 *
 * @internal
 */
final class FrameworkHooks
{
    /**
     * @param  array<string, array{parent: string|null, declared: list<string>}>  $inheritance
     * @return list<array{source: string, target: string, type: string}>
     */
    public static function edges(array $inheritance): array
    {
        $edges = [];

        foreach ($inheritance as $fqcn => $record) {
            $declared = array_values(array_diff($record['declared'], ['__construct']));

            if ($declared === []) {
                continue;
            }

            foreach (array_intersect($declared, self::packageMethods($fqcn)) as $method) {
                $edges[] = ['source' => "{$fqcn}::__construct", 'target' => "{$fqcn}::{$method}", 'type' => 'hook'];
            }
        }

        return $edges;
    }

    /**
     * The methods the class's package ancestors and package interfaces declare.
     *
     * @return list<string>
     */
    private static function packageMethods(string $fqcn): array
    {
        try {
            if (! class_exists($fqcn)) {
                return [];
            }

            $class = new ReflectionClass($fqcn);
            $types = [...$class->getInterfaces()];

            for ($parent = $class->getParentClass(); $parent !== false; $parent = $parent->getParentClass()) {
                if (! AppNamespace::isInApp($parent->getName())) {
                    $types[] = $parent;

                    break;
                }
            }

            $methods = [];

            foreach ($types as $type) {
                if (AppNamespace::isInApp($type->getName())) {
                    continue;
                }

                foreach ($type->getMethods() as $method) {
                    if (! $method->isPrivate()) {
                        $methods[] = $method->getName();
                    }
                }
            }

            return array_values(array_unique($methods));
        } catch (Throwable) {
            return [];
        }
    }
}
