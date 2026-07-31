<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use PhpParser\Node\Stmt\ClassLike;
use PhpParser\NodeFinder;
use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Support\AppFiles;
use SanderMuller\Richter\Tests\TestCase;
use SanderMuller\Richter\Tracers\ConstantReferenceTracer;

/**
 * Plan cref-core: constants and enum cases get member nodes + `references-constant` reader edges,
 * with reads resolved to the constant's DECLARING class so an inherited-constant read still connects.
 * Each source is fed as its own "file" so cross-file accumulation is exercised.
 */
final class ConstantReferenceTracerTest extends TestCase
{
    /**
     * @return list<array{source: string, target: string, type: string}>
     */
    private function edges(string ...$sources): array
    {
        $tracer = new ConstantReferenceTracer();

        foreach ($sources as $source) {
            $ast = AppFiles::parseResolved($source);
            $this->assertNotNull($ast, 'fixture failed to parse');

            /** @var list<ClassLike> $classLikes */
            $classLikes = array_values(new NodeFinder()->findInstanceOf($ast, ClassLike::class));
            $tracer->collect($classLikes);
        }

        return $tracer->edges();
    }

    /** @param  list<array{source: string, target: string, type: string}>  $edges */
    private function assertHasEdge(array $edges, string $source, string $target, string $type): void
    {
        $this->assertContains(['source' => $source, 'target' => $target, 'type' => $type], $edges, "missing {$type} edge {$source} → {$target}");
    }

    /** @param  list<array{source: string, target: string, type: string}>  $edges */
    private function assertNoReferenceConstant(array $edges): void
    {
        $this->assertSame([], array_values(array_filter($edges, static fn (array $e): bool => $e['type'] === 'references-constant')), 'expected no references-constant edge');
    }

    #[Test]
    public function a_same_class_constant_read_links_the_reader_to_the_constant(): void
    {
        $edges = $this->edges(<<<'PHP'
            <?php declare(strict_types=1);
            namespace App\Money;
            final class Pricing {
                public const float VAT = 0.21;
                public function withTax(): float { return self::VAT; }
            }
            PHP);

        $this->assertHasEdge($edges, 'App\Money\Pricing', 'App\Money\Pricing::VAT', 'declares');
        $this->assertHasEdge($edges, 'App\Money\Pricing::withTax', 'App\Money\Pricing::VAT', 'references-constant');
    }

    #[Test]
    public function an_inherited_constant_read_resolves_to_the_declaring_ancestor(): void
    {
        // Base declares SCALE; both Base::r and Child::c read `static::SCALE`. Both edges MUST target
        // Base::SCALE — targeting Child::SCALE would drop Child::c from a change to Base's constant.
        $edges = $this->edges(<<<'PHP'
            <?php declare(strict_types=1);
            namespace App\Money;
            abstract class Base {
                protected const int SCALE = 2;
                public function r(): int { return static::SCALE; }
            }
            final class Child extends Base {
                public function c(): int { return static::SCALE; }
            }
            PHP);

        $this->assertHasEdge($edges, 'App\Money\Base', 'App\Money\Base::SCALE', 'declares');
        $this->assertHasEdge($edges, 'App\Money\Base::r', 'App\Money\Base::SCALE', 'references-constant');
        $this->assertHasEdge($edges, 'App\Money\Child::c', 'App\Money\Base::SCALE', 'references-constant');
    }

    #[Test]
    public function an_interface_constant_read_resolves_to_the_interface(): void
    {
        $edges = $this->edges(<<<'PHP'
            <?php declare(strict_types=1);
            namespace App\Money;
            interface HasScale { public const int SCALE = 2; }
            final class Impl implements HasScale { public function s(): int { return self::SCALE; } }
            PHP);

        $this->assertHasEdge($edges, 'App\Money\Impl::s', 'App\Money\HasScale::SCALE', 'references-constant');
    }

    #[Test]
    public function a_cross_class_constant_read_links_to_the_owner(): void
    {
        $edges = $this->edges(<<<'PHP'
            <?php declare(strict_types=1);
            namespace App\Money;
            final class Rates { public const float VAT = 0.21; }
            final class Invoice { public function total(): float { return Rates::VAT; } }
            PHP);

        $this->assertHasEdge($edges, 'App\Money\Invoice::total', 'App\Money\Rates::VAT', 'references-constant');
    }

