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
        yield 'Bus::batch over a mapped collection' => ['Bus::batch($this->items->map(fn (int $i): ImportJob => new ImportJob())->all());', $twoJobsAndBus, ['App\Jobs\ImportJob::handle']];
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
     * A job that dispatches itself names its target with `self::`/`static::`, which PHP-Parser's
     * name resolution leaves verbatim. Before this resolved, the edge landed on a `self::handle`
     * node that names no class — one leaf every self-dispatching class in the app shared.
     *
     * @return Iterator<string, array{string, string}>
     */
    public static function selfReferentialDispatches(): Iterator
    {
        yield 'self::dispatch' => ['self::dispatch();', 'App\\Jobs\\ImportJob::handle'];
        yield 'static::dispatch' => ['static::dispatch();', 'App\\Jobs\\ImportJob::handle'];
        yield 'self::dispatchSync' => ['self::dispatchSync();', 'App\\Jobs\\ImportJob::handle'];
    }

    #[Test]
    #[DataProvider('selfReferentialDispatches')]
    public function it_resolves_a_self_referential_dispatch_to_the_declaring_class(string $body, string $expected): void
    {
        $source = "<?php\nnamespace App\\Jobs;\nclass ImportJob\n{\n    public function run(): void\n    {\n        {$body}\n    }\n\n    public function handle(): void\n    {\n    }\n}\n";

        $edges = new DispatchEdgeTracer()->edgesForSource($source, 'App\\Jobs\\ImportJob')['edges'];

        $this->assertSame([$expected], array_column($edges, 'target'));
    }

    /**
     * `self` inside a TRAIT is the consuming class at runtime, never the trait, so resolving it to
     * the trait's own FQCN would name a real class that never dispatched — the exact failure the
     * refusal exists to avoid, and a worse answer than naming nothing.
     */
    #[Test]
    public function it_refuses_to_resolve_self_inside_a_trait(): void
    {
        $source = "<?php\nnamespace App\\Concerns;\ntrait DispatchesWork\n{\n    public function run(): void\n    {\n        self::dispatch();\n    }\n\n    public function handle(): void\n    {\n    }\n}\n";

        $targets = array_column(new DispatchEdgeTracer()->edgesForSource($source, 'App\\Concerns\\DispatchesWork')['edges'], 'target');

        $this->assertNotContains('App\\Concerns\\DispatchesWork::handle', $targets);
        $this->assertSame(['self::handle'], $targets);
    }

    /**
     * `new static()` can construct a late-bound SUBCLASS, so it is not the self-construction the
     * guard suppresses. It keeps the edge the instantiation lane exists to retain — with the id
     * resolved to the declaring class, the honest floor.
     */
    #[Test]
    public function it_keeps_the_instantiation_edge_for_a_bare_new_static(): void
    {
        $source = "<?php\nnamespace App\\Jobs;\nuse Illuminate\\Foundation\\Bus\\Dispatchable;\nclass ImportJob\n{\n    use Dispatchable;\n\n    public function run(): static\n    {\n        return new static();\n    }\n\n    public function handle(): void\n    {\n    }\n}\n";

        $targets = array_column(new DispatchEdgeTracer()->edgesForSource($source, 'App\\Jobs\\ImportJob')['edges'], 'target');

        $this->assertSame(['App\\Jobs\\ImportJob::handle'], $targets);
    }

    /**
     * The named-constructor lane (`SomeJob::for($x)` as a dispatch argument) resolves its receiver
     * the same way every other lane does.
     */
    #[Test]
    public function it_resolves_a_dispatched_self_named_constructor(): void
    {
        $source = "<?php\nnamespace App\\Jobs;\nclass ImportJob\n{\n    public function run(): void\n    {\n        dispatch(self::forToday());\n    }\n\n    public function handle(): void\n    {\n    }\n}\n";

        $targets = array_column(new DispatchEdgeTracer()->edgesForSource($source, 'App\\Jobs\\ImportJob')['edges'], 'target');

        $this->assertSame(['App\\Jobs\\ImportJob::handle'], $targets);
    }

    /**
     * The FQCN being traced is derived from the file's PATH, while the lexical scope comes from the
     * AST. A file whose declared class name does not match its path — a PSR-4 violation, or a class
     * renamed without moving the file — makes the two disagree, and resolving `self` to the
     * path-derived name would point every dispatch at a class this file does not declare.
     */
    #[Test]
    public function it_refuses_to_resolve_self_when_the_declared_class_does_not_match_the_traced_fqcn(): void
    {
        $source = "<?php\nnamespace App\\Jobs;\nclass RenamedJob\n{\n    public function run(): void\n    {\n        self::dispatch();\n    }\n\n    public function handle(): void\n    {\n    }\n}\n";

        $targets = array_column(new DispatchEdgeTracer()->edgesForSource($source, 'App\\Jobs\\ImportJob')['edges'], 'target');

        $this->assertNotContains('App\\Jobs\\ImportJob::handle', $targets);
        $this->assertSame(['self::handle'], $targets);
    }

    /**
     * `parent::` is left unresolved on purpose: the parent FQCN is not known where the edge is
     * built, so naming the declaring class would point at the wrong class rather than at no class.
     */
    #[Test]
    public function it_leaves_a_parent_dispatch_unresolved(): void
    {
        $source = "<?php\nnamespace App\\Jobs;\nclass ImportJob extends BaseJob\n{\n    public function run(): void\n    {\n        parent::dispatch();\n    }\n\n    public function handle(): void\n    {\n    }\n}\n";

        $targets = array_column(new DispatchEdgeTracer()->edgesForSource($source, 'App\\Jobs\\ImportJob')['edges'], 'target');

        $this->assertNotContains('App\\Jobs\\ImportJob::handle', $targets);
        $this->assertSame(['parent::handle'], $targets);
    }

    /**
     * Constructing the enclosing class is not dispatching it. The self-construction guard could
     * never fire while the target read `self`, so resolving it first is what makes `new self()`
     * skip — the behaviour that guard was written for.
     */
    #[Test]
    public function it_does_not_draw_an_edge_for_a_bare_new_self(): void
    {
        $source = "<?php\nnamespace App\\Jobs;\nclass ImportJob\n{\n    public function run(): self\n    {\n        return new self();\n    }\n\n    public function handle(): void\n    {\n    }\n}\n";

        $edges = new DispatchEdgeTracer()->edgesForSource($source, 'App\\Jobs\\ImportJob')['edges'];

        $this->assertSame([], $edges);
    }

    /**
     * The other half of that distinction: `new self()` passed to a dispatch verb IS a self-dispatch,
     * and resolves to the declaring class. Pinned together with the case above so an edit cannot
     * collapse the two.
     */
    #[Test]
    public function it_resolves_a_dispatched_new_self_to_the_declaring_class(): void
    {
        $source = "<?php\nnamespace App\\Jobs;\nclass ImportJob\n{\n    public function run(): void\n    {\n        dispatch(new self());\n    }\n\n    public function handle(): void\n    {\n    }\n}\n";

        $edges = new DispatchEdgeTracer()->edgesForSource($source, 'App\\Jobs\\ImportJob')['edges'];

        $this->assertSame(['App\\Jobs\\ImportJob::handle'], array_column($edges, 'target'));
    }

    /**
     * `self` is lexical, so it resolves against whichever class-like encloses it. Methods arrive as
     * one flat list attributed to the file's class, so a file declaring more than one class-like
     * cannot say which one a given `self::` meant — and pointing it at the file's class would be a
     * guess. The ambiguous case is refused: no edge to the enclosing class.
     */
    #[Test]
    public function it_refuses_to_resolve_self_when_the_file_declares_more_than_one_class_like(): void
    {
        $source = "<?php\nnamespace App\\Jobs;\nclass ImportJob\n{\n    public function run(): void\n    {\n        \$x = new class {\n            public function go(): void\n            {\n                self::dispatch();\n            }\n        };\n    }\n\n    public function handle(): void\n    {\n    }\n}\n";

        $targets = array_column(new DispatchEdgeTracer()->edgesForSource($source, 'App\\Jobs\\ImportJob')['edges'], 'target');

        // The anonymous class's method arrives attributed to the outer class, so both dispatch
        // sites are seen — and every one of them stays unresolved.
        $this->assertNotContains('App\\Jobs\\ImportJob::handle', $targets);
        $this->assertSame(['self::handle'], array_values(array_unique($targets)));
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
        // `map` is total, so the callable's return type IS the batch's item type — the one opaque
        // argument shape that proves itself instead of needing doubt.
        yield 'Bus::batch over a mapped collection' => ['Bus::batch($this->items->map(fn (int $i): ImportJob => new ImportJob())->all());', "use App\Jobs\ImportJob;\nuse Illuminate\Support\Facades\Bus;", 0];
        yield 'Bus::batch over a mapped collection, filtered first' => ['Bus::batch($this->items->filter(fn (int $i): bool => $i > 0)->map(fn (int $i): ImportJob => new ImportJob())->values()->all());', "use App\Jobs\ImportJob;\nuse Illuminate\Support\Facades\Bus;", 0];
        // A call between the map and the dispatch that can change what the items are gives the proof up.
        yield 'Bus::batch over a mapped collection then plucked' => ['Bus::batch($this->items->map(fn (int $i): ImportJob => new ImportJob())->pluck("job")->all());', "use App\Jobs\ImportJob;\nuse Illuminate\Support\Facades\Bus;", 1];
        yield 'Bus::batch over a map whose callable is a variable' => ['Bus::batch($this->items->map($this->builder)->all());', 'use Illuminate\Support\Facades\Bus;', 1];
        yield 'Bus::batch over a map returning something else' => ['Bus::batch($this->items->map(fn (int $i): ImportJob => $this->build($i))->all());', "use App\Jobs\ImportJob;\nuse Illuminate\Support\Facades\Bus;", 1];
        // One branch returns a job and the other does not, so some items are not jobs and the batch
        // proves nothing.
        yield 'Bus::batch over a map with a non-job branch' => ['Bus::batch($this->items->map(fn (int $i): object => $i > 0 ? new ImportJob() : new ImportSummary())->all());', "use App\Jobs\ImportJob;\nuse Illuminate\Support\Facades\Bus;", 1];
        // A closure that can fall through returns null for some items; only a single unconditional
        // return proves the type.
        yield 'Bus::batch over a map that can fall through' => ['Bus::batch($this->items->map(function (int $i) { if ($i > 0) { return new ImportJob(); } })->all());', "use App\Jobs\ImportJob;\nuse Illuminate\Support\Facades\Bus;", 1];
        // A `return` inside a nested callable belongs to that callable, not to the map's.
        yield 'Bus::batch over a map whose only return is nested' => ['Bus::batch($this->items->map(function (int $i) { $make = fn (): ImportJob => new ImportJob(); })->all());', "use App\Jobs\ImportJob;\nuse Illuminate\Support\Facades\Bus;", 1];
        // `toArray()` converts an Arrayable item into an array, so the mapped job may not reach the
        // dispatch as a job at all.
        yield 'Bus::batch over a mapped collection handed over with toArray' => ['Bus::batch($this->items->map(fn (int $i): ImportJob => new ImportJob())->toArray());', "use App\Jobs\ImportJob;\nuse Illuminate\Support\Facades\Bus;", 1];
        // `flatMap` spreads an array return across the result, so the one-item-out reasoning does
        // not carry.
        yield 'Bus::batch over a flatMapped collection' => ['Bus::batch($this->items->flatMap(fn (int $i): ImportJob => new ImportJob())->all());', "use App\Jobs\ImportJob;\nuse Illuminate\Support\Facades\Bus;", 1];
        yield 'Bus::batch over a map with one unconditional return' => ['Bus::batch($this->items->map(function (int $i): ImportJob { return new ImportJob(); })->all());', "use App\Jobs\ImportJob;\nuse Illuminate\Support\Facades\Bus;", 0];
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
    public function a_job_aliased_by_compact_is_still_followed(): void
    {
        // The collection lane refuses this: an alias can change what a collection HOLDS. A single job
        // is a different claim — the alias can call anything on it, and no call changes its class —
        // and `compact()` is common enough that refusing it would list dispatches for no gain.
        $this->assertSame(0, $this->unresolved('$job = new ImportJob(); $this->log(compact(\'job\')); dispatch($job);', 'use App\Jobs\ImportJob;'));
    }

    #[Test]
    public function a_job_constructed_just_above_its_dispatch_is_not_unfollowable(): void
    {
        // The same shape as the test above, seen from the determinability side. The edge is already
        // there — the instantiation is in this very method — so the dispatch hides nothing and there is
        // nothing for a project to restructure. Recording it taints every selection over reach the
        // graph already has, which is the argument that exempts an inline closure too.
        $this->assertSame(0, $this->unresolved('$job = new ImportJob(); dispatch($job);', 'use App\Jobs\ImportJob;'));
    }

    /**
     * Shapes where the variable is NOT provably the constructed job, and the site must survive.
     *
     * Each one is a way the value can differ at the dispatch from what the `new` suggests. Getting any
     * of these wrong drops a real target from a test selection, so they outnumber the positive case
     * deliberately.
     *
     * @return Iterator<string, array{string}>
     */
    public static function unprovableLocalJobs(): Iterator
    {
        yield 'assigned only inside a branch' => ['if ($flag) { $job = new ImportJob(); } dispatch($job);'];
        yield 'reassigned from something opaque' => ['$job = new ImportJob(); $job = $this->factory->make(); dispatch($job);'];
        yield 'assigned only below the dispatch' => ['dispatch($job); $job = new ImportJob();'];
        yield 'rebound by a foreach' => ['$job = new ImportJob(); foreach ($queue as $job) { } dispatch($job);'];
        yield 'rebound by a destructuring foreach' => ['$job = new ImportJob(); foreach ($rows as [$job, $meta]) { } dispatch($job);'];
        yield 'rebound by a keyed destructuring foreach' => ['$job = new ImportJob(); foreach ($rows as $i => [$job]) { } dispatch($job);'];
        yield 'a dynamic write anywhere in the method' => ['$job = new ImportJob(); $$name = $other; dispatch($job);'];
        yield 'captured by reference' => ['$job = new ImportJob(); $f = function () use (&$job) { $job = null; }; dispatch($job);'];
        yield 'rebound through a reference alias' => ['$job = new ImportJob(); $alias = &$job; $alias = $this->factory->make(); dispatch($job);'];
        yield 'constructed inside a closure, dispatched outside' => ['$f = function () { $job = new ImportJob(); }; dispatch($job);'];
        yield 'not a dispatch target at all' => ['$job = new Post(); dispatch($job);'];
        // `extract()` and `eval()` write a name no `Variable` node carries, so the write count stays
        // at one while the value changes. `compact()` is deliberately absent: it only aliases the
        // object, and no call on an object changes its class.
        yield 'rebound by extract' => ['$job = new ImportJob(); extract($this->overrides()); dispatch($job);'];
        yield 'rebound by eval' => ['$job = new ImportJob(); eval($this->code()); dispatch($job);'];
        yield 'rebound by an include' => ['$job = new ImportJob(); include $this->path(); dispatch($job);'];
        yield 'rebound by a call through a variable' => ['$job = new ImportJob(); $hydrate($this->overrides()); dispatch($job);'];
        // The alias resolves before this pass reads it, so the import is caught by the same name check.
        yield 'rebound by an aliased extract' => ['$job = new ImportJob(); hydrate($this->overrides()); dispatch($job);'];
    }

    #[Test]
    #[DataProvider('unprovableLocalJobs')]
    public function a_variable_the_method_cannot_pin_down_stays_unfollowable(string $body): void
    {
        $this->assertSame(1, $this->unresolved($body, "use App\Jobs\ImportJob;\nuse App\Models\Post;\nuse function extract as hydrate;"));
    }

    #[Test]
    public function a_locally_constructed_job_in_a_chain_is_not_unfollowable_either(): void
    {
        $result = new DispatchEdgeTracer()->edgesForSource(
            "<?php\nnamespace App\Http\Controllers;\nuse App\Jobs\ImportJob;\nuse Illuminate\Support\Facades\Bus;\nclass PostController\n{\n    public function store(): void\n    {\n        \$job = new ImportJob();\n        Bus::chain([\$job]);\n    }\n}\n",
            self::DISPATCHER,
        );

        $this->assertSame([], $result['unresolvedSites']);
        $this->assertContains('App\Jobs\ImportJob::handle', array_column($result['edges'], 'target'));
    }

    /**
     * A batch names its collection. The map and the dispatch sit apart in every real batch, because
     * the code between them needs the collection, so a walk that stops at a local only ever proves
     * the shape nobody writes: the whole chain inline at the dispatch site.
     *
     * The refusals outnumber the positives deliberately — each is a way the collection at the
     * dispatch can differ from the one the map built, and reading any of them as resolved drops real
     * targets from a test selection.
     *
     * @return Iterator<string, array{string, int}>
     */
    public static function locallyBoundBatches(): Iterator
    {
        $map = '$this->items->map(fn (int $i): ImportJob => new ImportJob())';
        yield 'bound above the dispatch' => ["\$jobs = {$map}; Bus::batch(\$jobs->all());", 0];
        yield 'dispatched without a call of its own' => ["\$jobs = {$map}; Bus::batch(\$jobs);", 0];
        yield 'filtered before the map and after the local' => ['$jobs = $this->items->filter(fn (int $i): bool => $i > 0)->map(fn (int $i): ImportJob => new ImportJob()); Bus::batch($jobs->values()->all());', 0];
        // The chain split over two statements is the shape the local lane exists for: the second
        // binding reads the first, which is why the bindings are built in source order.
        yield 'built over two bindings' => ["\$mapped = {$map}; \$ready = \$mapped->filter(fn (ImportJob \$job): bool => true); Bus::batch(\$ready->all());", 0];
        yield 'bound only inside a branch' => ["if (\$flag) { \$jobs = {$map}; } Bus::batch(\$jobs->all());", 1];
        yield 'rebound from something opaque' => ["\$jobs = {$map}; \$jobs = \$this->pending(); Bus::batch(\$jobs->all());", 1];
        yield 'bound only below the dispatch' => ["Bus::batch(\$jobs->all()); \$jobs = {$map};", 1];
        yield 'rebound by a foreach' => ["\$jobs = {$map}; foreach (\$queue as \$jobs) { } Bus::batch(\$jobs->all());", 1];
        yield 'a dynamic write anywhere in the method' => ["\$jobs = {$map}; \$\$name = \$other; Bus::batch(\$jobs->all());", 1];
        yield 'plucked before the binding' => ["\$jobs = {$map}->pluck('id'); Bus::batch(\$jobs->all());", 1];
        yield 'plucked after the local' => ["\$jobs = {$map}; Bus::batch(\$jobs->pluck('id')->all());", 1];
        // A rebound name proves nothing, and neither does anything derived from it: the write count
        // is applied while the bindings are built, so the stale collection never reaches `$ready`.
        yield 'derived from a rebound local' => ["\$mapped = {$map}; \$mapped = \$this->pending(); \$ready = \$mapped->filter(fn (ImportJob \$job): bool => true); Bus::batch(\$ready->all());", 1];
        yield 'a local bound to something else entirely' => ['$jobs = $this->pending(); Bus::batch($jobs->all());', 1];
        // A collection is an object, so its CONTENTS can change with no write to the name: the write
        // count stays at one and the batch holds something the map never built. Any mention beyond
        // the binding and the dispatching read refuses the binding.
        yield 'mutated in place between the binding and the dispatch' => ["\$jobs = {$map}; \$jobs->push(\$this->extra()); Bus::batch(\$jobs->all());", 1];
        yield 'handed to a helper that keeps the handle' => ["\$jobs = {$map}; \$this->log(\$jobs); Bus::batch(\$jobs->all());", 1];
        // A name-keyed reach into the scope hands the collection out with no mention of the name, so
        // the occurrence count cannot see the alias that mutates it.
        yield 'aliased through compact' => ["\$jobs = {$map}; \$this->log(compact('jobs')); Bus::batch(\$jobs->all());", 1];
        yield 'aliased through get_defined_vars' => ["\$jobs = {$map}; \$this->log(get_defined_vars()); Bus::batch(\$jobs->all());", 1];
        yield 'rebound by extract' => ["\$jobs = {$map}; extract(\$this->overrides()); Bus::batch(\$jobs->all());", 1];
        yield 'rebound by eval' => ["\$jobs = {$map}; eval(\$this->code()); Bus::batch(\$jobs->all());", 1];
        yield 'rebound by an include' => ["\$jobs = {$map}; include \$this->path(); Bus::batch(\$jobs->all());", 1];
        yield 'rebound by a require' => ["\$jobs = {$map}; require \$this->path(); Bus::batch(\$jobs->all());", 1];
        yield 'a call through a name this pass cannot read' => ["\$jobs = {$map}; \$hydrate(\$this->overrides()); Bus::batch(\$jobs->all());", 1];
        // A dynamic READ reaches the collection while adding no occurrence of its name.
        yield 'mutated through a dynamic read' => ["\$jobs = {$map}; \$name = 'jobs'; \$\$name->push(\$this->extra()); Bus::batch(\$jobs->all());", 1];
    }

    #[Test]
    #[DataProvider('locallyBoundBatches')]
    public function a_batch_bound_to_a_local_is_followed_only_when_the_binding_is_provable(string $body, int $expected): void
    {
        $this->assertSame($expected, $this->unresolved($body, "use App\Jobs\ImportJob;\nuse Illuminate\Support\Facades\Bus;"));
    }

    #[Test]
    public function a_batch_bound_to_a_local_draws_the_edge_to_its_jobs(): void
    {
        // Edge parity with the inline form, not a proof of the local lane: the `new` inside the
        // callable draws this edge either way. The lane's own proof is the site count above.
        $edges = $this->edges(
            '$jobs = $this->items->map(fn (int $i): ImportJob => new ImportJob()); Bus::batch($jobs->all());',
            "use App\Jobs\ImportJob;\nuse Illuminate\Support\Facades\Bus;",
        );

        $this->assertContains(['source' => self::DISPATCHER . '::store', 'target' => 'App\Jobs\ImportJob::handle', 'type' => 'action-to-job'], $edges);
    }

    #[Test]
    public function a_named_constructor_draws_its_edge_but_keeps_its_site(): void
    {
        // `dispatch(ImportJob::for($x))` names the job in the receiver, so the edge is worth drawing: a
        // change to that job should reach this member. The site stays, because nothing here proves the
        // method returns an instance of its own class — and a wrong "resolved" drops a real target.
        $result = new DispatchEdgeTracer()->edgesForSource(
            "<?php\nnamespace App\Http\Controllers;\nuse App\Jobs\ImportJob;\nclass PostController\n{\n    public function store(): void\n    {\n        dispatch(ImportJob::for(\$video));\n    }\n}\n",
            self::DISPATCHER,
        );

        $this->assertContains('App\Jobs\ImportJob::handle', array_column($result['edges'], 'target'));
        $this->assertCount(1, $result['unresolvedSites']);
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
    public function a_component_event_does_not_unlock_the_instantiation_edge(): void
    {
        // The instantiation over-approximation is unlocked by a method that actually dispatches. A
        // component event named by a constant is not one — and it is classified twice, once for the
        // site and once for this unlocking. Reading the map in only the first place let a browser event
        // hand a handle()-carrying value object beside it a job edge it has no business having.
        $source = "<?php\nnamespace App\Livewire;\nuse App\Commands\ArchiveStalePosts;\nclass Panel\n{\n    private const string SAVED = 'saved';\n\n    public function save(): void\n    {\n        \$pending = new ArchiveStalePosts();\n        \$this->dispatch(self::SAVED);\n    }\n}\n";

        $this->assertSame([], new DispatchEdgeTracer()->edgesForSource($source, 'App\Livewire\Panel')['edges']);
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
    public function an_event_named_by_a_class_constant_is_not_a_job_dispatch(): void
    {
        // The variant that survived the literal-only test: the same Livewire event, named through a
        // constant instead of inline. Testing only for a bare literal just waits for the next
        // argument shape, which is what happened.
        $source = "<?php\nnamespace App\Livewire;\nclass Panel\n{\n    private const string SAVED = 'saved-settings';\n\n    public function save(): void\n    {\n        \$this->dispatch(self::SAVED);\n        \$this->dispatch(self::SAVED, id: 1);\n    }\n}\n";

        $result = new DispatchEdgeTracer()->edgesForSource($source, 'App\Livewire\Panel');

        $this->assertSame([], $result['unresolvedSites']);
    }

    #[Test]
    public function a_constant_that_is_not_a_string_still_counts_as_unfollowable(): void
    {
        // The guard resolves a constant only when the class declares it as a STRING. Anything else —
        // here a constant holding a job instance — stays an unfollowable dispatch, because dropping
        // it would cost a project real test coverage.
        $source = "<?php\nnamespace App\Http\Controllers;\nclass PostController\n{\n    private const int RETRIES = 3;\n\n    public function store(): void\n    {\n        \$this->dispatch(self::RETRIES);\n    }\n}\n";

        $sites = new DispatchEdgeTracer()->edgesForSource($source, self::DISPATCHER)['unresolvedSites'];

        $this->assertCount(1, $sites);
    }

    #[Test]
    public function a_constant_from_another_class_stays_unfollowable(): void
    {
        // Same-class only, deliberately: resolving through a parent or a sibling needs the cross-file
        // map, which does not exist while the tracer runs, and guessing would risk dropping a real one.
        $source = "<?php\nnamespace App\Http\Controllers;\nuse App\Events\Names;\nclass PostController\n{\n    public function store(): void\n    {\n        \$this->dispatch(Names::SAVED);\n    }\n}\n";

        $sites = new DispatchEdgeTracer()->edgesForSource($source, self::DISPATCHER)['unresolvedSites'];

        $this->assertCount(1, $sites);
    }

    #[Test]
    public function a_constant_resolves_against_its_own_declaring_class_not_the_whole_file(): void
    {
        // Two classes in one file, each with an `EVENT` of its own: a string in the component, an int
        // in the service. A name-only map across the file would read the int as the string, drop the
        // service's site, and hand back a `determinable` selection that nothing determined.
        $source = "<?php\nnamespace App\Livewire;\nclass Panel\n{\n    private const string EVENT = 'saved';\n\n    public function save(): void\n    {\n        \$this->dispatch(self::EVENT);\n    }\n}\n\nclass Importer\n{\n    private const int EVENT = 3;\n\n    public function run(): void\n    {\n        \$this->dispatch(self::EVENT);\n    }\n}\n";

        $sites = new DispatchEdgeTracer()->edgesForSource($source, 'App\Livewire\Panel')['unresolvedSites'];

        $this->assertCount(1, $sites);
        $this->assertSame(19, $sites[0]['line'], 'the service dispatch, not the component one');
    }

    #[Test]
    public function a_class_that_does_not_declare_the_constant_keeps_its_site(): void
    {
        // The sharper half of the same failure: the dispatching class declares no `EVENT` at all, so
        // `self::EVENT` comes from a parent this pass cannot read and may hold anything. A file-wide
        // map would call it a string purely because the class above happens to declare one.
        //
        // The component below needs its own dispatching method, or the map never carries its constant
        // and a file-wide one would be empty too — a test that passes without exercising anything.
        $source = "<?php\nnamespace App\Livewire;\nclass Panel\n{\n    private const string EVENT = 'saved';\n\n    public function save(): void\n    {\n        \$this->dispatch(self::EVENT);\n    }\n}\n\nclass Importer extends Base\n{\n    public function run(): void\n    {\n        \$this->dispatch(self::EVENT);\n    }\n}\n";

        $sites = new DispatchEdgeTracer()->edgesForSource($source, 'App\Livewire\Panel')['unresolvedSites'];

        $this->assertCount(1, $sites);
        $this->assertSame(17, $sites[0]['line'], 'the subclass dispatch, not the component one');
    }

    #[Test]
    public function a_grouped_typed_constant_declaration_resolves_too(): void
    {
        // Several names in one typed `const` statement — the form real components tend to use for a set
        // of related events. It works because the reader walks each statement's own list, but the docs
        // now say so, and a documented shape needs a test rather than a reading of the code.
        $source = "<?php\nnamespace App\Livewire;\nclass Panel\n{\n    public const string\n        SUBTITLE_CHANGED = 'subtitle-changed',\n        SUBTITLE_DELETED = 'subtitle-deleted';\n\n    public function save(): void\n    {\n        \$this->dispatch(self::SUBTITLE_CHANGED);\n        \$this->dispatch(self::SUBTITLE_DELETED);\n    }\n}\n";

        $this->assertSame([], new DispatchEdgeTracer()->edgesForSource($source, 'App\Livewire\Panel')['unresolvedSites']);
    }

    #[Test]
    public function the_self_keyword_is_matched_whatever_its_spelling(): void
    {
        // `self` is a keyword, so `SELF::EVENT` names the same constant. A case-sensitive match would
        // keep exactly the site this resolution exists to drop.
        $source = "<?php\nnamespace App\Livewire;\nclass Panel\n{\n    private const string EVENT = 'saved';\n\n    public function save(): void\n    {\n        \$this->dispatch(SELF::EVENT);\n    }\n}\n";

        $this->assertSame([], new DispatchEdgeTracer()->edgesForSource($source, 'App\Livewire\Panel')['unresolvedSites']);
    }

    #[Test]
    public function a_late_static_bound_constant_keeps_its_site(): void
    {
        // `static::` reads the constant off the runtime class, so a subclass can supply a value this
        // file never shows. Same class, same name, same string — and still recorded, because the
        // dispatch that runs may not be the one written here.
        $source = "<?php\nnamespace App\Livewire;\nclass Panel\n{\n    protected const EVENT = 'saved';\n\n    public function save(): void\n    {\n        \$this->dispatch(static::EVENT);\n    }\n}\n";

        $sites = new DispatchEdgeTracer()->edgesForSource($source, 'App\Livewire\Panel')['unresolvedSites'];

        $this->assertCount(1, $sites);
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
