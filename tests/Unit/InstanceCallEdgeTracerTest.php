<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Tests\TestCase;
use SanderMuller\Richter\Tracers\InstanceCallEdgeTracer;

final class InstanceCallEdgeTracerTest extends TestCase
{
    /** @return list<array{source: string, target: string, type: string}> */
    private function edges(string $body): array
    {
        return new InstanceCallEdgeTracer()->edgesForSource("<?php\nnamespace App\\Services;\n{$body}\n");
    }

    #[Test]
    public function a_call_on_this_links_the_two_members(): void
    {
        $edges = $this->edges(<<<'PHP'
            class Reporter
            {
                public function run(): string { return $this->build(); }
                public function build(): string { return 'x'; }
            }
            PHP);

        $this->assertSame([[
            'source' => 'App\Services\Reporter::run',
            'target' => 'App\Services\Reporter::build',
            'type' => 'instance-call',
        ]], $edges);
    }

    #[Test]
    public function a_nullsafe_call_on_this_links_the_same_way(): void
    {
        $edges = $this->edges(<<<'PHP'
            class Reporter
            {
                public function run(): ?string { return $this?->build(); }
                public function build(): string { return 'x'; }
            }
            PHP);

        $this->assertSame('App\Services\Reporter::build', $edges[0]['target']);
    }

    #[Test]
    public function a_call_on_a_method_the_class_inherits_still_links(): void
    {
        // The node may not be declared here. That is deliberate: `inheritedEdges()` connects the
        // referenced `Subclass::method` node to the ancestor whose body runs, and it only draws that
        // edge for member nodes something already references — this lane is what references them.
        $edges = $this->edges(<<<'PHP'
            class CsvReporter extends BaseReporter
            {
                public function run(): string { return $this->format(); }
            }
            PHP);

        $this->assertSame('App\Services\CsvReporter::format', $edges[0]['target']);
    }

    #[Test]
    public function recursion_draws_nothing(): void
    {
        $edges = $this->edges(<<<'PHP'
            class Walker
            {
                public function walk(int $depth): int { return $depth > 0 ? $this->walk($depth - 1) : 0; }
            }
            PHP);

        $this->assertSame([], $edges);
    }

    #[Test]
    public function a_dynamic_method_name_draws_nothing(): void
    {
        $edges = $this->edges(<<<'PHP'
            class Reporter
            {
                public function run(string $name): mixed { return $this->{$name}(); }
            }
            PHP);

        $this->assertSame([], $edges);
    }

    #[Test]
    public function a_first_class_callable_draws_nothing(): void
    {
        // `$this->build(...)` makes a closure. It does not call the method, so an edge here would
        // report a caller that never runs.
        $edges = $this->edges(<<<'PHP'
            class Reporter
            {
                public function run(): callable { return $this->build(...); }
                public function build(): string { return 'x'; }
            }
            PHP);

        $this->assertSame([], $edges);
    }

    #[Test]
    public function a_call_on_anything_other_than_this_draws_nothing(): void
    {
        // A property, a parameter and a local are receiver-typing problems, and this lane refuses
        // them rather than guessing which class they hold.
        $edges = $this->edges(<<<'PHP'
            class Reporter
            {
                public function __construct(private Helper $helper) {}
                public function run(Helper $other): string
                {
                    $local = $this->helper;

                    return $this->helper->format() . $other->format() . $local->format();
                }
            }
            PHP);

        $this->assertSame([], $edges);
    }

    #[Test]
    public function this_inside_an_anonymous_class_belongs_to_that_class(): void
    {
        // `$this` there is the anonymous class, which has no name to link to. Attributing the call
        // to the enclosing method would draw a confidently wrong edge.
        $edges = $this->edges(<<<'PHP'
            class Reporter
            {
                public function run(): object
                {
                    return new class
                    {
                        public function inner(): string { return $this->deeper(); }
                        public function deeper(): string { return 'x'; }
                    };
                }
            }
            PHP);

        $this->assertSame([], $edges);
    }

    #[Test]
    public function a_call_inside_a_closure_still_belongs_to_the_method(): void
    {
        // A closure keeps the enclosing `$this`, so the call is the method's own.
        $edges = $this->edges(<<<'PHP'
            class Reporter
            {
                public function run(): callable
                {
                    return function (): string {
                        return $this->build();
                    };
                }

                public function build(): string { return 'x'; }
            }
            PHP);

        $this->assertSame('App\Services\Reporter::build', $edges[0]['target']);
    }

    #[Test]
    public function a_trait_draws_its_calls_and_leaves_the_filtering_to_the_merge_step(): void
    {
        // Inside a trait, `$this` is the consuming class at runtime, so a method the trait does not
        // have must not be linked to it. Whether the trait HAS it is a whole-tree question once
        // traits use other traits, so this lane draws both and `InstanceCallResolution` decides.
        $edges = $this->edges(<<<'PHP'
            trait Audits
            {
                public function audit(): string { return $this->auditName() . $this->tableName(); }
                public function auditName(): string { return 'audit'; }
            }
            PHP);

        $this->assertSame([
            ['source' => 'App\Services\Audits::audit', 'target' => 'App\Services\Audits::auditName', 'type' => 'instance-call'],
            ['source' => 'App\Services\Audits::audit', 'target' => 'App\Services\Audits::tableName', 'type' => 'instance-call'],
        ], $edges);
    }

    #[Test]
    public function two_classes_in_one_file_keep_their_own_calls(): void
    {
        $edges = $this->edges(<<<'PHP'
            class First
            {
                public function run(): string { return $this->build(); }
                public function build(): string { return 'a'; }
            }

            class Second
            {
                public function run(): string { return $this->render(); }
                public function render(): string { return 'b'; }
            }
            PHP);

        $this->assertSame([
            ['source' => 'App\Services\First::run', 'target' => 'App\Services\First::build', 'type' => 'instance-call'],
            ['source' => 'App\Services\Second::run', 'target' => 'App\Services\Second::render', 'type' => 'instance-call'],
        ], $edges);
    }
}
