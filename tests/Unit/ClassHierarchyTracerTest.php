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

    /**
     * The inherited lane, which is edge-set-driven: the caller supplies the member nodes that already
     * exist in the graph, so nothing is drawn for a method nothing calls.
     *
     * @param  list<array{source: string, target: string, type: string}>  $edges
     * @param  string  ...$sources  each a full `<?php` file
     * @return list<array{source: string, target: string, type: string}>
     */
    private function inherited(array $edges, string ...$sources): array
    {
        $tracer = new ClassHierarchyTracer();

        foreach ($sources as $source) {
            $ast = AppFiles::parseResolved($source);
            $this->assertNotNull($ast, 'fixture source failed to parse');

            /** @var list<ClassLike> $classLikes */
            $classLikes = array_values(new NodeFinder()->findInstanceOf($ast, ClassLike::class));
            $tracer->collect($classLikes);
        }

        return $tracer->inheritedEdges($edges);
    }

    /**
     * A caller edge onto a member node, the shape Brain produces for `$subclass->method()`.
     *
     * @return array{source: string, target: string, type: string}
     */
    private function callEdge(string $memberNode): array
    {
        return ['source' => 'App\\Console\\Commands\\SendCommand::handle', 'target' => $memberNode, 'type' => 'service'];
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
        $this->assertSame(
            [],
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

    #[Test]
    public function an_inherited_method_connects_the_subclass_node_to_the_parent_that_declares_it(): void
    {
        // The F2 shape: every real call arrives on `MappingService::build` (the receiver's static
        // type), while the code runs in the parent — two unconnected nodes until this edge.
        $parent = "<?php\nnamespace App\\Services;\nclass ApiService\n{\n    public function build(): void\n    {\n    }\n}\n";
        $child = "<?php\nnamespace App\\Services;\nclass MappingService extends ApiService\n{\n}\n";

        $edges = $this->inherited([$this->callEdge('App\\Services\\MappingService::build')], $parent, $child);

        $this->assertSame([[
            'source' => 'App\\Services\\MappingService::build',
            'target' => 'App\\Services\\ApiService::build',
            'type' => 'inherits',
        ]], $edges);
    }

    #[Test]
    public function it_resolves_to_the_nearest_declaring_ancestor(): void
    {
        $a = "<?php\nnamespace App\\Services;\nclass A\n{\n    public function build(): void\n    {\n    }\n}\n";
        $b = "<?php\nnamespace App\\Services;\nclass B extends A\n{\n    public function build(): void\n    {\n    }\n}\n";
        $c = "<?php\nnamespace App\\Services;\nclass C extends B\n{\n}\n";

        $edges = $this->inherited([$this->callEdge('App\\Services\\C::build')], $a, $b, $c);

        // B, not A — B is the one that actually runs.
        $this->assertSame([[
            'source' => 'App\\Services\\C::build',
            'target' => 'App\\Services\\B::build',
            'type' => 'inherits',
        ]], $edges);
    }

    #[Test]
    public function an_overridden_method_draws_no_inherits_edge(): void
    {
        // overrideEdges() already links these; both would double-link the same pair.
        $parent = "<?php\nnamespace App\\Services;\nclass ApiService\n{\n    public function build(): void\n    {\n    }\n}\n";
        $child = "<?php\nnamespace App\\Services;\nclass MappingService extends ApiService\n{\n    public function build(): void\n    {\n    }\n}\n";

        $this->assertSame([], $this->inherited([$this->callEdge('App\\Services\\MappingService::build')], $parent, $child));
    }

    #[Test]
    public function a_privately_redeclared_method_draws_no_inherits_edge(): void
    {
        // `methods` filters private/static/__construct away, so the declared-name list is what
        // answers "does this class write the method out itself?" — a private same-name method does.
        $parent = "<?php\nnamespace App\\Services;\nclass ApiService\n{\n    public function build(): void\n    {\n    }\n}\n";
        $child = "<?php\nnamespace App\\Services;\nclass MappingService extends ApiService\n{\n    private function build(): void\n    {\n    }\n}\n";

        $this->assertSame([], $this->inherited([$this->callEdge('App\\Services\\MappingService::build')], $parent, $child));
    }

    #[Test]
    public function a_vendor_ancestor_draws_nothing(): void
    {
        // The parent was never scanned, so its methods are unknown — the walk stops rather than
        // guessing that the vendor base declares it.
        $child = "<?php\nnamespace App\\Services;\nuse Illuminate\\Console\\Command;\nclass MappingService extends Command\n{\n}\n";

        $this->assertSame([], $this->inherited([$this->callEdge('App\\Services\\MappingService::handle')], $child));
    }

    #[Test]
    public function a_method_nothing_calls_draws_nothing(): void
    {
        // Edge-set-driven: no member node in the graph, no edge — and so no phantom node either.
        $parent = "<?php\nnamespace App\\Services;\nclass ApiService\n{\n    public function build(): void\n    {\n    }\n}\n";
        $child = "<?php\nnamespace App\\Services;\nclass MappingService extends ApiService\n{\n}\n";

        $this->assertSame([], $this->inherited([], $parent, $child));
    }

    #[Test]
    public function it_emits_one_edge_per_member_node_however_many_callers(): void
    {
        $parent = "<?php\nnamespace App\\Services;\nclass ApiService\n{\n    public function build(): void\n    {\n    }\n}\n";
        $child = "<?php\nnamespace App\\Services;\nclass MappingService extends ApiService\n{\n}\n";

        $edges = $this->inherited([
            $this->callEdge('App\\Services\\MappingService::build'),
            ['source' => 'App\\Console\\Commands\\OtherCommand::handle', 'target' => 'App\\Services\\MappingService::build', 'type' => 'service'],
        ], $parent, $child);

        $this->assertCount(1, $edges);
    }

    #[Test]
    public function a_method_a_trait_supplies_connects_the_using_class_node_to_the_trait(): void
    {
        // The call names the using class, while the body lives in the trait.
        $enum = "<?php\nnamespace App\\Enums;\nenum Status: string\n{\n    use \\App\\Enums\\Concerns\\SortsCases;\n    case Open = 'open';\n}\n";

        $edges = $this->inherited([
            $this->callEdge('App\\Enums\\Status::sorted'),
            ['source' => 'App\\Enums\\Status', 'target' => 'App\\Enums\\Concerns\\SortsCases', 'type' => 'uses-trait'],
            ['source' => 'App\\Enums\\Concerns\\SortsCases', 'target' => 'App\\Enums\\Concerns\\SortsCases::sorted', 'type' => 'declares'],
        ], $enum);

        $this->assertSame([[
            'source' => 'App\\Enums\\Status::sorted',
            'target' => 'App\\Enums\\Concerns\\SortsCases::sorted',
            'type' => 'inherits',
        ]], $edges);
    }

    #[Test]
    public function a_trait_a_trait_uses_and_a_parent_s_trait_are_both_found(): void
    {
        $parent = "<?php\nnamespace App\\Models;\nclass Entry\n{\n}\n";
        $child = "<?php\nnamespace App\\Models;\nclass Comment extends Entry\n{\n}\n";

        $edges = $this->inherited([
            $this->callEdge('App\\Models\\Comment::label'),
            ['source' => 'App\\Models\\Entry', 'target' => 'App\\Models\\HasLabel', 'type' => 'uses-trait'],
            ['source' => 'App\\Models\\HasLabel', 'target' => 'App\\Models\\FormatsLabel', 'type' => 'uses-trait'],
            ['source' => 'App\\Models\\FormatsLabel', 'target' => 'App\\Models\\FormatsLabel::label', 'type' => 'declares'],
        ], $parent, $child);

        $this->assertSame('App\\Models\\FormatsLabel::label', $edges[0]['target'] ?? null);
    }

    #[Test]
    public function a_method_the_class_declares_itself_draws_no_trait_edge(): void
    {
        $class = "<?php\nnamespace App\\Models;\nclass Comment\n{\n    public function label(): string\n    {\n        return '';\n    }\n}\n";

        $this->assertSame([], $this->inherited([
            $this->callEdge('App\\Models\\Comment::label'),
            ['source' => 'App\\Models\\Comment', 'target' => 'App\\Models\\HasLabel', 'type' => 'uses-trait'],
            ['source' => 'App\\Models\\HasLabel', 'target' => 'App\\Models\\HasLabel::label', 'type' => 'declares'],
        ], $class));
    }

    /** @return list<string>|null */
    private function subtypesOf(string $type, string ...$sources): ?array
    {
        $tracer = new ClassHierarchyTracer();

        foreach ($sources as $source) {
            $ast = AppFiles::parseResolved($source);
            $this->assertNotNull($ast, 'fixture source failed to parse');

            /** @var list<ClassLike> $classLikes */
            $classLikes = array_values(new NodeFinder()->findInstanceOf($ast, ClassLike::class));
            $tracer->collect($classLikes);
        }

        $subtypes = $tracer->concreteSubtypesOf($type);

        if ($subtypes !== null) {
            sort($subtypes);
        }

        return $subtypes;
    }

    #[Test]
    public function concrete_subtypes_cover_a_grandchild_and_a_child_interface(): void
    {
        $this->assertSame(['App\C', 'App\D'], $this->subtypesOf(
            'App\I',
            "<?php\nnamespace App;\ninterface I {}",
            "<?php\nnamespace App;\ninterface J extends I {}",
            "<?php\nnamespace App;\nabstract class A implements I {}",
            "<?php\nnamespace App;\nabstract class B extends A {}",
            "<?php\nnamespace App;\nfinal class C extends B {}",
            "<?php\nnamespace App;\nfinal class D implements J {}",
        ));
    }

    #[Test]
    public function a_concrete_type_is_its_own_subtype(): void
    {
        $this->assertSame(['App\C'], $this->subtypesOf('App\C', "<?php\nnamespace App;\nclass C {}"));
    }

    #[Test]
    public function a_scanned_type_with_no_concrete_subtype_has_none(): void
    {
        $this->assertSame([], $this->subtypesOf('App\I', "<?php\nnamespace App;\ninterface I {}"));
    }

    #[Test]
    public function an_unscanned_type_has_unknown_subtypes(): void
    {
        $this->assertNull($this->subtypesOf('App\I', "<?php\nnamespace App;\nclass C {}"));
    }

    #[Test]
    public function an_anonymous_class_extending_a_subtype_makes_the_subtypes_unknown(): void
    {
        $this->assertNull($this->subtypesOf(
            'App\A',
            "<?php\nnamespace App;\nabstract class A {}",
            "<?php\nnamespace App;\nabstract class B extends A {}",
            "<?php\nnamespace App;\nfinal class C extends B {}",
            "<?php\nnamespace App;\nfinal class F { public function make(): A { return new class extends B {}; } }",
        ));
    }

    #[Test]
    public function an_anonymous_class_implementing_a_child_interface_makes_the_subtypes_unknown(): void
    {
        $this->assertNull($this->subtypesOf(
            'App\I',
            "<?php\nnamespace App;\ninterface I {}",
            "<?php\nnamespace App;\ninterface J extends I {}",
            "<?php\nnamespace App;\nfinal class F { public function make(): I { return new class implements J {}; } }",
        ));
    }
}
