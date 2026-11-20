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
     * The factory names the model can mean, in order of precedence.
     *
     * @return list<string>
     */
    private static function factoryNamesOf(string $class): array
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
