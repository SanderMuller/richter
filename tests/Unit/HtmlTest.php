<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use Iterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Analysis\Html;
use SanderMuller\Richter\Tests\TestCase;

final class HtmlTest extends TestCase
{
    /** @return Iterator<string, array{string, string}> */
    public static function nodeIds(): Iterator
    {
        yield 'route shows method and uri' => ['route::GET::/posts/{post}', 'GET /posts/{post}'];
        yield 'route keeps a multibyte uri intact' => ["route::GET::/caf\u{00e9}", "GET /caf\u{00e9}"];
        yield 'route with no uri shows the method' => ['route::GET', 'GET'];
        yield 'command drops its signature' => ['command::richter:impact {symbol}', 'richter:impact'];
        yield 'view drops the prefix' => ['view::mail.welcome', 'mail.welcome'];
        yield 'a view name containing a space is not truncated' => ['view::mail welcome', 'mail welcome'];
        yield 'model drops the prefix' => ['model::App\Models\Post', 'App\Models\Post'];
        yield 'schedule drops the prefix' => ['schedule::nightly-report', 'nightly-report'];
        yield 'an fqcn is left alone' => ['App\Services\PostPublisher::publish', 'App\Services\PostPublisher::publish'];
        // A partial id must render as itself: an empty label is the anonymous dot this avoids.
        yield 'a bare route prefix keeps the raw id' => ['route::', 'route::'];
        yield 'a bare view prefix keeps the raw id' => ['view::', 'view::'];
        yield 'a bare model prefix keeps the raw id' => ['model::', 'model::'];
        yield 'a bare command prefix keeps the raw id' => ['command::', 'command::'];
        yield 'a command that is only a signature keeps the raw id' => ['command:: {opt}', 'command:: {opt}'];
    }

    #[Test]
    #[DataProvider('nodeIds')]
    public function node_ids_render_in_their_human_form(string $node, string $expected): void
    {
        $this->assertSame($expected, Html::nodeLabel($node));
    }

    #[Test]
    public function escaping_covers_quotes_and_invalid_sequences(): void
    {
        $this->assertSame('&lt;b&gt; &amp; &quot;x&quot; &#039;y&#039;', Html::e('<b> & "x" \'y\''));
        // ENT_SUBSTITUTE: malformed UTF-8 becomes a replacement char rather than an empty string,
        // which would silently drop a whole path from the report.
        $this->assertNotSame('', Html::e("bad\xB1sequence"));
    }

    #[Test]
    public function a_location_renders_with_and_without_a_line(): void
    {
        $this->assertSame('routes/web.php:12', Html::locationText(['file' => 'routes/web.php', 'line' => 12]));
        $this->assertSame('routes/web.php', Html::locationText(['file' => 'routes/web.php']));
        $this->assertSame('', Html::locationText(null));
    }
}
