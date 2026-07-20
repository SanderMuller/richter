<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Analysis\MarkdownFormatter;
use SanderMuller\Richter\Analysis\RiskLevel;
use SanderMuller\Richter\Analysis\TestReferenceIndex;
use SanderMuller\Richter\Tests\TestCase;

final class MarkdownFormatterTest extends TestCase
{
    /**
     * @param  list<string>  $entryPoints
     * @param  array<string, 'analyzed'|'unresolved'>  $coverage
     * @param  list<string>  $relatedModels
     * @return array{changed: array<string, int>, coverage: array<string, 'analyzed'|'unresolved'>, entryPoints: list<string>, impacted: int, relatedModels: list<string>, risk: RiskLevel, lowConfidence: bool, coarseCapApplied: bool}
     */
    private function summary(
        array $entryPoints,
        array $coverage = ['app/Models/Post.php' => 'analyzed'],
        array $relatedModels = [],
        bool $lowConfidence = false,
        RiskLevel $risk = RiskLevel::Low,
        bool $coarseCapApplied = false,
    ): array {
        return [
            'changed' => array_map(static fn (): int => 1, $coverage),
            'coverage' => $coverage,
            'entryPoints' => $entryPoints,
            'impacted' => count($entryPoints),
            'relatedModels' => $relatedModels,
            'risk' => $risk,
            'lowConfidence' => $lowConfidence,
            'coarseCapApplied' => $coarseCapApplied,
        ];
    }

    #[Test]
    public function a_frontend_change_notes_that_risk_covers_backend_impact_only(): void
    {
        $output = MarkdownFormatter::detectChanges($this->summary(
            ['route::GET::/posts'],
            coverage: ['resources/js/Pages/Posts.vue' => 'analyzed'],
        ));

        $this->assertStringContainsString('> ℹ️ Frontend change: risk reflects backend impact only', $output);
    }

    #[Test]
    public function detect_changes_leads_with_a_risk_badge_and_the_advisory_note(): void
    {
        $output = MarkdownFormatter::detectChanges($this->summary(['route::GET::/posts'], risk: RiskLevel::High));

        $this->assertStringContainsString('## Richter change impact', $output);
        $this->assertStringContainsString('**Risk:** 🔴 HIGH _(advisory — not a gate)_', $output);
    }

    #[Test]
    public function detect_changes_drops_the_advisory_note_under_a_gate(): void
    {
        $output = MarkdownFormatter::detectChanges($this->summary([]), gateActive: true);

        $this->assertStringContainsString('**Risk:** 🟢 LOW', $output);
        $this->assertStringNotContainsString('advisory', $output);
    }

    #[Test]
    public function detect_changes_renders_the_changed_files_as_a_table_with_coverage(): void
    {
        $output = MarkdownFormatter::detectChanges($this->summary([], coverage: [
            'app/Models/Post.php' => 'analyzed',
            'app/Services/Lost.php' => 'unresolved',
        ]));

        $this->assertStringContainsString('| File | Graph nodes | Coverage |', $output);
        $this->assertStringContainsString('| `app/Models/Post.php` | 1 | analyzed |', $output);
        $this->assertStringContainsString('| `app/Services/Lost.php` | 1 | ⚠️ **UNRESOLVED**', $output);
        $this->assertStringContainsString('could not be placed in the graph', $output);
    }

    #[Test]
    public function a_path_containing_a_pipe_does_not_break_the_changed_files_table(): void
    {
        $output = MarkdownFormatter::detectChanges($this->summary([], coverage: [
            'app/weird|name.php' => 'analyzed',
            'app/back`tick.php' => 'analyzed',
            'app/double``tick.php' => 'analyzed',
        ]));

        $this->assertStringContainsString('| `app/weird\|name.php` | 1 | analyzed |', $output);
        $this->assertStringContainsString('| `` app/back`tick.php `` | 1 | analyzed |', $output);
        // Two consecutive backticks in the path would close a fixed ``-fence mid-filename; the
        // fence must outrun the longest run.
        $this->assertStringContainsString('| ``` app/double``tick.php ``` | 1 | analyzed |', $output);
    }

    #[Test]
    public function entry_points_render_as_an_unchecked_review_checklist_with_test_tags(): void
    {
        $tests = new TestReferenceIndex();
        $tests->addSource('<?php $this->get("/covered");');

        $output = MarkdownFormatter::detectChanges(
            $this->summary(['route::GET::/covered', 'route::GET::/uncovered']),
            $tests,
        );

        $this->assertStringContainsString('- [ ] `route::GET::/covered` — ✅ test-referenced', $output);
        $this->assertStringContainsString('- [ ] `route::GET::/uncovered` — ⚠️ no test references this', $output);
    }

