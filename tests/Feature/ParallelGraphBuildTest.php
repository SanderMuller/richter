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
 * Plan 046: the graph build runs Brain's branch and richter's tracer branch concurrently (the
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

        // Byte-identical, edge order included — the hard gate for plan 046.
        $this->assertEquals($serial, $parallel);
    }

    #[Test]
    public function the_worker_pipeline_returns_the_tracer_branch_end_to_end(): void
    {
        config()->set('richter.parallel', true);

        // A runnable artisan is required for the real spawn; Testbench provides the skeleton one.
        $this->assertFileExists(base_path('artisan'));

        $pending = TracerBranchRunner::start(self::fixtureProjectPath(), null);
        $this->assertInstanceOf(PendingTracerBranch::class, $pending, 'the worker should launch when parallel is on and artisan exists');

        $result = TracerBranchRunner::finish($pending);
        $this->assertNotNull($result, 'the worker should return a validated branch');

        // The child process must produce exactly the in-process branch.
        $this->assertEquals(new CodeGraphBuilder()->buildTracerBranch(self::fixtureProjectPath()), $result);
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
    public function finish_returns_the_validated_branch_on_success(): void
    {
        $out = (string) tempnam(sys_get_temp_dir(), 'richter-test-');
        $branch = [
            'edges' => [['source' => 'A::m', 'target' => 'B', 'type' => 'call']],
            'unparseableFiles' => 0,
            'unresolvedDispatches' => 2,
        ];
        file_put_contents($out, (string) json_encode($branch));
        $process = Process::path(base_path())->start([PHP_BINARY, '-r', 'exit(0);']);

        $this->assertSame($branch, TracerBranchRunner::finish(new PendingTracerBranch($process, $out)));
    }
}
