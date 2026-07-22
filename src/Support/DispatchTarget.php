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
 * Covers every dispatch-target shape the counted verbs can reach: `\Jobs\`-namespaced classes,
 * `ShouldQueue` jobs outside that namespace, `Dispatchable` commands/actions, AND plain
 * self-handling commands — a class with `handle()` or `__invoke()` and no `Dispatchable` trait,
 * which `dispatch($x)`/`dispatch_sync($x)`/`Bus::dispatch($x)` still runs via Laravel's
 * `BusDispatcher::dispatchNow` fallback. That last rule is why the predicate can be a
 * determinability blocker safely — it is exactly the category the `\Jobs\`|`ShouldQueue`|
 * `Dispatchable`-only shape missed.
 *
 * Documented residual (maintainer sign-off given, plan 036 Option A), now narrow: a command
 * dispatched via `Bus::map` to a SEPARATE handler, where the command class itself has neither
 * `handle()`/`__invoke()` nor `Dispatchable`/`ShouldQueue`/`\Jobs\` (the handler carries `handle()`,
 * the command does not — a rare pre-`Dispatchable` pattern), and a target of a project-configured
 * `richter.dispatch_helpers` function that falls outside every rule above. Both are classified
 * "not a target" here (when autoloadable) and could be missed — a future
 * `richter.dispatch_target_bases` config allowlist would close this if a consumer reports it.
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

            if (in_array(Dispatchable::class, class_uses_recursive($fqcn), true)) {
                return true;
            }

            // A self-handling bus command: dispatch($x) / dispatch_sync($x) / Bus::dispatch($x) call
            // handle() or __invoke() on a plain object with NO Dispatchable trait (Laravel's
            // BusDispatcher::dispatchNow falls back to `container->call([$command, 'handle'|'__invoke'])`).
            // Such a class is a real unresolved-dispatch target, so it must match. In the caller
            // closure, controllers/middleware/models reach this predicate only as prefixed node ids
            // (route::/controller::/action::/middleware::/model::), which classOfNode() skips before
            // here; a directly-changed invokable controller or an event listener carrying handle() can
            // still match — an accepted safe OVER-selection, never under-selection.
            return method_exists($fqcn, 'handle') || method_exists($fqcn, '__invoke');
        } catch (Throwable) {
            return true;
        }
    }
}
