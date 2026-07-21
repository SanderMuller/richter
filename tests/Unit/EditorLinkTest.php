<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use Iterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Analysis\EditorLink;
use SanderMuller\Richter\Tests\TestCase;

final class EditorLinkTest extends TestCase
{
    private function url(string $editor, string $basePath, string $file, ?int $line): string
    {
        $link = EditorLink::fromConfig($editor, $basePath);
        $this->assertInstanceOf(EditorLink::class, $link);

        return $link->url($file, $line);
    }

    #[Test]
    public function no_configured_editor_yields_no_linker(): void
    {
        // The default: absent config means plain-text file references, never a broken link.
        $this->assertNotInstanceOf(EditorLink::class, EditorLink::fromConfig(null, '/app'));
        $this->assertNotInstanceOf(EditorLink::class, EditorLink::fromConfig('', '/app'));
        $this->assertNotInstanceOf(EditorLink::class, EditorLink::fromConfig('   ', '/app'));
    }

    #[Test]
    public function an_unknown_editor_yields_no_linker(): void
    {
        // Rather than emit a scheme no OS handler answers, an unrecognised name stays plain text.
        $this->assertNotInstanceOf(EditorLink::class, EditorLink::fromConfig('notepad', '/app'));
    }

    #[Test]
    public function the_editor_name_is_matched_case_insensitively(): void
    {
        $this->assertSame(
            'phpstorm://open?file=/app/routes/web.php&line=9',
            $this->url('PhpStorm', '/app', 'routes/web.php', 9),
        );
    }

    /** @return Iterator<string, array{string, string}> */
    public static function schemes(): Iterator
    {
        yield 'phpstorm' => ['phpstorm', 'phpstorm://open?file=/app/routes/web.php&line=9'];
        yield 'vscode' => ['vscode', 'vscode://file//app/routes/web.php:9'];
        yield 'vscodium' => ['vscodium', 'vscodium://file//app/routes/web.php:9'];
        yield 'sublime' => ['sublime', 'subl://open?url=file:///app/routes/web.php&line=9'];
        yield 'textmate' => ['textmate', 'txmt://open?url=file:///app/routes/web.php&line=9'];
        yield 'atom' => ['atom', 'atom://core/open/file?filename=/app/routes/web.php&line=9'];
        yield 'netbeans' => ['netbeans', 'netbeans://open/?f=/app/routes/web.php:9'];
        yield 'xdebug' => ['xdebug', 'xdebug:///app/routes/web.php@9'];
    }

    #[Test]
    #[DataProvider('schemes')]
    public function each_editor_builds_its_own_url_scheme(string $editor, string $expected): void
    {
        $this->assertSame($expected, $this->url($editor, '/app', 'routes/web.php', 9));
    }

    #[Test]
    public function a_missing_line_defaults_to_one(): void
    {
        // A changed-file heading has no line; opening at the top of the file is the sensible default.
        $this->assertSame(
            'phpstorm://open?file=/app/app/Models/Post.php&line=1',
            $this->url('phpstorm', '/app', 'app/Models/Post.php', null),
        );
    }

    #[Test]
    public function a_space_in_the_path_is_percent_encoded_but_slashes_are_kept(): void
    {
        // The URL must stay valid, and the slashes readable — every supported scheme accepts them.
        $this->assertSame(
            'phpstorm://open?file=/app/app/My%20Service.php&line=3',
            $this->url('phpstorm', '/app', 'app/My Service.php', 3),
        );
    }

    #[Test]
    public function a_windows_base_path_is_normalised_to_forward_slashes(): void
    {
        $this->assertSame(
            'phpstorm://open?file=C:/app/routes/web.php&line=9',
            $this->url('phpstorm', 'C:\\app', 'routes\\web.php', 9),
        );
    }
}
