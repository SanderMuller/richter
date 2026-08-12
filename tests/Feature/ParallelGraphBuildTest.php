<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Graph\CodeGraphBuilder;
use SanderMuller\Richter\Graph\PendingTracerBranch;
use SanderMuller\Richter\Graph\TracerBranchRunner;
use SanderMuller\Richter\Tests\TestCase;

/**
 * Plan 050: the graph build runs Brain's branch and richter's tracer branch concurrently (the
 * tracer branch in a child artisan process). These prove the concurrent path is byte-identical to
 * the serial one and that any worker failure falls back to serial without throwing.
 */
final class ParallelGraphBuildTest extends TestCase
{
    #[Test]
    public function the_parallel_build_produces_a_graph_identical_to_the_serial_build(): void
    {
        config()->set('richter.parallel', false);
        $serial = new CodeGraphBuilder()->build(self::fixtureProjectPath());

        config()->set('richter.parallel', true);
        $parallel = new CodeGraphBuilder()->build(self::fixtureProjectPath());

        // Byte-identical, edge order included — the hard gate for plan 050. Holds whether the child
        // fork runs (exercising the merge) or falls back to serial when it can't, so this is stable
        // across environments (CI's bare Testbench skeleton can't boot the package in a raw
        // subprocess, so it lands on the fallback path there). The live fork is validated in a host
        // app; the worker's OUTPUT and finish()'s handling are covered in-process below.
        $this->assertEquals($serial, $parallel);
    }

    #[Test]
    public function the_worker_command_writes_json_matching_the_in_process_branch(): void
    {
        $out = (string) tempnam(sys_get_temp_dir(), 'richter-test-');

        try {
            $exit = Artisan::call('richter:internal-tracer-branch', [
                '--project' => self::fixtureProjectPath(),
                '--out' => $out,
            ]);
            $this->assertSame(0, $exit);

            $decoded = json_decode((string) file_get_contents($out), true);
        } finally {
            @unlink($out);
        }

        $this->assertEquals(new CodeGraphBuilder()->buildTracerBranch(self::fixtureProjectPath()), $decoded);
    }

    #[Test]
    public function finish_returns_null_when_the_worker_exits_nonzero(): void
    {
        $process = Process::path(base_path())->start([PHP_BINARY, '-r', 'exit(1);']);

        // Non-zero exit → null so build() falls back to the in-process branch.
        $this->assertNull(TracerBranchRunner::finish(new PendingTracerBranch($process, '/no/such/richter/output')));
    }

    #[Test]
    public function finish_returns_null_on_malformed_worker_output(): void
    {
        $out = (string) tempnam(sys_get_temp_dir(), 'richter-test-');
        file_put_contents($out, '{ this is not valid json');
        $process = Process::path(base_path())->start([PHP_BINARY, '-r', 'exit(0);']);

        // A truncated / corrupt payload must fail closed to null (serial fallback), never a wrong graph.
        $this->assertNull(TracerBranchRunner::finish(new PendingTracerBranch($process, $out)));
        $this->assertFileDoesNotExist($out, 'finish() cleans up the temp file');
    }

    #[Test]
    public function finish_rejects_a_semantically_impossible_payload(): void
    {
        // A negative count (or a non-list edges map) can't come from the worker; it's corruption,
        // and validate() must fail closed to serial rather than build a graph with false flags.
        $out = (string) tempnam(sys_get_temp_dir(), 'richter-test-');
        file_put_contents($out, (string) json_encode(['edges' => [], 'unparseableFiles' => -1, 'unresolvedDispatches' => 0]));
        $process = Process::path(base_path())->start([PHP_BINARY, '-r', 'exit(0);']);

        $this->assertNull(TracerBranchRunner::finish(new PendingTracerBranch($process, $out)));
    }

    #[Test]
    public function finish_rejects_a_payload_with_a_mis_shaped_inheritance_map(): void
    {
        // Same fail-closed rule as the edges: a wrong inheritance map would draw `inherits` edges to
        // methods that do not run, so serial-and-correct beats parallel-and-wrong.
        $out = (string) tempnam(sys_get_temp_dir(), 'richter-test-');
        file_put_contents($out, (string) json_encode([
            'edges' => [],
            'unparseableFiles' => 0,
            'unresolvedDispatches' => 0,
            'inheritance' => ['App\\Services\\Child' => ['parent' => 42, 'declared' => ['handle']]],
            'declares' => [],
        ]));
        $process = Process::path(base_path())->start([PHP_BINARY, '-r', 'exit(0);']);

        $this->assertNull(TracerBranchRunner::finish(new PendingTracerBranch($process, $out)));
    }

    #[Test]
    public function finish_rejects_a_payload_with_a_mis_shaped_declares_map(): void
    {
        // These become `declares` edges verbatim, so a mis-shaped entry would hang a member node off
        // a class that does not declare it — the same fail-closed rule the other two maps get.
        $out = (string) tempnam(sys_get_temp_dir(), 'richter-test-');
        file_put_contents($out, (string) json_encode([
            'edges' => [],
            'unparseableFiles' => 0,
            'unresolvedDispatches' => 0,
            'inheritance' => [],
            'declares' => ['App\\Services\\Child' => [['source' => 'App\\Services\\Child', 'target' => 42, 'type' => 'declares']]],
        ]));
        $process = Process::path(base_path())->start([PHP_BINARY, '-r', 'exit(0);']);

        $this->assertNull(TracerBranchRunner::finish(new PendingTracerBranch($process, $out)));
    }

    #[Test]
    public function finish_returns_the_validated_branch_on_success(): void
    {
        $out = (string) tempnam(sys_get_temp_dir(), 'richter-test-');
        $branch = [
            'edges' => [['source' => 'A::m', 'target' => 'B', 'type' => 'call']],
            'unparseableFiles' => 0,
            'unresolvedDispatches' => 2,
            // The worker also carries out the inheritance map, which the parent applies to the merged
            // edge set — a payload without it is not this worker's output. Same for the declares map:
            // the parent looks members up there rather than re-parsing every app class file.
            'inheritance' => ['App\\Services\\Child' => ['parent' => 'App\\Services\\Base', 'declared' => ['handle']]],
            'declares' => ['App\\Services\\Child' => [['source' => 'App\\Services\\Child', 'target' => 'App\\Services\\Child::handle', 'type' => 'declares']]],
        ];
        file_put_contents($out, (string) json_encode($branch));
        $process = Process::path(base_path())->start([PHP_BINARY, '-r', 'exit(0);']);

        $this->assertSame($branch, TracerBranchRunner::finish(new PendingTracerBranch($process, $out)));
    }
}
