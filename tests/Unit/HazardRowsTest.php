<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Analysis\Hazard;
use SanderMuller\Richter\Analysis\HazardRows;
use SanderMuller\Richter\Analysis\Hazards\RouteFileHazards;
use SanderMuller\Richter\Analysis\HtmlFormatter;
use SanderMuller\Richter\Analysis\ImpactFormatter;
use SanderMuller\Richter\Analysis\JsonPresenter;
use SanderMuller\Richter\Analysis\MarkdownFormatter;
use SanderMuller\Richter\Analysis\RiskLevel;
use SanderMuller\Richter\Tests\Support\DetectChangesFixture;
use SanderMuller\Richter\Tests\TestCase;

final class HazardRowsTest extends TestCase
{
    #[Test]
    public function one_middleware_lost_by_a_group_is_one_row(): void
    {
        $rows = HazardRows::of($this->lost(['auth', 'verified'], ['verified']));

        $this->assertCount(1, $rows);
        $this->assertSame('3 routes', $rows[0]['subject']);
        $this->assertSame('the `auth` middleware is gone from these routes in routes/web.php', $rows[0]['evidence']);
        $this->assertSame([
            'GET posts/0 (App\Http\Controllers\PostController::a0)',
            'GET posts/1 (App\Http\Controllers\PostController::a1)',
            'GET posts/2 (App\Http\Controllers\PostController::a2)',
        ], $rows[0]['routes']);
    }

    #[Test]
    public function two_middleware_lost_by_a_group_are_two_rows(): void
    {
        $this->assertSame(['3 routes', '3 routes'], array_column(HazardRows::of($this->lost(['auth', 'verified'], [])), 'subject'));
    }

    #[Test]
    public function one_route_that_lost_its_middleware_reads_as_before(): void
    {
        $hazards = $this->lost(['auth'], [], routes: 1);

        $this->assertSame([[$hazards[0]->member, $hazards[0]->evidence, []]], array_map(
            static fn (array $row): array => [$row['subject'], $row['evidence'], $row['routes']],
            HazardRows::of($hazards),
        ));
    }

    #[Test]
    public function a_route_with_another_reach_keeps_its_own_row(): void
    {
        $hazards = $this->lost(['auth'], []);
        $hazards[1] = $hazards[1]->withReach(Hazard::REACH_PUBLIC_WRITE);

        $rows = HazardRows::of(array_values($hazards));

        $this->assertSame(['2 routes', 'App\Http\Controllers\PostController::a1'], array_column($rows, 'subject'));
        $this->assertSame('public-write', $rows[1]['hazard']->reachLabel());
    }

    #[Test]
    public function evidence_added_by_a_later_pass_does_not_split_a_group(): void
    {
        $hazards = array_map(static fn (Hazard $hazard): Hazard => $hazard->withEvidence($hazard->evidence . ' (more)'), $this->lost(['auth'], []));

        $this->assertCount(1, HazardRows::of($hazards));
    }

    #[Test]
    public function the_printed_reports_group_and_the_json_does_not(): void
    {
        $hazards = $this->lost(['auth'], [], routes: 40);
        $result = DetectChangesFixture::with(['risk' => RiskLevel::High, 'hazards' => $hazards]);

        $text = ImpactFormatter::detectChanges($result);
        $this->assertStringContainsString('Hazards (40)', $text);
        $this->assertStringContainsString('40 routes — the `auth` middleware is gone from these routes in routes/web.php', $text);
        $this->assertStringContainsString('more, all in --json', $text);

        // These two have room for every name.
        foreach ([MarkdownFormatter::detectChanges($result), HtmlFormatter::detectChanges($result, [], 'main')] as $report) {
            $this->assertStringContainsString('40 routes', $report);
            $this->assertStringContainsString('PostController::a39', $report);
            $this->assertSame(1, substr_count($report, 'middleware is gone'));
        }

        $this->assertCount(40, JsonPresenter::detectChanges($result, 'main')['hazards']);
    }

    /**
     * @param  list<string>  $before
     * @param  list<string>  $after
     * @return list<Hazard>
     */
    private function lost(array $before, array $after, int $routes = 3): array
    {
        $file = static function (array $middleware) use ($routes): string {
            $list = implode(', ', array_map(static fn (string $name): string => "'{$name}'", $middleware));
            $lines = implode("\n", array_map(static fn (int $n): string => "    Route::get('posts/{$n}', [PostController::class, 'a{$n}']);", range(0, $routes - 1)));

            return "<?php\nuse App\\Http\\Controllers\\PostController;\nuse Illuminate\\Support\\Facades\\Route;\nRoute::middleware([{$list}])->group(function () {\n{$lines}\n});\n";
        };

        return RouteFileHazards::for('routes/web.php', $file($after), $file($before))[0];
    }
}
