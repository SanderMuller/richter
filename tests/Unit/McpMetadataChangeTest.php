<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use Iterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Changes\ChangedSymbols;
use SanderMuller\Richter\Changes\MemberChange;
use SanderMuller\Richter\Tests\TestCase;

/**
 * A `laravel/mcp` attribute on a tool, resource or prompt is metadata the server reads when it lists
 * or calls the primitive. An edit to it changes what the server answers, and nothing else, so it pins
 * to the primitive's `handle()` instead of seeding the whole class at low confidence.
 */
final class McpMetadataChangeTest extends TestCase
{
    private const string HEADER = "<?php\nnamespace App\\Mcp\\Tools;\nuse Laravel\\Mcp\\Server\\Attributes\\Description;\nuse Laravel\\Mcp\\Server\\Attributes\\Name;\nuse Laravel\\Mcp\\Server\\Tool;\n";

    private const string HANDLE = "    public function handle(): string { return 'ok'; }\n";

    public static function metadataOnlyEdits(): Iterator
    {
        yield 'a description' => [
            self::HEADER . "#[Description('Before')]\nfinal class StatusTool extends Tool\n{\n" . self::HANDLE . "}\n",
            self::HEADER . "#[Description('After')]\nfinal class StatusTool extends Tool\n{\n" . self::HANDLE . "}\n",
        ];
        yield 'a name' => [
            self::HEADER . "#[Name('status')]\nfinal class StatusTool extends Tool\n{\n" . self::HANDLE . "}\n",
            self::HEADER . "#[Name('service-status')]\nfinal class StatusTool extends Tool\n{\n" . self::HANDLE . "}\n",
        ];
        yield 'an attribute added' => [
            self::HEADER . "final class StatusTool extends Tool\n{\n" . self::HANDLE . "}\n",
            self::HEADER . "#[Description('After')]\nfinal class StatusTool extends Tool\n{\n" . self::HANDLE . "}\n",
        ];
        yield 'an attribute over several lines' => [
            self::HEADER . "#[Description(\n    'Before',\n)]\nfinal class StatusTool extends Tool\n{\n" . self::HANDLE . "}\n",
            self::HEADER . "#[Description(\n    'After',\n)]\nfinal class StatusTool extends Tool\n{\n" . self::HANDLE . "}\n",
        ];
        yield 'an attribute on the declaration line' => [
            self::HEADER . "#[Description('Before')] final class StatusTool extends Tool\n{\n" . self::HANDLE . "}\n",
            self::HEADER . "#[Description('After')] final class StatusTool extends Tool\n{\n" . self::HANDLE . "}\n",
        ];
        yield 'a description and the handle() body' => [
            self::HEADER . "#[Description('Before')]\nfinal class StatusTool extends Tool\n{\n" . self::HANDLE . "}\n",
            self::HEADER . "#[Description('After')]\nfinal class StatusTool extends Tool\n{\n" . str_replace("'ok'", "'up'", self::HANDLE) . "}\n",
        ];
        yield 'a resource' => [
            str_replace('Tool;', 'Resource;', self::HEADER) . "#[Description('Before')]\nfinal class StatusResource extends Resource\n{\n" . self::HANDLE . "}\n",
            str_replace('Tool;', 'Resource;', self::HEADER) . "#[Description('After')]\nfinal class StatusResource extends Resource\n{\n" . self::HANDLE . "}\n",
        ];
    }

    #[Test]
    #[DataProvider('metadataOnlyEdits')]
    public function a_metadata_edit_on_a_primitive_pins_to_its_handle(string $base, string $head): void
    {
        $file = ChangedSymbols::classifyFile('app/Mcp/Tools/StatusTool.php', $head, $base, $this->hunk($base, $head));

        $this->assertFalse($file->needsCoarseSeed());
        $this->assertSame([['handle', MemberChange::KIND_METHOD, MemberChange::CHANGE_MODIFIED]], array_map(
            static fn (MemberChange $member): array => [$member->name, $member->kind, $member->change],
            $file->members,
        ));
    }

