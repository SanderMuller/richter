<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use Iterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Analysis\NodeLabel;
use SanderMuller\Richter\Tests\TestCase;

/**
 * The `command::` display trim. The multi-line case is the one this exists for: a space-only split
 * "succeeds" there and still emits a label carrying a newline, which wraps in every columnar
 * surface — the exact output the trim is meant to prevent.
 */
final class NodeLabelTest extends TestCase
{
    /** @return Iterator<string, array{string, string}> */
    public static function nodes(): Iterator
    {
        yield 'a single-line signature is trimmed to the name' => ['command::reports:sync {--force}', 'command::reports:sync'];
        yield 'a multi-line signature is trimmed to the name' => ["command::reports:sync\n    {--force : Skip the freshness check}", 'command::reports:sync'];
        yield 'a tab-separated signature is trimmed to the name' => ["command::reports:sync\t{--force}", 'command::reports:sync'];
        yield 'a signature-less command is unchanged' => ['command::reports:sync', 'command::reports:sync'];
        yield 'the prefix is kept, unlike the html label' => ['command::reports:sync {--force}', 'command::reports:sync'];

        // Everything that is not a command keeps its id verbatim — the text and markdown surfaces
        // print node ids as addresses a reader can feed back to `richter:trace`.
        yield 'a route id is untouched' => ['route::GET::/posts/{post}', 'route::GET::/posts/{post}'];
        yield 'a view id containing a space is untouched' => ['view::mail welcome', 'view::mail welcome'];
        yield 'an fqcn is untouched' => ['App\Services\PostPublisher::publish', 'App\Services\PostPublisher::publish'];

        // No name to show: the raw id beats an anonymous `command::` stub.
        yield 'a bare prefix keeps the raw id' => ['command::', 'command::'];
        yield 'a signature opening with a space keeps the raw id' => ['command:: {opt}', 'command:: {opt}'];
        yield 'a signature opening with a newline keeps the raw id' => ["command::\n{opt}", "command::\n{opt}"];
    }

    #[Test]
    #[DataProvider('nodes')]
    public function command_nodes_display_as_their_name(string $node, string $expected): void
    {
        $this->assertSame($expected, NodeLabel::display($node));
    }

    #[Test]
    public function a_trimmed_label_never_carries_whitespace(): void
    {
        // The property that matters to every columnar renderer, asserted directly rather than
        // through one sample: a label that shortened at all must be a single unbroken token.
        $node = "command::reports:sync\n    {--force : Skip the freshness check}\n    {--since=}";

        $this->assertDoesNotMatchRegularExpression('/\s/', NodeLabel::display($node));
    }
}
