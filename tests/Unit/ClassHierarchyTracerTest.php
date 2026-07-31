<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use PhpParser\Node\Stmt\ClassLike;
use PhpParser\NodeFinder;
use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Support\AppFiles;
use SanderMuller\Richter\Tests\TestCase;
use SanderMuller\Richter\Tracers\ClassHierarchyTracer;

/**
 * Plan cha-core: CHA draws `override` edges (ancestor::m → descendant::m) so a concrete override
 * reached only via polymorphic dispatch is no longer orphaned. Each source below is fed as its own
 * "file" (a separate collect() call) so the cross-file accumulation is exercised.
 */
final class ClassHierarchyTracerTest extends TestCase
{
    /**
     * @param  string  ...$sources  each a full `<?php` file; fed to the tracer as a separate file
     * @return list<array{source: string, target: string, type: string}>
     */
    private function edges(string ...$sources): array
    {
        $tracer = new ClassHierarchyTracer();

        foreach ($sources as $source) {
            $ast = AppFiles::parseResolved($source);
            $this->assertNotNull($ast, 'fixture source failed to parse');

            /** @var list<ClassLike> $classLikes */
            $classLikes = array_values(new NodeFinder()->findInstanceOf($ast, ClassLike::class));
            $tracer->collect($classLikes);
        }

        return $tracer->overrideEdges();
    }

    /** @param  list<array{source: string, target: string, type: string}>  $edges */
    private function assertHasOverride(array $edges, string $source, string $target): void
    {
        $this->assertContains(
            ['source' => $source, 'target' => $target, 'type' => 'override'],
            $edges,
            "expected override edge {$source} → {$target}",
        );
    }

    /** @param  list<array{source: string, target: string, type: string}>  $edges */
    private function assertNoOverrideTo(array $edges, string $target): void
    {
        $this->assertEmpty(
            array_filter($edges, static fn (array $edge): bool => $edge['target'] === $target),
            "expected no override edge targeting {$target}",
        );
    }

    #[Test]
    public function an_abstract_parent_method_links_to_its_concrete_override(): void
    {
        $edges = $this->edges(<<<'PHP'
            <?php declare(strict_types=1);
            namespace App\Reports;
            abstract class ReportExporter { abstract public function body(): string; }
            final class CsvExporter extends ReportExporter { public function body(): string { return ''; } }
            PHP);

        $this->assertHasOverride($edges, 'App\Reports\ReportExporter::body', 'App\Reports\CsvExporter::body');
    }

    #[Test]
    public function an_interface_method_links_to_its_implementation(): void
    {
        $edges = $this->edges(<<<'PHP'
            <?php declare(strict_types=1);
            namespace App\Reports;
            interface Exportable { public function export(): string; }
            class PdfExporter implements Exportable { public function export(): string { return ''; } }
            PHP);

        $this->assertHasOverride($edges, 'App\Reports\Exportable::export', 'App\Reports\PdfExporter::export');
    }

    #[Test]
    public function overrides_are_linked_across_separate_files(): void
    {
        // The base and its subclass live in different files — CHA must accumulate across collect() calls.
        $edges = $this->edges(
            '<?php declare(strict_types=1); namespace App\Reports; abstract class ReportExporter { abstract public function body(): string; }',
            '<?php declare(strict_types=1); namespace App\Reports; final class CsvExporter extends ReportExporter { public function body(): string { return \'\'; } }',
        );

        $this->assertHasOverride($edges, 'App\Reports\ReportExporter::body', 'App\Reports\CsvExporter::body');
    }

    #[Test]
    public function a_transitive_grandparent_method_links_to_the_override(): void
    {
        // A declares m; B (middle) does NOT; C overrides. Edge A::m → C::m; no B::m edge (B has no m).
        $edges = $this->edges(<<<'PHP'
            <?php declare(strict_types=1);
            namespace App\Reports;
            abstract class A { abstract public function m(): void; }
            abstract class B extends A {}
            final class C extends B { public function m(): void {} }
            PHP);

        $this->assertHasOverride($edges, 'App\Reports\A::m', 'App\Reports\C::m');
        $this->assertNoOverrideTo($edges, 'App\Reports\B::m');
    }