    public static function editsThatStayCoarse(): Iterator
    {
        yield 'a modifier changed on the same line' => [
            self::HEADER . "#[Description('Before')] final class StatusTool extends Tool\n{\n" . self::HANDLE . "}\n",
            self::HEADER . "#[Description('After')] class StatusTool extends Tool\n{\n" . self::HANDLE . "}\n",
        ];
        yield 'a trait use changed too' => [
            self::HEADER . "#[Description('Before')]\nfinal class StatusTool extends Tool\n{\n    use \\App\\Concerns\\First;\n" . self::HANDLE . "}\n",
            self::HEADER . "#[Description('After')]\nfinal class StatusTool extends Tool\n{\n    use \\App\\Concerns\\Second;\n" . self::HANDLE . "}\n",
        ];
        yield 'an inherited handle' => [
            self::HEADER . "#[Description('Before')]\nfinal class StatusTool extends Tool\n{\n}\n",
            self::HEADER . "#[Description('After')]\nfinal class StatusTool extends Tool\n{\n}\n",
        ];
        yield 'an app attribute with the same short name' => [
            str_replace('Laravel\\Mcp\\Server\\Attributes\\Description', 'App\\Attributes\\Description', self::HEADER) . "#[Description('Before')]\nfinal class StatusTool extends Tool\n{\n" . self::HANDLE . "}\n",
            str_replace('Laravel\\Mcp\\Server\\Attributes\\Description', 'App\\Attributes\\Description', self::HEADER) . "#[Description('After')]\nfinal class StatusTool extends Tool\n{\n" . self::HANDLE . "}\n",
        ];
        yield 'an MCP attribute grouped with another attribute' => [
            self::HEADER . "#[Description('Before'), \\Deprecated]\nfinal class StatusTool extends Tool\n{\n" . self::HANDLE . "}\n",
            self::HEADER . "#[Description('After'), \\Deprecated]\nfinal class StatusTool extends Tool\n{\n" . self::HANDLE . "}\n",
        ];
        yield 'a server' => [
            str_replace('Server\\Tool;', 'Server;', self::HEADER) . "#[Description('Before')]\nfinal class StatusServer extends Server\n{\n" . self::HANDLE . "}\n",
            str_replace('Server\\Tool;', 'Server;', self::HEADER) . "#[Description('After')]\nfinal class StatusServer extends Server\n{\n" . self::HANDLE . "}\n",
        ];
        yield 'a class that is no primitive' => [
            self::HEADER . "#[Description('Before')]\nfinal class StatusTool\n{\n" . self::HANDLE . "}\n",
            self::HEADER . "#[Description('After')]\nfinal class StatusTool\n{\n" . self::HANDLE . "}\n",
        ];
    }

    #[Test]
    #[DataProvider('editsThatStayCoarse')]
    public function any_other_class_level_edit_stays_coarse(string $base, string $head): void
    {
        $file = ChangedSymbols::classifyFile('app/Mcp/Tools/StatusTool.php', $head, $base, $this->hunk($base, $head));

        $this->assertTrue($file->needsCoarseSeed());
    }

    /**
     * The lines a unified diff marks, from a longest-common-subsequence alignment of the two sides.
     *
     * @return array{added: list<array{line: int, text: string}>, removed: list<array{line: int, text: string}>}
     */
    private function hunk(string $base, string $head): array
    {
        $old = explode("\n", $base);
        $new = explode("\n", $head);
        $lengths = array_fill(0, count($old) + 1, array_fill(0, count($new) + 1, 0));

        for ($i = count($old) - 1; $i >= 0; --$i) {
            for ($j = count($new) - 1; $j >= 0; --$j) {
                $lengths[$i][$j] = $old[$i] === $new[$j] ? $lengths[$i + 1][$j + 1] + 1 : max($lengths[$i + 1][$j], $lengths[$i][$j + 1]);
            }
        }

        $hunk = ['added' => [], 'removed' => []];

        for ($i = 0, $j = 0; $i < count($old) || $j < count($new);) {
            if ($i < count($old) && $j < count($new) && $old[$i] === $new[$j]) {
                ++$i;
                ++$j;
            } elseif ($j < count($new) && ($i >= count($old) || $lengths[$i][$j + 1] >= $lengths[$i + 1][$j])) {
                $hunk['added'][] = ['line' => $j + 1, 'text' => $new[$j++]];
            } else {
                $hunk['removed'][] = ['line' => $i + 1, 'text' => $old[$i++]];
            }
        }

        return $hunk;
    }
}
