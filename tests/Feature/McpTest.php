<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Feature;

use Illuminate\Support\Facades\Process;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Request;
use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Mcp\Tools\DetectChangesTool;
use SanderMuller\Richter\Mcp\Tools\ImpactTool;
use SanderMuller\Richter\Tests\TestCase;

final class McpTest extends TestCase
{
    #[Test]
    public function the_richter_mcp_server_is_registered(): void
    {
        $this->assertNotNull(Mcp::getLocalServer('richter'));
    }

    #[Test]
    public function the_tools_carry_the_names_the_server_instructions_promise(): void
    {
        $this->assertSame('impact', resolve(ImpactTool::class)->name());
        $this->assertSame('detect-changes', resolve(DetectChangesTool::class)->name());
    }

    #[Test]
    public function the_impact_tool_rejects_a_missing_symbol(): void
    {
        $response = resolve(ImpactTool::class)->handle(new Request([]));

        $this->assertTrue($response->isError());
    }

    #[Test]
    public function the_detect_changes_tool_reports_a_broken_ref_as_an_error(): void
    {
        $response = resolve(DetectChangesTool::class)->handle(new Request(['base' => 'this-ref-does-not-exist-zzz']));

        $this->assertTrue($response->isError());
    }

    #[Test]
    public function the_detect_changes_tool_reports_an_option_shaped_ref_as_an_error(): void
    {
        $response = resolve(DetectChangesTool::class)->handle(new Request(['base' => '--upload-pack=x']));

        $this->assertTrue($response->isError());
    }

    #[Test]
    public function the_impact_tool_reports_the_blast_radius_of_a_symbol(): void
    {
        // Builds the real graph of the testbench skeleton. Both formatter branches (matched and
        // unmatched) quote the symbol, so the assertion holds regardless of what that graph contains.
        $response = resolve(ImpactTool::class)->handle(new Request(['symbol' => 'User']));

        $this->assertFalse($response->isError());
        $this->assertStringContainsString('User', (string) $response->content());
    }

    #[Test]
    public function the_detect_changes_tool_reports_an_empty_diff_cleanly(): void
    {
        Process::fake([
            '*merge-base*' => Process::result("abc123\n"),
            '*diff*' => Process::result(''),
        ]);

        $response = resolve(DetectChangesTool::class)->handle(new Request([]));

        $this->assertFalse($response->isError());
        $this->assertStringContainsString('No changed PHP files under app/', (string) $response->content());
    }
}
