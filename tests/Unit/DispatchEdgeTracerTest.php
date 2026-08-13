<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use Iterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Tests\TestCase;
use SanderMuller\Richter\Tracers\DispatchEdgeTracer;

final class DispatchEdgeTracerTest extends TestCase
{
    private const string DISPATCHER = 'App\Http\Controllers\PostController';

    /**
     * @return list<array{source: string, target: string, type: string}>
     */
    private function edges(string $body, string $uses): array
    {
        $source = "<?php\nnamespace App\Http\Controllers;\n{$uses}\nclass PostController\n{\n    public function store(): void\n    {\n        {$body}\n    }\n}\n";

        return new DispatchEdgeTracer()->edgesForSource($source, self::DISPATCHER)['edges'];
    }

    private function unresolved(string $body, string $uses): int
    {
        $source = "<?php\nnamespace App\Http\Controllers;\n{$uses}\nclass PostController\n{\n    public function store(): void\n    {\n        {$body}\n    }\n}\n";

        return count(new DispatchEdgeTracer()->edgesForSource($source, self::DISPATCHER)['unresolvedSites']);
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
        // A real, loadable class that is not a dispatch target (no handle()/__invoke(), no
        // Dispatchable/ShouldQueue, not \Jobs\) draws no edge. The class must exist: an unloadable
        // name fails toward firing under DispatchTarget (uncertainty → could-be), so a made-up name
        // here would wrongly draw an edge.
        yield 'non-dispatch-target class produces no edge' => ['dispatch(new Post());', 'use App\Models\Post;', []];
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
     * Plan 043: a resolved dispatch of a non-job dispatch target — a plain self-handling command
     * (a `handle()` with no `Dispatchable` trait, run via `BusDispatcher::dispatchNow`) or a
     * `Dispatchable` command that is not `ShouldQueue` — now draws the `action-to-job` edge the
     * job-only predicate previously omitted. The edge-drawer and the determinability predicate share
     * one definition of "dispatch target" (the DispatchTarget predicate), so both recognise these shapes.
     *
     * @return Iterator<string, array{string, string, string}>
     */
    public static function nonJobDispatchTargets(): Iterator
    {
        yield 'plain self-handling command' => ['dispatch(new ArchiveStalePosts());', 'use App\Commands\ArchiveStalePosts;', 'App\Commands\ArchiveStalePosts::handle'];
        yield 'Dispatchable command that is not ShouldQueue' => ['dispatch(new GenerateReport());', 'use App\Actions\GenerateReport;', 'App\Actions\GenerateReport::handle'];
    }

    #[Test]
    #[DataProvider('nonJobDispatchTargets')]
    public function it_draws_an_edge_to_a_resolved_non_job_dispatch_target(string $body, string $uses, string $expectedTarget): void
    {
        $edges = $this->edges($body, $uses);

        $this->assertContains(['source' => self::DISPATCHER . '::store', 'target' => $expectedTarget, 'type' => 'action-to-job'], $edges);
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
    public function constructing_a_handle_only_shape_without_a_dispatch_verb_draws_no_edge(): void
    {
        // A method that merely constructs a class matching the dispatch predicate ONLY via
        // handle()/__invoke() — with no dispatch verb in the method — must draw no edge. Countless
        // value objects carry a handle() method; without this, every DTO-constructing method reads as
        // a dispatcher. (An INTRINSIC job/command is still linked from a bare instantiation — see the
        // `dispatch_with_retries helper` case above, which uses a \Jobs\ class.)
        $source = "<?php\nnamespace App\Calculators;\nuse App\Commands\ArchiveStalePosts;\nclass PriceCalculator\n{\n    public function build(): void\n    {\n        \$pending = new ArchiveStalePosts();\n    }\n}\n";

        $this->assertSame([], new DispatchEdgeTracer()->edgesForSource($source, 'App\Calculators\PriceCalculator')['edges']);
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
        $source = "<?php\nnamespace App\Http\Controllers;\nuse App\Jobs\ImportJob;\nuse Illuminate\Support\Facades\Bus;\nclass PostController\n{\n    public function store(): void\n    {\n        Bus::chain([new ImportJob(), \$dynamic]);\n    }\n}\n";

        $result = new DispatchEdgeTracer()->edgesForSource($source, self::DISPATCHER);

        $this->assertContains('App\Jobs\ImportJob::handle', array_column($result['edges'], 'target'));
        $this->assertCount(1, $result['unresolvedSites']);
    }

    #[Test]
    public function an_unresolved_site_carries_the_dispatching_member_and_the_dispatch_line(): void
    {
        // Without these two fields the report can only say a dispatch somewhere could not be
        // followed, which is the whole reason the selection was unactionable.
        $source = "<?php\nnamespace App\Http\Controllers;\nclass PostController\n{\n    public function store(): void\n    {\n        dispatch(\$job);\n    }\n}\n";

        $sites = new DispatchEdgeTracer()->edgesForSource($source, self::DISPATCHER)['unresolvedSites'];

        $this->assertSame([['line' => 7, 'dispatcher' => self::DISPATCHER . '::store']], $sites);
    }

    #[Test]
    public function two_opaque_items_in_one_chain_are_one_site(): void
    {
        // The site is the dispatch statement, not each opaque sub-expression: a reader opens one
        // line, so a count that read 2 here would be counting increments rather than places to look.
        $source = "<?php\nnamespace App\Http\Controllers;\nuse Illuminate\Support\Facades\Bus;\nclass PostController\n{\n    public function store(): void\n    {\n        Bus::chain([\$first, \$second]);\n    }\n}\n";

        $sites = new DispatchEdgeTracer()->edgesForSource($source, self::DISPATCHER)['unresolvedSites'];

        $this->assertSame([['line' => 8, 'dispatcher' => self::DISPATCHER . '::store']], $sites);
    }

    #[Test]
    public function two_unfollowable_dispatches_in_one_method_stay_two_sites(): void
    {
        // The counterpart to the de-duplication above: distinct statements are distinct places, and
        // collapsing them would hide one of the two lines a reader has to go and fix.
        $source = "<?php\nnamespace App\Http\Controllers;\nclass PostController\n{\n    public function store(): void\n    {\n        dispatch(\$first);\n        dispatch(\$second);\n    }\n}\n";

        $sites = new DispatchEdgeTracer()->edgesForSource($source, self::DISPATCHER)['unresolvedSites'];

        $this->assertSame([
            ['line' => 7, 'dispatcher' => self::DISPATCHER . '::store'],
            ['line' => 8, 'dispatcher' => self::DISPATCHER . '::store'],
        ], $sites);
    }

    #[Test]
    public function a_string_literal_argument_is_not_a_job_dispatch(): void
    {
        // `$this->dispatch('close-modal')` is Livewire's browser-event dispatch, a different method
        // that happens to share the name. `DispatchesJobs::dispatch()` takes a job OBJECT, so a bare
        // literal can never be one. Counting it blocked a test selection with nothing to restructure.
        $source = "<?php\nnamespace App\Livewire;\nclass Modal\n{\n    public function close(): void\n    {\n        \$this->dispatch('close-modal', id: 1);\n    }\n}\n";

        $result = new DispatchEdgeTracer()->edgesForSource($source, 'App\Livewire\Modal');

        $this->assertSame([], $result['unresolvedSites']);
        $this->assertSame([], $result['edges']);
    }

    #[Test]
    public function a_variable_argument_to_this_dispatch_is_still_a_job_dispatch(): void
    {
        // The other side of the same guard: narrowing must not swallow the real Dispatchable form,
        // which is the whole reason the lane exists.
        $source = "<?php\nnamespace App\Http\Controllers;\nclass PostController\n{\n    public function store(): void\n    {\n        \$this->dispatch(\$job);\n    }\n}\n";

        $sites = new DispatchEdgeTracer()->edgesForSource($source, self::DISPATCHER)['unresolvedSites'];

        $this->assertSame([['line' => 7, 'dispatcher' => self::DISPATCHER . '::store']], $sites);
    }

    #[Test]
    public function an_inline_closure_dispatch_is_not_unfollowable(): void
    {
        // `dispatch(function () { … })` queues the closure itself, and its body is in the same AST
        // the reference tracer already descends — so the work it does is already edges out of this
        // dispatching member. Nothing is hidden and nothing can be restructured, which is what makes
        // calling it unfollowable a block over reach the graph already has.
        $source = "<?php\nnamespace App\Http\Controllers;\nclass PostController\n{\n    public function store(): void\n    {\n        dispatch(function (): void {\n            \$this->rebuild();\n        })->afterResponse();\n    }\n\n    private function rebuild(): void {}\n}\n";

        $result = new DispatchEdgeTracer()->edgesForSource($source, self::DISPATCHER);

        $this->assertSame([], $result['unresolvedSites']);
    }

    #[Test]
    public function an_arrow_function_dispatch_is_not_unfollowable_either(): void
    {
        $source = "<?php\nnamespace App\Http\Controllers;\nclass PostController\n{\n    public function store(): void\n    {\n        dispatch(fn (): int => 1);\n    }\n}\n";

        $this->assertSame([], new DispatchEdgeTracer()->edgesForSource($source, self::DISPATCHER)['unresolvedSites']);
    }

    #[Test]
    public function a_closure_inside_a_chain_is_not_unfollowable_either(): void
    {
        // Chained queued closures are an ordinary Laravel idiom, and the argument is the same one
        // that exempts a closure passed alone: it is the queued work, and its body is right there.
        $source = "<?php\nnamespace App\Http\Controllers;\nuse App\Jobs\ImportJob;\nuse Illuminate\Support\Facades\Bus;\nclass PostController\n{\n    public function store(): void\n    {\n        Bus::chain([new ImportJob(), function (): void {}]);\n    }\n}\n";

        $result = new DispatchEdgeTracer()->edgesForSource($source, self::DISPATCHER);

        $this->assertContains('App\Jobs\ImportJob::handle', array_column($result['edges'], 'target'));
        $this->assertSame([], $result['unresolvedSites']);
    }

    #[Test]
    public function an_unparseable_source_yields_no_sites(): void
    {
        // The unparseable-file taint is a separate, global signal; a file with no AST must not also
        // contribute a dispatch site naming a line nobody can open.
        $result = new DispatchEdgeTracer()->edgesForSource("<?php\nclass {{{ broken\n", self::DISPATCHER);

        $this->assertSame([], $result['unresolvedSites']);
        $this->assertSame([], $result['edges']);
    }
}