    #[Test]
    public function a_reference_with_no_behavioural_assertion_renders_the_weaker_wording(): void
    {
        $tests = new TestReferenceIndex();
        $tests->addSource('<?php $this->get("/covered"); $response->assertOk();', 'tests/Feature/ShallowTest.php');

        $output = MarkdownFormatter::detectChanges($this->summary(['route::GET::/covered']), $tests);

        $this->assertStringContainsString('- [ ] `route::GET::/covered` — 🟡 test-referenced, no behavioural assertion found', $output);
    }

    #[Test]
    public function zero_entry_points_render_an_explicit_none_line(): void
    {
        $output = MarkdownFormatter::detectChanges($this->summary([]));

        $this->assertStringContainsString('### Entry points reached (0)', $output);
        $this->assertStringContainsString('_None reached from the changed code._', $output);
    }

    #[Test]
    public function entry_points_past_the_cap_collapse_into_a_details_block_instead_of_truncating(): void
    {
        $entryPoints = [];
        for ($i = 0; $i < 20; ++$i) {
            $entryPoints[] = sprintf('route::GET::/r%02d', $i);
        }

        $output = MarkdownFormatter::detectChanges($this->summary($entryPoints));

        $this->assertStringContainsString('<summary>… and 5 more</summary>', $output);
        // Unlike the text formatter, nothing is dropped — the overflow entries render inside <details>.
        $this->assertStringContainsString('route::GET::/r19', $output);
    }

    #[Test]
    public function checklist_entries_render_location_and_exposure_with_issue_sub_bullets(): void
    {
        $result = $this->summary(['route::POST::/checkout']) + [
            'entryPointLocations' => ['route::POST::/checkout' => ['file' => 'routes/web.php', 'line' => 21]],
            'entryPointSecurity' => ['route::POST::/checkout' => ['exposure' => 'public', 'riskLevel' => 'high', 'issues' => [
                ['type' => 'PUBLIC_WRITE', 'severity' => 'high', 'message' => 'POST route with no auth middleware', 'file' => 'app/Http/Controllers/CheckoutController.php', 'line' => 31],
            ]]],
        ];

        $output = MarkdownFormatter::detectChanges($result);

        $this->assertStringContainsString('- [ ] `route::POST::/checkout` — `routes/web.php:21` — 🔓 public', $output);
        $this->assertStringContainsString(
            '  - ⚠️ **PUBLIC_WRITE** (high): POST route with no auth middleware — `app/Http/Controllers/CheckoutController.php:31`',
            $output,
        );
    }

    #[Test]
    public function a_gated_route_renders_a_flag_badge(): void
    {
        $result = $this->summary(['route::POST::/checkout']) + [
            'entryPointGates' => ['route::POST::/checkout' => ['ai-coach']],
        ];

        $this->assertStringContainsString('- [ ] `route::POST::/checkout` — 🚩 ai-coach', MarkdownFormatter::detectChanges($result));
    }

    #[Test]
    public function an_unrecognised_exposure_renders_bare_instead_of_guessing_an_icon(): void
    {
        $result = $this->summary(['route::GET::/x']) + [
            'entryPointSecurity' => ['route::GET::/x' => ['exposure' => 'internal', 'riskLevel' => 'low', 'issues' => []]],
        ];

        $this->assertStringContainsString('- [ ] `route::GET::/x` — internal', MarkdownFormatter::detectChanges($result));
    }

    #[Test]
    public function impact_hops_render_their_location(): void
    {
        $output = MarkdownFormatter::impact([
            'target' => 'App\Models\User',
            'callers' => [['depth' => 1, 'node' => 'route::GET::/users', 'via' => 'route-to-controller', 'file' => 'routes/web.php', 'line' => 4]],
            'dependencies' => [],
        ]);

        $this->assertStringContainsString('- `route::GET::/users` _(via route-to-controller, depth 1)_ — `routes/web.php:4`', $output);
    }

    #[Test]
    public function explain_nests_the_call_chain_in_code_spans_under_its_entry_point(): void
    {
        $result = $this->summary(['route::GET::/posts']) + ['entryPointPaths' => [
            'route::GET::/posts' => [
                ['node' => 'route::GET::/posts', 'via' => 'route-to-controller'],
                ['node' => 'App\Services\PostPublisher::publish', 'via' => ''],
            ],
        ]];

        $output = MarkdownFormatter::detectChanges($result, explain: true);

        $this->assertStringContainsString('  - ↳ `route::GET::/posts` →(route-to-controller) `App\Services\PostPublisher::publish`', $output);
    }

