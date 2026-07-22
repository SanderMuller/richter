<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use App\Actions\GenerateReport;
use App\Commands\ArchiveStalePosts;
use App\Jobs\PublishPostJob;
use App\Models\Post;
use App\Notifications\PostDigestNotification;
use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Support\DispatchTarget;
use SanderMuller\Richter\Tests\TestCase;
use SanderMuller\Richter\Tracers\DispatchEdgeTracer;

/**
 * Unit coverage for {@see DispatchTarget::matches()} — the predicate plan 036's scoped S2 blocker
 * uses to decide whether a class could be the target of an unresolved bus dispatch. Every "true"
 * case below models a shape {@see DispatchEdgeTracer}'s counted
 * dispatch verbs can reach; the "false" case and the fail-toward-fire case are what makes the
 * predicate safe to use as a determinability blocker at all.
 */
final class DispatchTargetTest extends TestCase
{
    #[Test]
    public function a_jobs_namespaced_class_matches(): void
    {
        $this->assertTrue(DispatchTarget::matches(PublishPostJob::class));
    }

    #[Test]
    public function a_shouldqueue_class_outside_the_jobs_namespace_matches(): void
    {
        $this->assertTrue(DispatchTarget::matches(PostDigestNotification::class));
    }

    #[Test]
    public function a_dispatchable_trait_command_that_is_not_shouldqueue_matches(): void
    {
        // The A2 fix: v1's `\Jobs\`|ShouldQueue-only predicate missed exactly this shape — a
        // synchronous command dispatched via dispatch_sync()/dispatchNow()/Bus::dispatch().
        $this->assertTrue(DispatchTarget::matches(GenerateReport::class));
    }

    #[Test]
    public function a_plain_self_handling_command_matches(): void
    {
        // The codex-found category the Dispatchable-only predicate missed: a plain class with
        // handle() and no Dispatchable trait, run by dispatch($x) via BusDispatcher's dispatchNow
        // fallback. Under-selecting a change it reaches would violate the cardinal rule.
        $this->assertTrue(DispatchTarget::matches(ArchiveStalePosts::class));
    }

    #[Test]
    public function a_plain_class_with_no_handle_or_invoke_does_not_match(): void
    {
        // A model has neither handle()/__invoke() nor any dispatch trait — the unlock depends on
        // this staying false so a change reached only through such classes still narrows.
        $this->assertFalse(DispatchTarget::matches(Post::class));
    }

    #[Test]
    public function a_class_that_cannot_autoload_matches_fail_toward_fire(): void
    {
        // Uncertainty must never resolve to "not a target" — an unclassifiable caller (e.g. a
        // short controller id that failed to resolve to a real FQCN) fails toward "yes, could be".
        $this->assertTrue(DispatchTarget::matches('App\Does\Not\Exist'));
    }

    #[Test]
    public function repeated_calls_for_the_same_class_agree(): void
    {
        // The predicate is memoised — pinning that repeated lookups don't drift.
        $this->assertTrue(DispatchTarget::matches(PublishPostJob::class));
        $this->assertTrue(DispatchTarget::matches(PublishPostJob::class));
        $this->assertFalse(DispatchTarget::matches(Post::class));
        $this->assertFalse(DispatchTarget::matches(Post::class));
    }
}
