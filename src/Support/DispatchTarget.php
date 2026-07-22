<?php declare(strict_types=1);

namespace SanderMuller\Richter\Support;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use SanderMuller\Richter\Analysis\AffectedTests;
use SanderMuller\Richter\Tracers\DispatchEdgeTracer;
use Throwable;

/**
 * Whether a class COULD be the target of an unresolved bus dispatch (plan 036, Design C) — the
 * shared predicate {@see AffectedTests}'s scoped S2 blocker uses to
 * decide whether a change's upward-caller closure contains a possible dispatch target. `$fqcn` is
 * never a confirmed dispatch target here (an unresolved dispatch's target is, by definition, not
 * statically known) — this only asks "is this class SHAPED like something that verb could reach".
 *
 * Covers every common dispatch-target shape: `\Jobs\`-namespaced classes, `ShouldQueue` jobs outside
 * that namespace, and `Dispatchable` commands/actions dispatched synchronously (`dispatch_sync`,
 * `dispatchNow`, `Bus::dispatch`) that are neither `\Jobs\` nor `ShouldQueue`. Documented residual
 * (maintainer sign-off given, plan 036): a class dispatched via `Bus::map` that uses NEITHER
 * `Dispatchable` NOR `ShouldQueue` NOR lives under `\Jobs\` (a rare, pre-`Dispatchable`-era pattern),
 * and a target of a project-configured `richter.dispatch_helpers` function that falls outside those
 * categories. Both are classified "not a target" here (when autoloadable) and could be missed — a
 * future `richter.dispatch_target_bases` config allowlist would close this if a consumer reports it.
 *
 * Queued Mailables/Notifications/Events/broadcasts are NOT a gap: `Mail::queue`/`notify()`/
 * `event()`/`broadcast()` are never counted S2 dispatch verbs (see {@see DispatchEdgeTracer}),
 * so their targets never reach this predicate in the first place.
 */
final class DispatchTarget
{
    /** @var array<string, bool> */
    private static array $cache = [];

    /**
     * Memoised — the same FQCN recurs often across one `affected-tests` run's upward-caller closure.
     */
    public static function matches(string $fqcn): bool
    {
        return self::$cache[$fqcn] ??= self::evaluate($fqcn);
    }

    /**
     * The class-existence guard runs FIRST and its failure short-circuits everything else — the
     * ordering is load-bearing. `is_subclass_of()`/`class_uses_recursive()` return `false`/`[]` for a
     * non-existent class without throwing, so checking them before (or instead of) confirming the
     * class is loadable would let a missing/unclassifiable class wrongly conclude "not a target" —
     * exactly the under-fire this predicate exists to prevent. Any autoload failure anywhere in this
     * evaluation (a missing class, a broken parent/trait file) is uncertainty, and uncertainty must
     * never resolve to "not a target" — so the whole check fails toward `true`, not `false` (unlike
     * {@see DispatchEdgeTracer::isQueueable()}'s mirrored try/catch,
     * which fails toward `false` because its own caller only ever wants a confident "yes, it's a
     * job").
     */
    private static function evaluate(string $fqcn): bool
    {
        try {
            if (! class_exists($fqcn)) {
                return true;
            }

            if (str_contains($fqcn, '\\Jobs\\')) {
                return true;
            }

            if (is_subclass_of($fqcn, ShouldQueue::class)) {
                return true;
            }

            return in_array(Dispatchable::class, class_uses_recursive($fqcn), true);
        } catch (Throwable) {
            return true;
        }
    }
}
