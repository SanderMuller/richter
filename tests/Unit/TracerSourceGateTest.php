<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use Iterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Tests\TestCase;
use SanderMuller\Richter\Tracers\ConfigRegistryTracer;
use SanderMuller\Richter\Tracers\DispatchEdgeTracer;
use SanderMuller\Richter\Tracers\PolicyEdgeTracer;
use SanderMuller\Richter\Tracers\ViewRenderTracer;

/**
 * The source-token gates the graph build asks before walking a file, and the one property that makes
 * them safe: each is a strict SUPERSET of what its tracer can match.
 *
 * These gates are the cheapest thing in the build to get wrong. A matcher that grows a form the gate's
 * token does not spell drops that file's edges silently — no error, no test failure, just a graph
 * missing reach nobody can see is missing. Every case below pairs the gate with a source its tracer
 * genuinely matches, so a widened matcher fails here rather than in a consumer's report.
 */
final class TracerSourceGateTest extends TestCase
{
    /** @return Iterator<string, array{string, callable(string): bool}> */
    public static function matchedShapes(): Iterator
    {
        yield 'policy constant' => [
            "<?php\nnamespace App\Http\Controllers;\nuse App\Policies\PostPolicy;\nclass C { public function s(): void { \$this->authorize(PostPolicy::UPDATE); } }\n",
            PolicyEdgeTracer::mayMatch(...),
        ];
        yield 'policy by fully qualified name' => [
            "<?php\nnamespace App\Http\Controllers;\nclass C { public function s(): void { \$p = new \App\Policies\PostPolicy(); } }\n",
            PolicyEdgeTracer::mayMatch(...),
        ];
        yield 'config helper' => [
            "<?php\nnamespace App\Services;\nclass C { public function s(): void { config('services.drivers'); } }\n",
            ConfigRegistryTracer::mayMatch(...),
        ];
        yield 'Config facade' => [
            "<?php\nnamespace App\Services;\nuse Illuminate\Support\Facades\Config;\nclass C { public function s(): void { Config::get('services.drivers'); } }\n",
            ConfigRegistryTracer::mayMatch(...),
        ];
        yield 'view helper' => [
            "<?php\nnamespace App\Http\Controllers;\nclass C { public function s(): void { view('posts.index'); } }\n",
            ViewRenderTracer::mayMatch(...),
        ];
        yield 'View facade' => [
            "<?php\nnamespace App\Http\Controllers;\nuse Illuminate\Support\Facades\View;\nclass C { public function s(): void { View::make('posts.index'); } }\n",
            ViewRenderTracer::mayMatch(...),
        ];
        yield 'view property on a page component' => [
            "<?php\nnamespace App\Pages;\nclass P { protected static string \$view = 'pages.settings'; }\n",
            ViewRenderTracer::mayMatch(...),
        ];
        yield 'dispatch helper' => [
            "<?php\nnamespace App\Http\Controllers;\nclass C { public function s(): void { dispatch(\$job); } }\n",
            new DispatchEdgeTracer()->mayMatch(...),
        ];
        yield 'static Job::dispatch' => [
            "<?php\nnamespace App\Http\Controllers;\nuse App\Jobs\ImportJob;\nclass C { public function s(): void { ImportJob::dispatch(); } }\n",
            new DispatchEdgeTracer()->mayMatch(...),
        ];
        yield 'Bus chain' => [
            "<?php\nnamespace App\Http\Controllers;\nuse Illuminate\Support\Facades\Bus;\nclass C { public function s(): void { Bus::chain(\$jobs); } }\n",
            new DispatchEdgeTracer()->mayMatch(...),
        ];
        yield 'configured project helper that never says "dispatch"' => [
            "<?php\nnamespace App\Http\Controllers;\nclass C { public function s(): void { queue_job(\$this->factory->make()); } }\n",
            new DispatchEdgeTracer(['queue_job'])->mayMatch(...),
        ];
        yield 'instantiation split across a line break' => [
            "<?php\nnamespace App\Http\Controllers;\nuse App\Jobs\ImportJob;\nclass C { public function s(): void { \$j = new\n            ImportJob(); } }\n",
            new DispatchEdgeTracer()->mayMatch(...),
        ];
        yield 'bare instantiation of an intrinsic job' => [
            "<?php\nnamespace App\Http\Controllers;\nuse App\Jobs\ImportJob;\nclass C { public function s(): void { \$j = new ImportJob(); } }\n",
            new DispatchEdgeTracer()->mayMatch(...),
        ];
    }

    /**
     * @param  callable(string): bool  $gate
     */
    #[Test]
    #[DataProvider('matchedShapes')]
    public function a_gate_lets_through_every_shape_its_tracer_matches(string $source, callable $gate): void
    {
        $this->assertTrue($gate($source), 'the gate must be a superset of its matcher — this shape produces an edge or a site');
    }

    /** @return Iterator<string, array{string, callable(string): bool}> */
    public static function unmatchedShapes(): Iterator
    {
        $plain = "<?php\nnamespace App\Models;\nclass Post { public function title(): string { return \$this->attributes['title']; } }\n";

        yield 'no policy anywhere' => [$plain, PolicyEdgeTracer::mayMatch(...)];
        yield 'no config read' => [$plain, ConfigRegistryTracer::mayMatch(...)];
        yield 'no view render' => [$plain, ViewRenderTracer::mayMatch(...)];
        yield 'no dispatch and no instantiation' => [$plain, new DispatchEdgeTracer()->mayMatch(...)];
    }

    /**
     * The other half: a gate that answered `true` for everything would satisfy the superset property
     * and save nothing, so it has to refuse something.
     *
     * @param  callable(string): bool  $gate
     */
    #[Test]
    #[DataProvider('unmatchedShapes')]
    public function a_gate_refuses_a_source_with_nothing_to_find(string $source, callable $gate): void
    {
        $this->assertFalse($gate($source));
    }
}
