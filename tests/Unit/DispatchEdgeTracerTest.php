<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use Iterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Tests\TestCase;
use SanderMuller\Richter\Tracers\DispatchEdgeTracer;

final class DispatchEdgeTracerTest extends TestCase
{
    private const string DISPATCHER = 'App\Http\Controllers\VideoController';

    /**
     * @return list<array{source: string, target: string, type: string}>
     */
    private function edges(string $body, string $uses): array
    {
        $source = "<?php\nnamespace App\Http\Controllers;\n{$uses}\nclass VideoController\n{\n    public function store(): void\n    {\n        {$body}\n    }\n}\n";

        return new DispatchEdgeTracer()->edgesForSource($source, self::DISPATCHER)['edges'];
    }

    private function unresolved(string $body, string $uses): int
    {
        $source = "<?php\nnamespace App\Http\Controllers;\n{$uses}\nclass VideoController\n{\n    public function store(): void\n    {\n        {$body}\n    }\n}\n";

        return new DispatchEdgeTracer()->edgesForSource($source, self::DISPATCHER)['unresolved'];
    }

    /**
     * Every dispatch form the tracer recognises, plus the two non-dispatches it must ignore.
     *
     * @return Iterator<string, array{string, string, list<string>}>
     */
    public static function dispatchForms(): Iterator
    {
        $importJob = 'use App\Jobs\ImportJob;';
        $importAndBus = "use App\Jobs\ImportJob;\nuse Illuminate\Support\Facades\Bus;";
        $twoJobsAndBus = "use App\Jobs\ImportJob;\nuse App\Jobs\OtherJob;\nuse Illuminate\Support\Facades\Bus;";
        yield 'dispatch_with_retries helper' => ['dispatch_with_retries(new ImportJob());', $importJob, ['App\Jobs\ImportJob::handle']];
        yield 'Dispatchable $this->dispatch' => ['$this->dispatch(new ImportJob());', $importJob, ['App\Jobs\ImportJob::handle']];
        yield 'static Job::dispatch' => ['ImportJob::dispatch();', $importJob, ['App\Jobs\ImportJob::handle']];
        yield 'dispatch_sync helper' => ['dispatch_sync(new ImportJob());', $importJob, ['App\Jobs\ImportJob::handle']];
        yield 'dispatch_sync_with_retries helper' => ['dispatch_sync_with_retries(new ImportJob());', $importJob, ['App\Jobs\ImportJob::handle']];
        yield 'conditional dispatchAfterResponse' => ['ImportJob::dispatchAfterResponse();', $importJob, ['App\Jobs\ImportJob::handle']];
        yield 'conditional dispatchIf' => ['ImportJob::dispatchIf($cond);', $importJob, ['App\Jobs\ImportJob::handle']];
        yield 'Bus facade dispatch' => ['Bus::dispatch(new ImportJob());', $importAndBus, ['App\Jobs\ImportJob::handle']];
        yield 'aliased Bus facade' => ['QueueBus::dispatch(new ImportJob());', "use App\Jobs\ImportJob;\nuse Illuminate\Support\Facades\Bus as QueueBus;", ['App\Jobs\ImportJob::handle']];
        yield 'Bus::chain — every job' => ['Bus::chain([new ImportJob(), new OtherJob()]);', $twoJobsAndBus, ['App\Jobs\ImportJob::handle', 'App\Jobs\OtherJob::handle']];
        yield 'Bus::batch — every job' => ['Bus::batch([new ImportJob(), new OtherJob()]);', $twoJobsAndBus, ['App\Jobs\ImportJob::handle', 'App\Jobs\OtherJob::handle']];
        yield 'grouped use import' => ['dispatch_with_retries(new OtherJob());', 'use App\Jobs\{ImportJob, OtherJob};', ['App\Jobs\OtherJob::handle']];
        // RegularThing is in App\Http\Controllers (same namespace), not a job — no edge.
        yield 'non-job class produces no edge' => ['dispatch(new RegularThing());', '', []];
        // `ImportJob::dispatch(...)` builds a closure; getArgs() would throw if not guarded for it.
        yield 'first-class callable does not emit' => ['$ref = ImportJob::dispatch(...);', $importJob, []];
    }

    /**
     * @param  list<string>  $expectedTargets
     */
    #[Test]
    #[DataProvider('dispatchForms')]
    public function it_traces_each_dispatch_form_to_its_jobs(string $body, string $uses, array $expectedTargets): void
    {
        $edges = $this->edges($body, $uses);

        if ($expectedTargets === []) {
            $this->assertSame([], $edges);

            return;
        }

        foreach ($expectedTargets as $target) {
            $this->assertContains(['source' => self::DISPATCHER . '::store', 'target' => $target, 'type' => 'action-to-job'], $edges);
        }
    }

    /**
     * A dispatch verb whose job can't be seen statically counts as unresolved; anything that isn't a
     * job dispatch must not.
     *
     * @return Iterator<string, array{string, string, int}>
     */
    public static function unresolvedSignals(): Iterator
    {
        yield 'unfollowable variable dispatch' => ['dispatch($this->job);', '', 1];
        yield 'Bus group with a non-array argument' => ['Bus::batch($pending);', 'use Illuminate\Support\Facades\Bus;', 1];
        yield 'unrelated method ->dispatch is not a job dispatch' => ['$emitter->dispatch($event);', '', 0];
        yield 'Bus::chain tail ->dispatch is no-arg' => ['Bus::chain([new ImportJob()])->dispatch();', "use App\Jobs\ImportJob;\nuse Illuminate\Support\Facades\Bus;", 0];
    }

    #[Test]
    #[DataProvider('unresolvedSignals')]
    public function it_counts_only_unfollowable_job_dispatches_as_unresolved(string $body, string $uses, int $expected): void
    {
        $this->assertSame($expected, $this->unresolved($body, $uses));
    }

    #[Test]
    public function instantiating_a_job_links_the_constructing_method_even_when_dispatched_as_a_variable(): void
    {
        $edges = $this->edges('$job = new ImportJob(); dispatch($job);', 'use App\Jobs\ImportJob;');

        $this->assertContains(['source' => self::DISPATCHER . '::store', 'target' => 'App\Jobs\ImportJob::handle', 'type' => 'action-to-job'], $edges);
    }

    #[Test]
    public function a_job_constructing_itself_emits_no_self_edge(): void
    {
        $source = "<?php\nnamespace App\Jobs;\nclass ImportJob\n{\n    public function copy(): void\n    {\n        \$clone = new ImportJob();\n    }\n}\n";

        $this->assertSame([], new DispatchEdgeTracer()->edgesForSource($source, 'App\Jobs\ImportJob')['edges']);
    }

    #[Test]
    public function a_chain_with_one_opaque_item_emits_its_edge_and_one_unresolved(): void
    {
        $source = "<?php\nnamespace App\Http\Controllers;\nuse App\Jobs\ImportJob;\nuse Illuminate\Support\Facades\Bus;\nclass VideoController\n{\n    public function store(): void\n    {\n        Bus::chain([new ImportJob(), \$dynamic]);\n    }\n}\n";

        $result = new DispatchEdgeTracer()->edgesForSource($source, self::DISPATCHER);

        $this->assertContains('App\Jobs\ImportJob::handle', array_column($result['edges'], 'target'));
        $this->assertSame(1, $result['unresolved']);
    }
}
