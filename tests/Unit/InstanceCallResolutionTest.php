<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Graph\InstanceCallResolution;
use SanderMuller\Richter\Tests\TestCase;

final class InstanceCallResolutionTest extends TestCase
{
    /**
     * @param  list<string>  $methods
     * @return list<array{source: string, target: string, type: string}>
     */
    private function declares(string $fqcn, array $methods): array
    {
        return array_map(
            static fn (string $method): array => ['source' => $fqcn, 'target' => "{$fqcn}::{$method}", 'type' => 'declares'],
            $methods,
        );
    }

    /**
     * @param  list<array{source: string, target: string, type: string}>  $edges
     * @return list<string>
     */
    private function targets(array $edges): array
    {
        return array_values(array_column(
            array_values(array_filter($edges, static fn (array $edge): bool => $edge['type'] === 'instance-call')),
            'target',
        ));
    }

    #[Test]
    public function a_method_the_class_declares_is_kept(): void
    {
        $edges = InstanceCallResolution::keepResolvable(
            [['source' => 'App\Services\Reporter::run', 'target' => 'App\Services\Reporter::build', 'type' => 'instance-call']],
            ['App\Services\Reporter' => ['parent' => null, 'declared' => ['run', 'build']]],
            ['App\Services\Reporter' => $this->declares('App\Services\Reporter', ['run', 'build'])],
        );

        $this->assertSame(['App\Services\Reporter::build'], $this->targets($edges));
    }

    #[Test]
    public function a_framework_method_no_app_class_declares_is_dropped(): void
    {
        // `$this->hasMany(...)` on an Eloquent model: the method lives in a vendor base, so the edge
        // would mint a member node the application does not own.
        $edges = InstanceCallResolution::keepResolvable(
            [['source' => 'App\Models\Post::comments', 'target' => 'App\Models\Post::hasMany', 'type' => 'instance-call']],
            ['App\Models\Post' => ['parent' => null, 'declared' => ['comments']]],
            ['App\Models\Post' => $this->declares('App\Models\Post', ['comments'])],
        );

        $this->assertSame([], $this->targets($edges));
    }

    #[Test]
    public function a_method_an_app_ancestor_declares_is_kept(): void
    {
        $edges = InstanceCallResolution::keepResolvable(
            [['source' => 'App\Formulas\CarFormula::rate', 'target' => 'App\Formulas\CarFormula::discount', 'type' => 'instance-call']],
            [
                'App\Formulas\CarFormula' => ['parent' => 'App\Formulas\BaseFormula', 'declared' => ['rate']],
                'App\Formulas\BaseFormula' => ['parent' => null, 'declared' => ['discount']],
            ],
            [
                'App\Formulas\CarFormula' => $this->declares('App\Formulas\CarFormula', ['rate']),
                'App\Formulas\BaseFormula' => $this->declares('App\Formulas\BaseFormula', ['discount']),
            ],
        );

        $this->assertSame(['App\Formulas\CarFormula::discount'], $this->targets($edges));
    }

    #[Test]
    public function a_method_a_used_trait_declares_is_kept(): void
    {
        $edges = InstanceCallResolution::keepResolvable(
            [
                ['source' => 'App\Models\Review', 'target' => 'App\Models\Concerns\WithAudits', 'type' => 'uses-trait'],
                ['source' => 'App\Models\Review::answers', 'target' => 'App\Models\Review::auditName', 'type' => 'instance-call'],
            ],
            ['App\Models\Review' => ['parent' => null, 'declared' => ['answers']]],
            [
                'App\Models\Review' => $this->declares('App\Models\Review', ['answers']),
                'App\Models\Concerns\WithAudits' => $this->declares('App\Models\Concerns\WithAudits', ['auditName']),
            ],
        );

        $this->assertSame(['App\Models\Review::auditName'], $this->targets($edges));
    }

    #[Test]
    public function an_inheritance_cycle_terminates(): void
    {
        // A malformed or mis-parsed chain must not hang the build.
        $edges = InstanceCallResolution::keepResolvable(
            [['source' => 'App\A::run', 'target' => 'App\A::missing', 'type' => 'instance-call']],
            [
                'App\A' => ['parent' => 'App\B', 'declared' => ['run']],
                'App\B' => ['parent' => 'App\A', 'declared' => []],
            ],
            ['App\A' => $this->declares('App\A', ['run'])],
        );

        $this->assertSame([], $this->targets($edges));
    }

    #[Test]
    public function every_other_edge_type_passes_through_untouched(): void
    {
        $other = [
            ['source' => 'App\Services\Reporter::run', 'target' => 'App\Services\Helper::format', 'type' => 'static-call'],
            ['source' => 'App\Models\Post', 'target' => 'App\Models\Comment', 'type' => 'model-relationship'],
        ];

        $this->assertSame($other, InstanceCallResolution::keepResolvable($other, [], []));
    }
}
