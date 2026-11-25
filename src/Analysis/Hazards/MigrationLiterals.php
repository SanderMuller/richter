<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis\Hazards;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassLike;

/**
 * A string argument written in place, or one the migration class declares: `$this->table`,
 * `self::TABLE`. Read from the class's own source, and null for anything else.
 *
 * @internal
 */
final class MigrationLiterals
{
    public static function string(?Node $argument, ?ClassLike $class): ?string
    {
        $value = $argument instanceof Arg ? $argument->value : null;

        $declared = match (true) {
            $value instanceof PropertyFetch => HazardSource::isThis($value->var) && $value->name instanceof Identifier
                ? self::property($class, $value->name->toString())
                : null,
            $value instanceof ClassConstFetch => $value->class instanceof Name
                && in_array($value->class->toLowerString(), ['self', 'static'], strict: true)
                && $value->name instanceof Identifier
                    ? self::constant($class, $value->name->toString())
                    : null,
            default => $value,
        };

        return $declared instanceof String_ ? $declared->value : null;
    }

    private static function property(?ClassLike $class, string $name): ?Expr
    {
        foreach ($class?->getProperty($name)->props ?? [] as $prop) {
            if ($prop->name->toString() === $name) {
                return $prop->default;
            }
        }

        return null;
    }

    private static function constant(?ClassLike $class, string $name): ?Expr
    {
        foreach ($class?->getConstants() ?? [] as $constant) {
            foreach ($constant->consts as $const) {
                if ($const->name->toString() === $name) {
                    return $const->value;
                }
            }
        }

        return null;
    }
}
