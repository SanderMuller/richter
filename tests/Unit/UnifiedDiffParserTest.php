<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Changes\UnifiedDiffParser;
use SanderMuller\Richter\Tests\TestCase;

final class UnifiedDiffParserTest extends TestCase
{
    #[Test]
    public function it_parses_a_single_line_modification_with_correct_line_numbers(): void
    {
        $diff = <<<'DIFF'
        diff --git a/app/Foo.php b/app/Foo.php
        index 1111111..2222222 100644
        --- a/app/Foo.php
        +++ b/app/Foo.php
        @@ -6 +6 @@ class Foo
        -        return 0;
        +        return 1;
        DIFF;

        $parsed = UnifiedDiffParser::parse($diff);

        $this->assertSame([
            'added' => [['line' => 6, 'text' => '        return 1;']],
            'removed' => [['line' => 6, 'text' => '        return 0;']],
            'oldPath' => 'app/Foo.php',
        ], $parsed['app/Foo.php']);
    }

    #[Test]
    public function it_records_the_old_path_of_a_renamed_file(): void
    {
        $diff = <<<'DIFF'
        diff --git a/app/Old.php b/app/New.php
        similarity index 90%
        rename from app/Old.php
        rename to app/New.php
        --- a/app/Old.php
        +++ b/app/New.php
        @@ -3 +3 @@
        -    return 1;
        +    return 2;
        DIFF;

        $parsed = UnifiedDiffParser::parse($diff);

        $this->assertArrayHasKey('app/New.php', $parsed);
        $this->assertSame('app/Old.php', $parsed['app/New.php']['oldPath']);
    }

    #[Test]
    public function it_parses_a_multi_line_addition_block(): void
    {
        $diff = <<<'DIFF'
        diff --git a/app/Bar.php b/app/Bar.php
        --- a/app/Bar.php
        +++ b/app/Bar.php
        @@ -8,0 +9,3 @@ class Bar
        +    public function baz(): int
        +    {
        +    }
        DIFF;

        $parsed = UnifiedDiffParser::parse($diff);

        $this->assertSame([9, 10, 11], array_column($parsed['app/Bar.php']['added'], 'line'));
        $this->assertSame([], $parsed['app/Bar.php']['removed']);
    }

    #[Test]
    public function it_keeps_each_files_lines_separate_in_a_multi_file_diff(): void
    {
        $diff = <<<'DIFF'
        diff --git a/app/A.php b/app/A.php
        --- a/app/A.php
        +++ b/app/A.php
        @@ -1 +1 @@
        -old A
        +new A
        diff --git a/app/B.php b/app/B.php
        --- a/app/B.php
        +++ b/app/B.php
        @@ -5 +5 @@
        -old B
        +new B
        DIFF;

        $parsed = UnifiedDiffParser::parse($diff);

        $this->assertSame([['line' => 1, 'text' => 'new A']], $parsed['app/A.php']['added']);
        $this->assertSame([['line' => 5, 'text' => 'new B']], $parsed['app/B.php']['added']);
    }

    #[Test]
    public function it_ignores_the_no_newline_at_eof_marker(): void
    {
        $diff = <<<'DIFF'
        diff --git a/app/A.php b/app/A.php
        --- a/app/A.php
        +++ b/app/A.php
        @@ -1 +1 @@
        -old
        \ No newline at end of file
        +new
        \ No newline at end of file
        DIFF;

        $parsed = UnifiedDiffParser::parse($diff);

        $this->assertSame([['line' => 1, 'text' => 'new']], $parsed['app/A.php']['added']);
        $this->assertSame([['line' => 1, 'text' => 'old']], $parsed['app/A.php']['removed']);
    }

    #[Test]
    public function it_keys_a_deletion_on_the_old_path(): void
    {
        $diff = <<<'DIFF'
        diff --git a/app/Gone.php b/app/Gone.php
        deleted file mode 100644
        --- a/app/Gone.php
        +++ /dev/null
        @@ -1,2 +0,0 @@
        -<?php
        -class Gone {}
        DIFF;

        $parsed = UnifiedDiffParser::parse($diff);

        $this->assertArrayHasKey('app/Gone.php', $parsed);
        $this->assertSame([1, 2], array_column($parsed['app/Gone.php']['removed'], 'line'));
    }

    #[Test]
    public function it_parses_a_crlf_diff_with_clean_paths(): void
    {
        // A heredoc cannot embed `\r`, so convert a LF fixture to CRLF line endings.
        $unix = <<<'DIFF'
        diff --git a/app/Foo.php b/app/Foo.php
        --- a/app/Foo.php
        +++ b/app/Foo.php
        @@ -6 +6 @@ class Foo
        -        return 0;
        +        return 1;
        DIFF;

        $parsed = UnifiedDiffParser::parse(str_replace("\n", "\r\n", $unix . "\n"));

        $this->assertSame(['app/Foo.php'], array_keys($parsed));
        $this->assertSame([['line' => 6, 'text' => "        return 1;\r"]], $parsed['app/Foo.php']['added']);
        $this->assertSame([['line' => 6, 'text' => "        return 0;\r"]], $parsed['app/Foo.php']['removed']);
    }

    #[Test]
    public function it_ignores_a_binary_file_diff(): void
    {
        $diff = <<<'DIFF'
        diff --git a/public/logo.png b/public/logo.png
        index 1111111..2222222 100644
        Binary files a/public/logo.png and b/public/logo.png differ
        DIFF;

        $this->assertSame([], UnifiedDiffParser::parse($diff));
    }

    #[Test]
    public function it_ignores_a_mode_only_change(): void
    {
        $diff = <<<'DIFF'
        diff --git a/app/Script.php b/app/Script.php
        old mode 100644
        new mode 100755
        DIFF;

        $this->assertSame([], UnifiedDiffParser::parse($diff));
    }

    #[Test]
    public function it_treats_an_added_line_starting_with_plus_plus_as_content_inside_a_hunk(): void
    {
        $diff = <<<'DIFF'
        diff --git a/app/Counter.php b/app/Counter.php
        --- a/app/Counter.php
        +++ b/app/Counter.php
        @@ -10,0 +11 @@ class Counter
        +++ $i;
        DIFF;

        $parsed = UnifiedDiffParser::parse($diff);

        $this->assertSame(['app/Counter.php'], array_keys($parsed));
        $this->assertSame([['line' => 11, 'text' => '++ $i;']], $parsed['app/Counter.php']['added']);
        $this->assertSame([], $parsed['app/Counter.php']['removed']);
    }

    #[Test]
    public function it_treats_a_removed_line_starting_with_dash_dash_as_content_inside_a_hunk(): void
    {
        $diff = <<<'DIFF'
        diff --git a/app/Query.php b/app/Query.php
        --- a/app/Query.php
        +++ b/app/Query.php
        @@ -5 +4,0 @@ class Query
        --- DROP TABLE archive
        DIFF;

        $parsed = UnifiedDiffParser::parse($diff);

        $this->assertSame([['line' => 5, 'text' => '-- DROP TABLE archive']], $parsed['app/Query.php']['removed']);
        $this->assertSame([], $parsed['app/Query.php']['added']);
    }
}
