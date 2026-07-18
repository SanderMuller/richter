<?php declare(strict_types=1);

namespace SanderMuller\Richter\Graph;

use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Property;
use PhpParser\NodeFinder;
use SanderMuller\Richter\Support\AppFiles;

/**
 * The app's route-middleware alias map (alias → FQCN), read statically from wherever the app
 * registers it: the classic Kernel `$middlewareAliases` property, or the Laravel 11+
 * `$middleware->alias([...])` call in bootstrap/app.php. Both value forms resolve: `::class`
 * references and class-string literals.
 */
final class MiddlewareAliases
{
    /** @return array<string, string> */
    public static function forProject(string $projectRoot): array
    {
        // Bootstrap wins on conflicts: Laravel 11+ reads bootstrap/app.php even when a legacy
        // Kernel stub is still around.
        return [
            ...self::fromKernel(self::sourceOf($projectRoot . '/app/Http/Kernel.php')),
            ...self::fromBootstrap(self::sourceOf($projectRoot . '/bootstrap/app.php')),
        ];
    }

    /**
     * The Kernel's `$middlewareAliases` map. The legacy `$routeMiddleware` property name is
     * deliberately not read — a dead branch for a name modern Kernels never use is speculative
     * generality.
     *
     * @return array<string, string>
     */
    public static function fromKernel(string $kernelSource): array
    {
        $ast = AppFiles::parseResolved($kernelSource);

        if ($ast === null) {
            return [];
        }

        $map = [];

        foreach (new NodeFinder()->findInstanceOf($ast, Property::class) as $property) {
            foreach ($property->props as $prop) {
                if ($prop->name->toString() === 'middlewareAliases' && $prop->default instanceof Array_) {
                    $map = [...$map, ...self::aliasesIn($prop->default)];
                }
            }
        }

        return $map;
    }

    /**
     * Aliases registered the Laravel 11+ way — `$middleware->alias([...])` in bootstrap/app.php.
     *
     * @return array<string, string>
     */
    public static function fromBootstrap(string $bootstrapSource): array
    {
        $ast = AppFiles::parseResolved($bootstrapSource);

        if ($ast === null) {
            return [];
        }

        $map = [];

        foreach (new NodeFinder()->findInstanceOf($ast, MethodCall::class) as $call) {
            if (! $call->name instanceof Identifier || $call->name->toString() !== 'alias') {
                continue;
            }

            $argument = $call->args[0]->value ?? null;

            if ($argument instanceof Array_) {
                $map = [...$map, ...self::aliasesIn($argument)];
            }
        }

        return $map;
    }

    /** @return array<string, string> */
    private static function aliasesIn(Array_ $aliases): array
    {
        $map = [];

        foreach ($aliases->items as $item) {
            if (! $item->key instanceof String_) {
                continue;
            }

            if ($item->value instanceof ClassConstFetch && $item->value->class instanceof Name) {
                $map[$item->key->value] = AppFiles::resolveName($item->value->class);
            } elseif ($item->value instanceof String_) {
                // Laravel also accepts a class-string literal as the alias value.
                $map[$item->key->value] = ltrim($item->value->value, '\\');
            }
        }

        return $map;
    }

    private static function sourceOf(string $path): string
    {
        return is_file($path) ? (string) file_get_contents($path) : '';
    }
}
