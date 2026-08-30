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
    public function a_truthiness_test_tolerates_absence_the_same_way_an_emptiness_helper_does(): void
    {
        // `! $order->flag` answers the same for null and for false, which on a tri-state boolean
        // column is how code says "absent is fine". Reported from production: the lane called this
        // bare and raised a finding on it.
        $this->assertArrayHasKey('emptiness', $this->stylesFor('        if (! $order->external_id) { return; }'));
        $this->assertArrayHasKey('emptiness', $this->stylesFor('        if ($order->external_id) { return; }'));
        $this->assertArrayHasKey('emptiness', $this->stylesFor('        $x = $order->external_id ? 1 : 2;'));
    }

    private function isSoft(string $body): bool
    {
        return array_intersect(array_keys($this->stylesFor($body)), SiblingReads::SOFT_STYLES) !== [];
    }

    #[Test]
    public function a_direct_test_and_a_test_through_a_local_agree(): void
    {
        // The same tolerance must not depend on whether the value passes through a variable first.
        // It did: the direct form read as bare while the local form read as guarded.
        // EVERY soft form, both ways round. One table feeds the fetch path and the local path, and a
        // form present in one but missing from the other is the defect this asserts against. Three
        // such gaps have shipped: boolean negation, a plain `if`, and `empty()`/`isset()` on a local.
        foreach ([
            'if (! %s) { return; }',
            'if (%s) { return; }',
            '$x = %s ? 1 : 2;',
            'if (empty(%s)) { return; }',
            'if (isset(%s)) { return; }',
            'if (filled(%s)) { return; }',
            'if (blank(%s)) { return; }',
            '$x = %s ?? 1;',
            '$x = %s ?: 1;',
            '%s ??= 1;',
            'if (false) { } elseif (%s) { return; }',
            'while (%s) { break; }',
            'do { break; } while (%s);',
            'for (; %s;) { break; }',
            'if ($other && %s) { return; }',
            'if (%s && $other) { return; }',
            'if ($other || %s) { return; }',
            'if (%s || $other) { return; }',
            'if ($other and %s) { return; }',
            'if ($other or %s) { return; }',
            'if ($other xor %s) { return; }',
            'for ($i = 0; $i < 2, %s;) { break; }',
            '$b = (bool) %s;',
            '$r = match (true) { %s => 1, default => 2 };',
            'switch (true) { case %s: break; }',
        ] as $form) {
            $viaLocal = '        $id = $order->external_id;' . "\n" . '        ' . sprintf($form, '$id');
            $direct = '        ' . sprintf($form, '$order->external_id');

            // Both must be soft, not merely equal: asserting equality alone passes when a form is
            // wrongly hard on BOTH paths, which is the failure this test exists to catch.
            //
            // SOFT, not the same label. A guard reaching a read through a local cannot report which
            // soft form it was, so `$id ?? 1` and `$id ?: 1` record emptiness where the direct forms
            // record fallback. That difference never reaches a reader: a soft read produces no
            // finding, so no label is printed for it.
            $this->assertTrue($this->isSoft($direct), "direct: {$form}");
            $this->assertTrue($this->isSoft($viaLocal), "via local: {$form}");
        }
    }

    #[Test]
    public function a_null_test_stays_hard_on_both_paths(): void
    {
        // The mirror of the soft-form parity test. A `=== null` distinguishes null from false rather
        // than folding them together, so it must never suppress a finding — and it did, through a
        // local, because the local path graded every guard as emptiness.
        foreach (['if (%s === null) { return; }', 'if (%s !== null) { return; }', 'if (is_null(%s)) { return; }'] as $form) {
            $viaLocal = '        $id = $order->external_id;' . "\n" . '        ' . sprintf($form, '$id');
            $direct = '        ' . sprintf($form, '$order->external_id');

            $this->assertFalse($this->isSoft($direct), "direct: {$form}");
            $this->assertFalse($this->isSoft($viaLocal), "via local: {$form}");
        }
    }

    #[Test]
    public function only_the_last_condition_of_a_for_loop_is_a_truth_test(): void
    {
        // `for ($i = 0; $a, $b;)` evaluates both and continues on `$b` alone. An earlier expression
        // is a plain read, and treating it as a guard would silence one the loop never tested.
        $this->assertTrue($this->isSoft('        for ($i = 0; $i < 2, $order->external_id;) { break; }'));
        $this->assertFalse($this->isSoft('        for ($i = 0; $order->external_id, $i < 2;) { break; }'));
    }

    #[Test]
    public function a_comparison_to_something_other_than_null_guards_nothing(): void
    {
        // `$id === $other` says nothing about absence. Treating it as a guard would silence a read
        // the source never guarded.
        $this->assertFalse($this->isSoft('        $id = $order->external_id;' . "\n" . '        if ($id === $other) { return; }'));
        $this->assertSame(['bare'], array_keys($this->stylesFor('        $id = $order->external_id;' . "\n" . '        if ($id === $other) { return; }')));
    }

    #[Test]
    public function a_guard_on_something_the_property_was_passed_to_is_not_a_guard_on_the_property(): void
    {
        // `accepts()` is free to reject null, to tell it from false, or to hand it on. The truthiness
        // test speaks about what it returned, not about the value it was given. Marking the nested
        // read would suppress a finding here and, on the evidence side, manufacture soft evidence.
        foreach ([
            '        if (accepts($order->external_id)) { return; }',
            '        if (! accepts($order->external_id)) { return; }',
            '        $x = accepts($order->external_id) ? 1 : 2;',
            '        $x = $this->wrap($order->external_id) ?? 1;',
        ] as $body) {
            $this->assertSame(['bare'], array_keys($this->stylesFor($body)), $body);
        }
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