    #[Test]
    public function a_this_qualified_read_resolves_to_the_enclosing_class(): void
    {
        $edges = $this->edges(<<<'PHP'
            <?php declare(strict_types=1);
            namespace App\Money;
            final class Pricing {
                public const float VAT = 0.21;
                public function withTax(): float { return $this::VAT; }
            }
            PHP);

        $this->assertHasEdge($edges, 'App\Money\Pricing::withTax', 'App\Money\Pricing::VAT', 'references-constant');
    }

    #[Test]
    public function an_enum_case_read_links_to_the_case(): void
    {
        $edges = $this->edges(<<<'PHP'
            <?php declare(strict_types=1);
            namespace App\Order;
            enum Status { case Draft; case Shipped; }
            final class Svc { public function done(Status $s): bool { return $s === Status::Shipped; } }
            PHP);

        $this->assertHasEdge($edges, 'App\Order\Status', 'App\Order\Status::Shipped', 'declares');
        $this->assertHasEdge($edges, 'App\Order\Svc::done', 'App\Order\Status::Shipped', 'references-constant');
    }

    #[Test]
    public function a_class_magic_constant_is_not_a_constant_reference(): void
    {
        $edges = $this->edges(<<<'PHP'
            <?php declare(strict_types=1);
            namespace App\Money;
            final class Rates {}
            final class Foo { public function m(): string { return Rates::class; } }
            PHP);

        $this->assertNoReferenceConstant($edges);
    }

    #[Test]
    public function a_dynamic_owner_is_skipped(): void
    {
        $edges = $this->edges(<<<'PHP'
            <?php declare(strict_types=1);
            namespace App\Money;
            final class Foo { public function m(string $x): mixed { return $x::SOME; } }
            PHP);

        $this->assertNoReferenceConstant($edges);
    }

    #[Test]
    public function a_vendor_owner_draws_no_edge(): void
    {
        // The owner is a class richter never scanned — app-scoped, so no edge (a change to it would
        // read UNRESOLVED, honestly, not "no impact").
        $edges = $this->edges(<<<'PHP'
            <?php declare(strict_types=1);
            namespace App\Money;
            use Vendor\Config;
            final class Foo { public function m(): mixed { return Config::TIMEOUT; } }
            PHP);

        $this->assertNoReferenceConstant($edges);
    }

    #[Test]
    public function a_read_nowhere_constant_still_nodes_via_a_declares_edge(): void
    {
        $edges = $this->edges(<<<'PHP'
            <?php declare(strict_types=1);
            namespace App\Money;
            final class Foo { public const int UNUSED = 1; public function m(): void {} }
            PHP);

        $this->assertHasEdge($edges, 'App\Money\Foo', 'App\Money\Foo::UNUSED', 'declares');
        $this->assertNoReferenceConstant($edges);
    }

    #[Test]
    public function a_constant_read_in_a_parameter_default_is_a_reader(): void
    {
        // The read lives in the method's parameter default, not its body — it must still draw a reader
        // edge (a body-only walk would miss it and, with the resolvable flip, read a false "no impact").
        $edges = $this->edges(<<<'PHP'
            <?php declare(strict_types=1);
            namespace App\Money;
            final class Report {
                public const int SCALE = 2;
                public function render(int $scale = self::SCALE): string { return (string) $scale; }
            }
            PHP);

        $this->assertHasEdge($edges, 'App\Money\Report::render', 'App\Money\Report::SCALE', 'references-constant');
    }

    #[Test]
    public function a_scope_relative_read_in_a_nested_anonymous_class_is_not_attributed_to_the_outer_class(): void
    {
        // `self::LIMIT` inside the anonymous class refers to the anon's own LIMIT (no node), NOT the
        // outer Widget::LIMIT — so no false edge to Widget::LIMIT. A NAMED read inside the anon still
        // connects (a name resolves the same regardless of nesting).
        $edges = $this->edges(<<<'PHP'
            <?php declare(strict_types=1);
            namespace App\Widgets;
            final class Rates { public const int MAX = 9; }
            final class Widget {
                public const int LIMIT = 100;
                public function build(): object {
                    return new class {
                        public const int LIMIT = 5;
                        public function cap(int $n): int { return min($n, self::LIMIT) + Rates::MAX; }
                    };
                }
            }
            PHP);

        $this->assertNotContains(
            ['source' => 'App\Widgets\Widget::build', 'target' => 'App\Widgets\Widget::LIMIT', 'type' => 'references-constant'],
            $edges,
            'a self:: read inside a nested anonymous class must not be attributed to the outer class',
        );
        $this->assertHasEdge($edges, 'App\Widgets\Widget::build', 'App\Widgets\Rates::MAX', 'references-constant');
    }
}
