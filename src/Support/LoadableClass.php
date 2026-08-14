<?php declare(strict_types=1);

namespace SanderMuller\Richter\Support;

use Throwable;

/**
 * Whether a class name the tracers derived actually exists.
 *
 * The guard exists for one shape: an UNQUALIFIED name with no matching import resolves against the
 * file's own namespace, so a `new DateTimeImmutable()` written without its `use` reads as
 * `App\Services\DateTimeImmutable` — inside the app namespace by spelling, nonexistent in fact. An
 * edge to it invents a node. Nothing real is lost by requiring the class to load: a target richter
 * cannot autoload has no node from any other tracer either, so the edge could only point at a phantom.
 *
 * Memoised per process — the same receiver recurs across a file and across a whole build, and a miss
 * costs a failed autoload each time. A broken autoloader throwing here is uncertainty about a class
 * that, by definition, no other tracer could place: no edge.
 *
 * @internal
 */
final class LoadableClass
{
    /** @var array<string, bool> */
    private static array $loadable = [];

    public static function exists(string $fqcn): bool
    {
        try {
            return self::$loadable[$fqcn] ??= class_exists($fqcn) || interface_exists($fqcn) || enum_exists($fqcn);
        } catch (Throwable) {
            return self::$loadable[$fqcn] = false;
        }
    }
}
