<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Graph\SecondHopWalk;
use SanderMuller\Richter\Tests\Support\RecordingBodyWalk;
use SanderMuller\Richter\Tests\TestCase;

/**
 * The candidate selection, in isolation from the parsing. The walk itself is a stub that records
 * what it was asked to read, so these assertions are about which methods get chosen — the whole of
 * this class's design.
 */
final class SecondHopWalkTest extends TestCase
{
    #[Test]
    public function it_reads_the_method_a_static_call_targets(): void
    {
        $walk = new RecordingBodyWalk();

        new SecondHopWalk($walk(...), enabled: true)->edgesFor([
            ['source' => 'App\Console\Commands\Sync::handle', 'target' => 'App\Support\Registry::all', 'type' => 'static-call'],
        ], '/project');

        $this->assertSame([['App\Support\Registry::all']], $walk->asked);
        $this->assertSame('/project', $walk->askedFor);
    }

    #[Test]
    public function it_leaves_a_method_whose_body_was_already_read(): void
    {
        // A bare-typed edge out of the node is evidence the body was walked — by Brain through the
        // entry-point tracer, or by a configured entry-point root.
        $walk = new RecordingBodyWalk();

        new SecondHopWalk($walk(...), enabled: true)->edgesFor([
            ['source' => 'App\Console\Commands\Sync::handle', 'target' => 'App\Support\Registry::all', 'type' => 'static-call'],
            ['source' => 'App\Support\Registry::all', 'target' => 'App\Services\Reporter', 'type' => 'service'],
        ], '/project');

        $this->assertSame([], $walk->asked);
    }

    #[Test]
    public function a_dispatch_edge_is_not_read_as_evidence_of_a_walk(): void
    {
        // `action-to-job` is emitted by Brain's call chain AND by richter's per-file dispatch
        // tracer, which reads no body. Treating it as evidence would skip the classes this walk
        // exists to reach — the bug, rebuilt inside the fix.
        $walk = new RecordingBodyWalk();

        new SecondHopWalk($walk(...), enabled: true)->edgesFor([
            ['source' => 'App\Console\Commands\Sync::handle', 'target' => 'App\Support\Registry::all', 'type' => 'static-call'],
            ['source' => 'App\Support\Registry::all', 'target' => 'App\Jobs\Import::handle', 'type' => 'action-to-job'],
        ], '/project');

        $this->assertSame([['App\Support\Registry::all']], $walk->asked);
    }

    #[Test]
    public function it_does_nothing_when_disabled(): void
    {
        $walk = new RecordingBodyWalk();

        $result = new SecondHopWalk($walk(...), enabled: false)->edgesFor([
            ['source' => 'App\Console\Commands\Sync::handle', 'target' => 'App\Support\Registry::all', 'type' => 'static-call'],
        ], '/project');

        $this->assertSame([], $walk->asked);
        $this->assertSame(['edges' => [], 'unread' => 0], $result);
    }

    #[Test]
    public function every_static_call_target_is_asked_for_in_one_round(): void
    {
        // A chain of static calls needs no iteration: the per-file tracer has already emitted both
        // edges, so both targets are candidates from the start.
        $walk = new RecordingBodyWalk();

        new SecondHopWalk($walk(...), enabled: true)->edgesFor([
            ['source' => 'App\Console\Commands\Sync::handle', 'target' => 'App\Support\Registry::all', 'type' => 'static-call'],
            ['source' => 'App\Support\Registry::all', 'target' => 'App\Support\Builder::make', 'type' => 'static-call'],
        ], '/project');

        $this->assertSame([['App\Support\Builder::make', 'App\Support\Registry::all']], $walk->asked);
    }

    #[Test]
    public function the_same_target_reached_twice_is_read_once(): void
    {
        $walk = new RecordingBodyWalk();

        new SecondHopWalk($walk(...), enabled: true)->edgesFor([
            ['source' => 'App\Console\Commands\One::handle', 'target' => 'App\Support\Registry::all', 'type' => 'static-call'],
            ['source' => 'App\Console\Commands\Two::handle', 'target' => 'App\Support\Registry::all', 'type' => 'static-call'],
        ], '/project');

        $this->assertSame([['App\Support\Registry::all']], $walk->asked);
    }

    #[Test]
    public function it_passes_on_what_the_walk_could_not_read(): void
    {
        // An unreadable body produces no edges and no error, which is indistinguishable from a
        // method that calls nothing — so the count has to travel out to be reportable at all.
        $walk = new RecordingBodyWalk(unread: 2);

        $result = new SecondHopWalk($walk(...), enabled: true)->edgesFor([
            ['source' => 'App\Console\Commands\Sync::handle', 'target' => 'App\Support\Registry::all', 'type' => 'static-call'],
        ], '/project');

        $this->assertSame(2, $result['unread']);
    }

    #[Test]
    public function nothing_to_read_is_not_reported_as_unread(): void
    {
        $walk = new RecordingBodyWalk(unread: 7);

        $result = new SecondHopWalk($walk(...), enabled: true)->edgesFor([
            ['source' => 'App\Console\Commands\Sync::handle', 'target' => 'App\Services\Reporter', 'type' => 'service'],
        ], '/project');

        $this->assertSame([], $walk->asked);
        $this->assertSame(0, $result['unread']);
    }
}
