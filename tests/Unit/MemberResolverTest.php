<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Changes\MemberChange;
use SanderMuller\Richter\Changes\MemberResolver;
use SanderMuller\Richter\Tests\TestCase;

final class MemberResolverTest extends TestCase
{
    #[Test]
    public function it_resolves_methods_with_their_line_spans_and_marks_them_resolvable(): void
    {
        $source = <<<'PHP'
        <?php
        class Foo
        {
            public function bar(): int
            {
                return 1;
            }
        }
        PHP;

        $resolved = MemberResolver::resolve($source);

        $bar = $this->member($resolved['members'], 'bar');
        $this->assertSame(MemberChange::KIND_METHOD, $bar['kind']);
        $this->assertTrue($bar['resolvable']);
        $this->assertSame(4, $bar['start']);
        $this->assertSame(7, $bar['end']);
        $this->assertSame([['start' => 2, 'end' => 8]], $resolved['classRanges']);
    }

    #[Test]
    public function it_marks_constants_resolvable_but_properties_not(): void
    {
        // Constants get a member node via ConstantReferenceTracer, so they pin precisely; a property
        // (e.g. $fillable) has no member node and still collapses to the class node (coarse).
        $source = <<<'PHP'
        <?php
        class Foo
        {
            public const BAR = 1;
            protected array $fillable = ['a'];
        }
        PHP;

        $resolved = MemberResolver::resolve($source);

        $this->assertTrue($this->member($resolved['members'], 'BAR')['resolvable']);
        $this->assertFalse($this->member($resolved['members'], 'fillable')['resolvable']);
    }

    #[Test]
    public function it_marks_enum_cases_resolvable(): void
    {
        $source = <<<'PHP'
        <?php
        enum Status
        {
            case Draft;
            case Shipped;
        }
        PHP;

        $resolved = MemberResolver::resolve($source);

        $this->assertTrue($this->member($resolved['members'], 'Draft')['resolvable']);
    }

    #[Test]
    public function it_keeps_trait_constants_coarse(): void
    {
        // A trait constant is copied into each using class, not inherited, so ConstantReferenceTracer
        // skips it — it stays non-resolvable (coarse) so a change reaches the using classes via the
        // coarse seed rather than reading UNRESOLVED.
        $source = <<<'PHP'
        <?php
        trait HasStatus { public const STATUS_ACTIVE = 1; }
        PHP;

        $resolved = MemberResolver::resolve($source);

        $this->assertFalse($this->member($resolved['members'], 'STATUS_ACTIVE')['resolvable']);
    }

    #[Test]
    public function it_includes_leading_attribute_lines_in_a_member_span(): void
    {
        $source = <<<'PHP'
        <?php
        class Foo
        {
            #[Deprecated]
            public function bar(): void
            {
            }
        }
        PHP;

        // The attribute is on line 4; the member span must start there so a changed
        // attribute line maps to its method.
        $this->assertSame(4, $this->member(MemberResolver::resolve($source)['members'], 'bar')['start']);
    }

    #[Test]
    public function it_includes_a_leading_doc_comment_in_a_member_span(): void
    {
        $source = <<<'PHP'
        <?php
        class Foo
        {
            /**
             * Returns the answer.
             */
            public function bar(): int
            {
                return 42;
            }
        }
        PHP;

        // The docblock opens on line 4; the member span must start there so a new method added
        // together with its docblock reads as one additive member, not a class-level change.
        $this->assertSame(4, $this->member(MemberResolver::resolve($source)['members'], 'bar')['start']);
    }

    #[Test]
    public function it_gives_each_constant_in_a_group_its_own_line_span(): void
    {
        $source = <<<'PHP'
        <?php
        class Companies
        {
            const
                FOO = 'foo',
                BAR = 'bar';
        }
        PHP;

        // A multi-constant declaration must not give every constant the whole statement's span —
        // otherwise touching one marks them all. FOO (the first item) absorbs the `const` keyword
        // on line 4; BAR starts and ends on its own line 6.
        $members = MemberResolver::resolve($source)['members'];
        $this->assertSame(4, $this->member($members, 'FOO')['start']);
        $this->assertSame(6, $this->member($members, 'BAR')['start']);
        $this->assertSame(6, $this->member($members, 'BAR')['end']);
    }

    /**
     * @param  list<array{name: string, kind: string, resolvable: bool, start: int, end: int}>  $members
     * @return array{name: string, kind: string, resolvable: bool, start: int, end: int}
     */
    private function member(array $members, string $name): array
    {
        foreach ($members as $member) {
            if ($member['name'] === $name) {
                return $member;
            }
        }

        self::fail("member {$name} not resolved");
    }
}
