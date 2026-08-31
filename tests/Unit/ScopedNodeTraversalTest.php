<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Support\AppFiles;
use SanderMuller\Richter\Tests\TestCase;

/**
 * The two scope rules {@see AppFiles::nodesOwnedByWithNesting()} enforces, asserted on the helper
 * itself rather than only through the three tracers that depend on them.
 *
 * They decide those tracers' correctness — getting either wrong reappears as a
 * phantom edge or a confidently wrong receiver, not as a failure here — so a change to them should
 * break this test first, where the rule is stated, instead of a lane fixture somewhere downstream.
 */
final class ScopedNodeTraversalTest extends TestCase
{
    private function method(string $source): ClassMethod
    {
        $ast = AppFiles::parseResolved($source);
        $this->assertNotNull($ast);

        foreach (new NodeFinder()->findInstanceOf($ast, ClassMethod::class) as $method) {
            if ($method instanceof ClassMethod && $method->name->toString() === 'run') {
                return $method;
            }
        }

        self::fail('no method run');
    }

    /** @return list<array{0: string, 1: bool}> the call name and its nested flag, in traversal order */
    private function staticCalls(ClassMethod $method): array
    {
        $calls = [];

        foreach (AppFiles::nodesOwnedByWithNesting($method, static fn (Node $node): bool => $node instanceof StaticCall) as [$call, $nested]) {
            $this->assertInstanceOf(StaticCall::class, $call);
            // `StaticCall::$name` is an Identifier for a literal method name and an Expr for a dynamic
            // one; every call in these fixtures is literal.
            $this->assertInstanceOf(Identifier::class, $call->name);
            $calls[] = [$call->name->toString(), $nested];
        }

        return $calls;
    }

    #[Test]
    public function a_named_nested_class_is_pruned(): void
    {
        // It reaches the caller as a class-like in its own right, so descending into it here would
        // attribute the same call twice — one call, two edges.
        $method = $this->method(<<<'PHP'
            <?php
            namespace App\Services;

            class Outer
            {
                public function run(): void
                {
                    Alpha::visible();

                    class Inner
                    {
                        public function hidden(): void
                        {
                            Beta::pruned();
                        }
                    }
                }
            }
            PHP);

        $this->assertSame([['visible', false]], $this->staticCalls($method));
    }

    #[Test]
    public function an_anonymous_class_is_descended_and_flagged_nested(): void
    {
        // It is scanned nowhere else, so this is its only chance to be seen — but `self`/`static`/
        // `parent` inside it mean that class, so the flag is what stops a caller resolving them
        // against the enclosing one and drawing a confidently wrong edge.
        $method = $this->method(<<<'PHP'
            <?php
            namespace App\Services;

            class Outer
            {
                public function run(): void
                {
                    Alpha::plain();

                    $handler = new class {
                        public function handle(): void
                        {
                            self::inside();
                        }
                    };
                }
            }
            PHP);

        $this->assertSame([['plain', false], ['inside', true]], $this->staticCalls($method));
    }

    #[Test]
    public function the_nested_flag_is_per_node_not_per_method(): void
    {
        // One method holds both shapes. Flagging the whole method would either invent nesting for the
        // ordinary call or drop it for the one that really is nested.
        $method = $this->method(<<<'PHP'
            <?php
            namespace App\Services;

            class Outer
            {
                public function run(): void
                {
                    $a = new class {
                        public function handle(): void
                        {
                            static::deep();
                        }
                    };

                    Alpha::shallow();
                }
            }
            PHP);

        $this->assertSame([['deep', true], ['shallow', false]], $this->staticCalls($method));
    }

    #[Test]
    public function a_parameter_default_and_an_attribute_are_walked_too(): void
    {
        // Params and attribute groups are not statements, so they are traversed separately from the
        // body. A match in a parameter default is as real a reference as one in the body, and dropping
        // either traversal would silently narrow every lane built on this helper.
        //
        // The predicate is `ClassConstFetch`, not `StaticCall`, deliberately: a parameter default must
        // be a constant expression, so a static CALL cannot legally appear in one and a test using it
        // would pass whether or not the params were walked at all.
        $method = $this->method(<<<'PHP'
            <?php
            namespace App\Services;

            class Outer
            {
                #[Marker(Gamma::TAG)]
                public function run(int $limit = Alpha::LIMIT): void
                {
                    $x = Beta::BODY;
                }
            }
            PHP);

        $found = AppFiles::nodesOwnedByWithNesting(
            $method,
            static fn (Node $node): bool => $node instanceof ClassConstFetch,
        );

        $names = [];

        foreach ($found as [$fetch, $nested]) {
            $this->assertInstanceOf(ClassConstFetch::class, $fetch);
            $this->assertFalse($nested);
            $names[] = AppFiles::resolveName($fetch->class instanceof Name ? $fetch->class : new Name('?'));
        }

        // Body first, then the param default, then the attribute — the order the edge sets downstream
        // are deduped first-wins against.
        $this->assertSame(['App\Services\Beta', 'App\Services\Alpha', 'App\Services\Gamma'], $names);
    }
}
