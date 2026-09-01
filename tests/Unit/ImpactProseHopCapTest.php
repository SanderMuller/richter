<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Analysis\ImpactFormatter;
use SanderMuller\Richter\Tests\TestCase;

final class ImpactProseHopCapTest extends TestCase
{
    /**
     * The caller/dependency hop sections are capped like every other breadth list: this prose is
     * also the MCP response's text block, and a hub symbol used to render thousands of hop lines
     * beside an already-capped entry-point list.
     */
    #[Test]
    public function the_caller_and_dependency_sections_cap_at_fifteen_hops_with_a_tail(): void
    {
        $callers = [];

        foreach (range(1, 20) as $i) {
            $callers[] = ['depth' => 1, 'node' => "App\\Caller{$i}", 'via' => 'static-call'];
        }

        $report = ImpactFormatter::impact([
            'target' => 'App\\Models\\Thing',
            'callers' => $callers,
            'dependencies' => [['depth' => 1, 'node' => 'App\\OnlyDependency', 'via' => 'action-to-service']],
            'entryPoints' => [],
            'associationEntryPoints' => [],
            'entryPointPaths' => [],
            'entryPointLocations' => [],
            'entryPointSecurity' => [],
            'entryPointGates' => [],
            'entryPointAuthGates' => [],
            'entryPointAuthMiddleware' => [],
        ]);

        $this->assertStringContainsString('App\\Caller15', $report);
        $this->assertStringNotContainsString('App\\Caller16', $report);
        $this->assertStringContainsString('… and 5 more', $report);

        // A section under the cap renders in full, with no tail of its own.
        $this->assertStringContainsString('App\\OnlyDependency', $report);
        $this->assertSame(1, substr_count($report, '… and 5 more'));
    }
}