    #[Test]
    public function an_intermediate_abstract_override_is_also_linked(): void
    {
        // All three declare m: base, intermediate abstract, concrete. Every ancestor-that-declares-m
        // links to every descendant that declares it (additive, safe over-approximation).
        $edges = $this->edges(<<<'PHP'
            <?php declare(strict_types=1);
            namespace App\Reports;
            abstract class A { public function m(): void {} }
            abstract class B extends A { public function m(): void {} }
            final class C extends B { public function m(): void {} }
            PHP);

        $this->assertHasOverride($edges, 'App\Reports\A::m', 'App\Reports\B::m');
        $this->assertHasOverride($edges, 'App\Reports\B::m', 'App\Reports\C::m');
        $this->assertHasOverride($edges, 'App\Reports\A::m', 'App\Reports\C::m');
    }

    #[Test]
    public function a_method_declared_only_on_the_concrete_class_is_not_an_override(): void
    {
        $edges = $this->edges(<<<'PHP'
            <?php declare(strict_types=1);
            namespace App\Reports;
            abstract class ReportExporter { abstract public function body(): string; }
            final class CsvExporter extends ReportExporter { public function body(): string { return ''; } public function extra(): void {} }
            PHP);

        $this->assertHasOverride($edges, 'App\Reports\ReportExporter::body', 'App\Reports\CsvExporter::body');
        $this->assertNoOverrideTo($edges, 'App\Reports\CsvExporter::extra');
    }

    #[Test]
    public function private_static_and_constructor_methods_are_excluded(): void
    {
        // Only the public instance method `pub` is polymorphic; priv/stat/__construct are not.
        $edges = $this->edges(<<<'PHP'
            <?php declare(strict_types=1);
            namespace App\Reports;
            class Base {
                public function __construct() {}
                public function pub(): void {}
                private function priv(): void {}
                public static function stat(): void {}
            }
            class Child extends Base {
                public function __construct() {}
                public function pub(): void {}
                private function priv(): void {}
                public static function stat(): void {}
            }
            PHP);

        $this->assertHasOverride($edges, 'App\Reports\Base::pub', 'App\Reports\Child::pub');
        $this->assertNoOverrideTo($edges, 'App\Reports\Child::priv');
        $this->assertNoOverrideTo($edges, 'App\Reports\Child::stat');
        $this->assertNoOverrideTo($edges, 'App\Reports\Child::__construct');
    }

    #[Test]
    public function an_unscanned_vendor_ancestor_draws_no_edge(): void
    {
        // The base is a vendor class richter never collected — CHA is app-scoped, so no edge is drawn
        // (its method set is unknown).
        $edges = $this->edges(<<<'PHP'
            <?php declare(strict_types=1);
            namespace App\Reports;
            use Vendor\Package\BaseExporter;
            final class CsvExporter extends BaseExporter { public function body(): string { return ''; } }
            PHP);

        $this->assertSame([], $edges);
    }

    #[Test]
    public function an_anonymous_class_produces_no_override_edge(): void
    {
        // Anonymous classes have no FQCN — they can be neither an override source nor target.
        $edges = $this->edges(<<<'PHP'
            <?php declare(strict_types=1);
            namespace App\Reports;
            abstract class ReportExporter { abstract public function body(): string; }
            function make(): ReportExporter { return new class extends ReportExporter { public function body(): string { return ''; } }; }
            PHP);

        $this->assertSame([], $edges);
    }

    #[Test]
    public function a_class_with_no_ancestors_produces_no_edges(): void
    {
        $edges = $this->edges('<?php declare(strict_types=1); namespace App\Reports; final class Standalone { public function run(): void {} }');

        $this->assertSame([], $edges);
    }

    #[Test]
    public function a_trait_is_not_treated_as_an_override_source(): void
    {
        // A trait method is copied into the using class, not virtually dispatched — no override edge,
        // and the using class has no parent/interface to override.
        $edges = $this->edges(<<<'PHP'
            <?php declare(strict_types=1);
            namespace App\Reports;
            trait Formats { public function format(): string { return ''; } }
            final class CsvExporter { use Formats; public function format(): string { return ''; } }
            PHP);

        $this->assertSame([], $edges);
    }
}
