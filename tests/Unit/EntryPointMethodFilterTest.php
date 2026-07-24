<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Support\EntryPointMethodFilter;
use SanderMuller\Richter\Tests\TestCase;

final class EntryPointMethodFilterTest extends TestCase
{
    /** @return array<string, ClassMethod> method name => node */
    private function methods(string $body): array
    {
        $ast = new ParserFactory()->createForNewestSupportedVersion()->parse("<?php\nclass T\n{\n{$body}\n}\n");

        $out = [];

        foreach (new NodeFinder()->findInstanceOf($ast ?? [], ClassMethod::class) as $method) {
            $out[$method->name->toString()] = $method;
        }

        return $out;
    }

    #[Test]
    public function a_method_with_no_call_node_is_call_free(): void
    {
        $methods = $this->methods('
            public function config(): array { return ["a" => 1]; }
            public function flag(): bool { return true; }
            public function assign(): void { $this->x = 1; }
        ');

        $this->assertFalse(EntryPointMethodFilter::hasCallNode($methods['config']));
        $this->assertFalse(EntryPointMethodFilter::hasCallNode($methods['flag']));
        $this->assertFalse(EntryPointMethodFilter::hasCallNode($methods['assign']));
    }

    #[Test]
    public function every_call_node_kind_counts_as_a_call(): void
    {
        $methods = $this->methods('
            public function m1(): void { $this->svc->do(); }
            public function m2(): void { Foo::bar(); }
            public function m3(): void { $this->svc?->do(); }
            public function m4(): void { helper(); }
            public function m5(): void { new Baz(); }
        ');

        foreach (['m1', 'm2', 'm3', 'm4', 'm5'] as $name) {
            $this->assertTrue(EntryPointMethodFilter::hasCallNode($methods[$name]), $name);
        }
    }

    #[Test]
    public function a_call_nested_inside_a_closure_still_counts(): void
    {
        // The only call node lives inside the closure body — the recursive find must reach it, so
        // this method is NOT skippable.
        $methods = $this->methods('
            public function nested(): void { $fn = function () { report(); }; }
        ');

        $this->assertTrue(EntryPointMethodFilter::hasCallNode($methods['nested']));
    }
}