    #[Test]
    public function no_call_chain_renders_without_explain(): void
    {
        $result = $this->summary(['route::GET::/posts']) + ['entryPointPaths' => [
            'route::GET::/posts' => [
                ['node' => 'route::GET::/posts', 'via' => 'route-to-controller'],
                ['node' => 'App\Services\PostPublisher::publish', 'via' => ''],
            ],
        ]];

        $this->assertStringNotContainsString('↳', MarkdownFormatter::detectChanges($result));
    }

    #[Test]
    public function related_models_collapse_into_a_details_block(): void
    {
        $output = MarkdownFormatter::detectChanges($this->summary([], relatedModels: ['App\Models\Post', 'App\Models\Review']));

        $this->assertStringContainsString('<summary>Related models (association reach — context, not risk): 2</summary>', $output);
        $this->assertStringContainsString('- `App\Models\Review`', $output);
        $this->assertStringContainsString('- `App\Models\Post`', $output);
    }

    #[Test]
    public function findings_render_as_a_warning_list(): void
    {
        $result = $this->summary([]) + ['findings' => ["app/Exports/X.php: eager-load string 'commentsreviews' matches no relation"]];

        $output = MarkdownFormatter::detectChanges($result);

        $this->assertStringContainsString('### Findings (in the changed source itself)', $output);
        $this->assertStringContainsString("- ⚠️ app/Exports/X.php: eager-load string 'commentsreviews' matches no relation", $output);
    }

    #[Test]
    public function a_low_confidence_result_renders_a_blockquote_note_with_the_cap_only_when_it_fired(): void
    {
        $capped = MarkdownFormatter::detectChanges(
            $this->summary(['route::GET::/r'], lowConfidence: true, risk: RiskLevel::Medium, coarseCapApplied: true),
        );
        $this->assertStringContainsString('> ⚠️ Low confidence', $capped);
        $this->assertStringContainsString('risk capped at MEDIUM', $capped);

        $notCapped = MarkdownFormatter::detectChanges(
            $this->summary(['route::GET::/r'], lowConfidence: true, risk: RiskLevel::High),
        );
        $this->assertStringContainsString('> ⚠️ Low confidence', $notCapped);
        $this->assertStringNotContainsString('capped at MEDIUM', $notCapped);
    }

    #[Test]
    public function a_console_command_entry_point_renders_without_its_signature(): void
    {
        $output = MarkdownFormatter::detectChanges($this->summary(['command::vector-store:cleanup {--days=7 : Days to keep}']));

        $this->assertStringContainsString('- [ ] `command::vector-store:cleanup`', $output);
        $this->assertStringNotContainsString('{--days', $output);
    }

    #[Test]
    public function impact_renders_callers_and_dependencies_with_hop_context(): void
    {
        $output = MarkdownFormatter::impact([
            'target' => 'App\Models\User',
            'callers' => [['depth' => 1, 'node' => 'route::GET::/users', 'via' => 'route-to-controller']],
            'dependencies' => [['depth' => 2, 'node' => 'App\Models\Team', 'via' => 'model-relationship']],
        ]);

        $this->assertStringContainsString('## Richter blast radius: `App\Models\User`', $output);
        $this->assertStringContainsString('### Callers (what breaks if you change it) (1)', $output);
        $this->assertStringContainsString('- `route::GET::/users` _(via route-to-controller, depth 1)_', $output);
        $this->assertStringContainsString('### Dependencies (what it reaches) (1)', $output);
        $this->assertStringContainsString('- `App\Models\Team` _(via model-relationship, depth 2)_', $output);
    }

    #[Test]
    public function impact_hops_past_the_cap_collapse_and_an_empty_side_renders_none(): void
    {
        $callers = array_map(
            static fn (int $i): array => ['depth' => 1, 'node' => sprintf('App\Callers\C%02d', $i), 'via' => 'references'],
            range(1, 16),
        );

        $output = MarkdownFormatter::impact(['target' => 'App\S', 'callers' => $callers, 'dependencies' => []]);

        $this->assertStringContainsString('<details>', $output);
        $this->assertStringContainsString('… and 1 more', $output);
        $this->assertStringContainsString('`App\Callers\C16`', $output);
        $this->assertStringContainsString('### Dependencies (what it reaches) (0)', $output);
        $this->assertStringContainsString('_(none)_', $output);
    }

    #[Test]
    public function impact_no_match_stays_honest_about_coverage(): void
    {
        $output = MarkdownFormatter::impact([
            'target' => 'Zzz\Nonexistent',
            'callers' => [],
            'dependencies' => [],
        ]);

        $this->assertStringContainsString('No graph nodes matched', $output);
        $this->assertStringContainsString('not proof of no impact', $output);
    }
}
