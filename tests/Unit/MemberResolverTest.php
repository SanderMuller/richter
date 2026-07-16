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
    public function it_marks_enum_cases_constants_and_properties_non_resolvable(): void
    {
        $source = <<<'PHP'
        <?php
        class Foo
        {
            public const BAR = 1;
            protected array $fillable = ['a'];
        }
        PHP;

        $resolved = MemberResolver::resolve($source);

        $this->assertFalse($this->member($resolved['members'], 'BAR')['resolvable']);
        $this->assertFalse($this->member($resolved['members'], 'fillable')['resolvable']);
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
