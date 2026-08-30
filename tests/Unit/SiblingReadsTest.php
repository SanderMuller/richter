<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Changes\SiblingReads;
use SanderMuller\Richter\Tests\TestCase;

final class SiblingReadsTest extends TestCase
{
    private function source(string $body, string $class = 'CreateTask'): string
    {
        return <<<PHP
        <?php
        namespace App\\Actions;
        use App\\Models\\Order;
        class {$class} {
            public function handle(Order \$order): void {
        {$body}
            }
        }
        PHP;
    }

    /** @return array<string, list<string>> */
    private function stylesFor(string $body, string $key = 'App\Models\Order->external_id'): array
    {
        $reads = SiblingReads::in($this->source($body));

        return array_map(array_values(...), $reads[$key] ?? []);
    }

    #[Test]
    public function a_bare_read_is_bare(): void
    {
        $this->assertSame(
            ['bare' => ['App\Actions\CreateTask::handle']],
            $this->stylesFor('        $id = $order->external_id;'),
        );
    }

    #[Test]
    public function a_coalesce_and_a_short_ternary_are_fallbacks(): void
    {
        $this->assertArrayHasKey('fallback', $this->stylesFor('        $id = $order->external_id ?? "x";'));
        $this->assertArrayHasKey('fallback', $this->stylesFor('        $id = $order->external_id ?: "x";'));
    }

    #[Test]
    public function emptiness_helpers_and_a_nullsafe_use_are_emptiness(): void
    {
        foreach (['filled', 'blank', 'empty'] as $helper) {
            $this->assertArrayHasKey('emptiness', $this->stylesFor("        if ({$helper}(\$order->external_id)) { return; }"));
        }

        $this->assertArrayHasKey('emptiness', $this->stylesFor('        $order->external_id?->format("Y");'));
    }

    #[Test]
    public function a_null_comparison_is_a_null_test_and_never_soft(): void
    {
        $styles = $this->stylesFor('        if ($order->external_id === null) { return; }');

        $this->assertArrayHasKey('null-test', $styles);
        $this->assertSame([], array_intersect(array_keys($styles), SiblingReads::SOFT_STYLES));
    }

    #[Test]
    public function a_guard_through_a_local_disarms_the_read(): void
    {
        // The guard arrives one line later, through the variable the read was assigned into.
        $this->assertArrayHasKey('emptiness', $this->stylesFor(
            "        \$id = \$order->external_id;\n        if (! \$id) { return; }",
        ));
    }

    #[Test]
    public function only_the_outermost_fetch_of_a_guarded_expression_is_guarded(): void
    {
        // `$order->customer->name ?? 'x'` guards the NAME. It says nothing about `customer`, which is
        // only a receiver on the way there, so that read stays bare.
        $reads = SiblingReads::in($this->source("        \$name = \$order->customer->name ?? 'x';"));

        $this->assertSame(['bare'], array_keys($reads['App\Models\Order->customer']));

        // And the chained receiver itself records no read for `name`: `$order->customer` has no
        // declared type, which is the no-guess rule rather than an oversight.
        $this->assertArrayNotHasKey('App\Models\Customer->name', $reads);
    }

    #[Test]
    public function a_write_is_not_a_read(): void
    {
        $this->assertSame([], SiblingReads::in($this->source('        $order->external_id = "x";')));
        $this->assertSame([], SiblingReads::in($this->source('        unset($order->external_id);')));
    }

    #[Test]
    public function a_read_modify_write_is_a_read(): void
    {
        $this->assertArrayHasKey('bare', $this->stylesFor('        $order->external_id .= "x";'));
    }

    #[Test]
    public function an_untyped_receiver_records_nothing(): void
    {
        $source = <<<'PHP'
        <?php
        namespace App\Actions;
        class CreateTask {
            public function handle($order): void { $id = $order->external_id; }
        }
        PHP;

        $this->assertSame([], SiblingReads::in($source));
    }

    #[Test]
    public function a_vendor_typed_receiver_records_nothing(): void
    {
        $source = <<<'PHP'
        <?php
        namespace App\Actions;
        use Illuminate\Http\Request;
        class CreateTask {
            public function handle(Request $request): void { $id = $request->external_id; }
        }
        PHP;

        // App-scoped like every other lane: a vendor class is not this application's to compare.
        $this->assertSame([], SiblingReads::in($source));
    }

    #[Test]
    public function keys_are_fqcns_so_two_same_named_classes_never_collide(): void
    {
        $source = <<<'PHP'
        <?php
        namespace App\Actions;
        use App\Models\Billing\Order;
        class CreateTask {
            public function handle(Order $order): void { $id = $order->external_id; }
        }
        PHP;

        $this->assertArrayHasKey('App\Models\Billing\Order->external_id', SiblingReads::in($source));
    }

    #[Test]
    public function only_the_named_members_are_read_when_a_filter_is_given(): void
    {
        $source = <<<'PHP'
        <?php
        namespace App\Actions;
        use App\Models\Order;
        class CreateTask {
            public function changed(Order $order): void { $id = $order->external_id; }
            public function untouched(Order $order): void { $id = $order->reference; }
        }
        PHP;

        $reads = SiblingReads::in($source, ['changed']);

        $this->assertArrayHasKey('App\Models\Order->external_id', $reads);
        $this->assertArrayNotHasKey('App\Models\Order->reference', $reads);
    }

    #[Test]
    public function unparseable_source_reads_as_nothing(): void
    {
        $this->assertSame([], SiblingReads::in('<?php class {{{'));
    }
}
