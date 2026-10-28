<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Analysis\SentFields;
use SanderMuller\Richter\Tests\TestCase;

final class SentFieldsTest extends TestCase
{
    /** @return iterable<string, array{string, list<string>}> */
    public static function sources(): iterable
    {
        yield 'adjacent shorthand keys' => ['axios.post(url, { id, title, body })', ['body', 'id', 'title']];
        yield 'keyed literal' => ["fetch('/posts', { body: JSON.stringify({ title: t, 'sub_title': s }) })", ['body', 'sub_title', 'title']];
        yield 'form data' => ["data.append('title', t); params.set(\"body\", b)", ['body', 'title']];
        yield 'bracket write' => ["payload['title'] = t", ['title']];
        yield 'dotted write' => ['form.title = t', ['title']];
        yield 'comparison is not a write' => ['if (form.title == t) {}', []];
        // The documented over-match: an argument list takes the object-literal shape.
        yield 'argument list' => ['save(id, title, body)', ['title']];
        yield 'template interpolation' => ['<p>{{ title }}</p>', ['title']];
    }

    /** @param  list<string>  $expected */
    #[Test]
    #[DataProvider('sources')]
    public function it_lists_every_sent_field(string $source, array $expected): void
    {
        $this->assertSame($expected, SentFields::in($source));
    }

    #[Test]
    #[DataProvider('sources')]
    public function the_list_and_the_single_field_check_agree_both_ways(string $source): void
    {
        $listed = SentFields::in($source);

        foreach (['id', 'title', 'body', 'sub_title', 'save', 'url', 'form', 'payload', 'data', 't'] as $name) {
            $this->assertSame(
                SentFields::sends($source, $name),
                in_array($name, $listed, true),
                "'{$name}' in: {$source}",
            );
        }
    }

    /** @return iterable<string, array{string, bool}> */
    public static function opaqueSources(): iterable
    {
        yield 'object spread' => ['axios.post(url, { ...form, id })', true];
        yield 'leading spread after a key' => ['post(url, { id, ...rest })', true];
        yield 'form data from an element' => ['fetch(url, { body: new FormData(el) })', true];
        yield 'empty form data' => ["const d = new FormData(); d.append('title', t)", false];
        yield 'literal keys only' => ['axios.post(url, { id, title })', false];
    }

    #[Test]
    #[DataProvider('opaqueSources')]
    public function it_knows_when_a_source_can_send_unnamed_fields(string $source, bool $expected): void
    {
        $this->assertSame($expected, SentFields::opaque($source));
    }
}
