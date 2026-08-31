<?php declare(strict_types=1);

namespace SanderMuller\Richter\Support;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use SanderMuller\Richter\Analysis\AffectedTests;
use SanderMuller\Richter\Tracers\DispatchEdgeTracer;
use Throwable;

/**
 * Whether a class COULD be the target of an unresolved bus dispatch (plan 036, Design C) — the one
 * shared definition of "dispatch target" across two callers: {@see AffectedTests}'s scoped S2
 * blocker (does a change's upward-caller closure contain a possible dispatch target?) and, since
 * plan 043, {@see DispatchEdgeTracer}'s resolved-dispatch edge-drawer (which instantiation/dispatch
 * targets get an `action-to-job` edge). `$fqcn` is never a confirmed dispatch target here (an
 * unresolved dispatch's target is, by definition, not statically known) — this only asks "is this
 * class SHAPED like something that verb could reach".
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
     * The class-existence guard runs FIRST and its failure short-circuits everything else. The
     * ordering matters: `is_subclass_of()`/`class_uses_recursive()` return `false`/`[]` for a
     * non-existent class without throwing, so checking them before (or instead of) confirming the
     * class is loadable would let a missing/unclassifiable class wrongly conclude "not a target" —
     * exactly the under-fire this predicate exists to prevent. Any autoload failure anywhere in this
     * evaluation (a missing class, a broken parent/trait file) is uncertainty, and uncertainty must
     * never resolve to "not a target" — so the whole check fails toward `true`, not `false`. Both
     * callers want that direction: as a determinability blocker (plan 036) an uncertain class must
     * block the change, and as the edge-drawer's target test (plan 043) an uncertain class must
     * still draw the `action-to-job` edge — an extra edge over-selects, never under.
     */
    private static function evaluate(string $fqcn): bool
    {
        try {
            if (! class_exists($fqcn)) {
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
            if (self::isIntrinsic($fqcn)) {
                return true;
            }

            if (method_exists($fqcn, 'handle')) {
                return true;
            }

            return method_exists($fqcn, '__invoke');
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * A class worth linking from a bare `new X(...)` ({@see DispatchEdgeTracer}'s instantiation
     * over-approximation) even with no dispatch verb in sight: an INTRINSIC dispatch target —
     * dispatchable by declaration (`\Jobs\`-namespaced, `ShouldQueue`, or using `Dispatchable`), so a
     * `new` of one is very likely bound for a dispatch, including through a helper the tracer doesn't
     * recognise — OR a class that can't be resolved (uncertainty → fail toward could-be). A class that
     * is a dispatch target ONLY because it carries handle()/__invoke() is deliberately excluded: that
     * plain shape is a dispatch target only when a dispatch verb actually runs it, and countless value
     * objects share it — so the tracer links it from an instantiation only inside a dispatching method.
     * (This is a subset of {@see matches()}, not a replacement: {@see matches()} still fires on the
     * handle()/__invoke() shape, which the S2 determinability blocker and a resolved dispatch need.)
     */
    public static function isIntrinsicOrUnresolvable(string $fqcn): bool
    {
        try {
            return ! class_exists($fqcn) || self::isIntrinsic($fqcn);
        } catch (Throwable) {
            return true;
        }
    }

    /**
     * Dispatchable by declaration: `\Jobs\`-namespaced, a `ShouldQueue` job, or using the
     * `Dispatchable` trait. Callers guard `class_exists()` first — `is_subclass_of()` /
     * `class_uses_recursive()` return `false`/`[]` for a missing class rather than confirming intent.
     */
    private static function isIntrinsic(string $fqcn): bool
    {
        return str_contains($fqcn, '\\Jobs\\')
            || is_subclass_of($fqcn, ShouldQueue::class)
            || in_array(Dispatchable::class, class_uses_recursive($fqcn), true);
    }
}
