<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tracers;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Model;
use ReflectionClass;
use SanderMuller\Richter\Support\AppNamespace;
use Throwable;

/**
 * Which model each factory under `database/factories` builds, for {@see ModelFactoryTracer}.
 *
 * @internal
 */
final class FactoryModels
{
    private const string MODEL = 'Illuminate\\Database\\Eloquent\\Model';

    private const string USE_FACTORY = 'Illuminate\\Database\\Eloquent\\Attributes\\UseFactory';

    /**
     * Factory class => the model it builds. The model's own declaration wins: a `#[UseFactory]`
     * attribute, then Laravel's resolver (which honours `Factory::guessFactoryNamesUsing()`), then
     * the default naming under this app's root namespace. A factory no model claims falls back to
     * its `$model` property, the shape a model's `newFactory()` override pairs with.
     *
     * @param  list<string>  $classes
     * @param  array<string, mixed>  $factories
     * @return array<string, string>
     */
    public static function byFactory(array $classes, array $factories): array
    {
        $modelOf = [];

        foreach ($classes as $class) {
            foreach (self::factoryNamesOf($class) as $factory) {
                if (isset($factories[$factory])) {
                    $modelOf[$factory] ??= $class;

                    break;
                }
            }
        }

        foreach ($factories as $factory => $record) {
            if (is_array($record) && is_string($record['model'] ?? null) && ! in_array($record['model'], $modelOf, true) && self::isFactoryModel($record['model'])) {
                $modelOf[$factory] ??= $record['model'];
            }
        }

        ksort($modelOf);

        return $modelOf;
    }

    /**
     * The factory a source file declares and the model it builds, or null when the two cannot be tied.
     *
     * The inverse of {@see byFactory()} for ONE file, without the class list the build has. The
     * factory's own `$model` property is read first, then the default naming in reverse. Either
     * candidate must be a model that has a factory, and a candidate from the naming must name this
     * factory back. A model that pairs through `#[UseFactory]` alone with a name outside the
     * convention is not found, and the caller treats that as unknown.
     *
     * @return array{factory: string, model: string}|null
     */
    public static function builtBy(string $source): ?array
    {
        if (preg_match('/^namespace\s+([\w\\\\]+)\s*;/m', $source, $namespace) !== 1
            || preg_match('/^\s*(?:final\s+)?class\s+(\w+)/m', $source, $class) !== 1
        ) {
            return null;
        }

        $factory = "{$namespace[1]}\\{$class[1]}";

        if (preg_match('/\$model\s*=\s*\\\\?([\w\\\\]+)::class/', $source, $property) === 1) {
            $model = self::resolveAgainst($property[1], $namespace[1], $source);

            return self::isFactoryModel($model) ? ['factory' => $factory, 'model' => $model] : null;
        }

        $prefix = 'Database\\Factories\\';

        if (! str_starts_with($factory, $prefix) || ! str_ends_with($factory, 'Factory')) {
            return null;
        }

        $name = substr($factory, strlen($prefix), -strlen('Factory'));
        $root = AppNamespace::root();

        foreach (["{$root}Models\\{$name}", "{$root}{$name}"] as $model) {
            if (in_array($factory, self::factoryNamesOf($model), strict: true)) {
                return ['factory' => $factory, 'model' => $model];
            }
        }

        return null;
    }

    /** A class name as the file means it: fully qualified, imported, or relative to the namespace. */
    private static function resolveAgainst(string $name, string $namespace, string $source): string
    {
        if (str_contains($name, '\\')) {
            return ltrim($name, '\\');
        }

        $quoted = preg_quote($name, '/');

        if (preg_match('/^use\s+\\\\?([\w\\\\]+\\\\' . $quoted . '|[\w\\\\]+\s+as\s+' . $quoted . ')\s*;/m', $source, $import) === 1) {
            return (string) preg_replace('/\s+as\s+\w+$/', '', $import[1]);
        }

        return "{$namespace}\\{$name}";
    }

    /**
     * The factory names the model can mean, in order of precedence.
     *
     * @return list<string>
     */
    public static function factoryNamesOf(string $class): array
    {
        if (! self::isFactoryModel($class)) {
            return [];
        }

        $names = [];

        try {
            foreach (new ReflectionClass($class)->getAttributes(self::USE_FACTORY) as $attribute) {
                $named = $attribute->getArguments()[0] ?? null;

                if (is_string($named)) {
                    return [ltrim($named, '\\')];
                }
            }

            if (class_exists(Factory::class)) {
                $names[] = Factory::resolveFactoryName($class);
            }
        } catch (Throwable) {
            // The default naming below still applies.
        }

        $root = AppNamespace::root();
        $models = "{$root}Models\\";
        $names[] = 'Database\\Factories\\' . (str_starts_with($class, $models) ? substr($class, strlen($models)) : substr($class, strlen($root))) . 'Factory';

        return $names;
    }

    /**
     * An app model that uses `HasFactory`, so `X::factory()` exists.
     *
     * @phpstan-assert-if-true class-string<Model> $class
     */
    public static function isFactoryModel(string $class): bool
    {
        try {
            return AppNamespace::isInApp($class)
                && class_exists($class)
                && is_subclass_of($class, self::MODEL)
                && method_exists($class, 'factory')
                && ! new ReflectionClass($class)->isAbstract();
        } catch (Throwable) {
            return false;
        }
    }
}
