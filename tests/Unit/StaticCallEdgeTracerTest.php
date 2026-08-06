<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Tests\TestCase;
use SanderMuller\Richter\Tracers\StaticCallEdgeTracer;

/**
 * Brain traces `new Foo` but not `Foo::bar()`, so a class reached only through static calls has no
 * node at all — and `detect-changes` then states that nothing references it. These cover the edge
 * this draws and, just as load-bearing, the receivers it must stay silent about: an unfiltered
 * version would light up every facade and `Carbon::now()` in the codebase.
 */
final class StaticCallEdgeTracerTest extends TestCase
{
    private const string CALLER = 'App\\Services\\Reporter';

    /**
     * @return list<array{source: string, target: string, type: string}>
     */
    private function edges(string $body, string $uses = '', string $extends = ''): array
    {
        $source = "<?php\nnamespace App\\Services;\n{$uses}\nclass Reporter{$extends}\n{\n    public function run(): void\n    {\n        {$body}\n    }\n}\n";

        return new StaticCallEdgeTracer()->edgesForSource($source, self::CALLER);
    }

    #[Test]
    public function it_links_a_static_call_to_the_callee_member_node(): void
    {
        $edges = $this->edges('PostPublisher::all();', 'use App\Services\PostPublisher;');

        $this->assertSame([[
            'source' => self::CALLER . '::run',
            'target' => 'App\\Services\\PostPublisher::all',
            'type' => 'static-call',
        ]], $edges);
    }

    #[Test]
    public function it_links_a_self_call_to_the_declaring_class_own_member(): void
    {
        // Brain does not recurse into a class's own private methods, so a helper reached only this
        // way is invisible without the edge.
        $this->assertSame([[
            'source' => self::CALLER . '::run',
            'target' => self::CALLER . '::helper',
            'type' => 'static-call',
        ]], $this->edges('self::helper();'));
    }

    #[Test]
    public function it_links_a_late_static_binding_call_to_the_declaring_class(): void
    {
        $this->assertSame([[
            'source' => self::CALLER . '::run',
            'target' => self::CALLER . '::helper',
            'type' => 'static-call',
        ]], $this->edges('static::helper();'));
    }

    #[Test]
    public function it_links_a_parent_call_to_the_parent_member_node(): void
    {
        $edges = $this->edges('parent::build();', 'use App\Services\BaseReporter;', ' extends BaseReporter');

        $this->assertSame([[
            'source' => self::CALLER . '::run',
            'target' => 'App\\Services\\BaseReporter::build',
            'type' => 'static-call',
        ]], $edges);
    }

    #[Test]
    public function a_parent_call_in_a_class_extending_a_vendor_base_draws_nothing(): void
    {
        $this->assertSame([], $this->edges('parent::build();', 'use Illuminate\Console\Command;', ' extends Command'));
    }

    #[Test]
    public function it_ignores_vendor_and_framework_receivers(): void
    {
        // The whole point of the app gate: without it every facade call becomes an edge.
        $this->assertSame([], $this->edges('Carbon::now(); Str::of("x"); Cache::put("k", 1);'));
    }

    #[Test]
    public function it_leaves_eloquent_static_queries_to_brain(): void
    {
        // Brain already types these `model`; a second edge would count one call twice.
        $this->assertSame([], $this->edges('Post::find(1); Post::create([]);', 'use App\Models\Post;'));
    }

    #[Test]
    public function a_non_query_static_call_on_a_model_is_still_traced(): void
    {
        // Only the query verbs are Brain's; a domain method on a model class is nobody else's edge.
        $edges = $this->edges('Post::resolveLabel();', 'use App\Models\Post;');

        $this->assertSame([[
            'source' => self::CALLER . '::run',
            'target' => 'App\\Models\\Post::resolveLabel',
            'type' => 'static-call',
        ]], $edges);
    }

    #[Test]
    public function it_ignores_a_dynamic_receiver(): void
    {
        $this->assertSame([], $this->edges('$class::make();'));
    }

    #[Test]
    public function it_treats_a_first_class_callable_as_a_call(): void
    {
        $edges = $this->edges('$fn = PostPublisher::all(...);', 'use App\Services\PostPublisher;');

        $this->assertSame([[
            'source' => self::CALLER . '::run',
            'target' => 'App\\Services\\PostPublisher::all',
            'type' => 'static-call',
        ]], $edges);
    }

    #[Test]
    public function it_attributes_each_call_to_the_class_that_declares_the_calling_method(): void
    {
        // Two classes in one file: a flat method bucket would credit both calls to the first class.
        $source = "<?php\nnamespace App\\Services;\nuse App\\Services\\PostPublisher;\nclass First\n{\n    public function a(): void\n    {\n        PostPublisher::one();\n    }\n}\nclass Second\n{\n    public function b(): void\n    {\n        PostPublisher::two();\n    }\n}\n";

        $edges = new StaticCallEdgeTracer()->edgesForSource($source, 'App\\Services\\First');

        $this->assertContains(['source' => 'App\\Services\\First::a', 'target' => 'App\\Services\\PostPublisher::one', 'type' => 'static-call'], $edges);
        $this->assertContains(['source' => 'App\\Services\\Second::b', 'target' => 'App\\Services\\PostPublisher::two', 'type' => 'static-call'], $edges);
    }

    #[Test]
    public function it_dedupes_the_same_call_made_twice(): void
    {
        $this->assertCount(1, $this->edges('PostPublisher::all(); PostPublisher::all();', 'use App\Services\PostPublisher;'));
    }
}
