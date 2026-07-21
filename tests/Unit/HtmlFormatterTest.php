<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Analysis\EditorLink;
use SanderMuller\Richter\Analysis\HtmlFormatter;
use SanderMuller\Richter\Analysis\RadialLayout;
use SanderMuller\Richter\Analysis\RiskLevel;
use SanderMuller\Richter\Analysis\TestReferenceIndex;
use SanderMuller\Richter\Changes\ChangedFileSymbols;
use SanderMuller\Richter\Changes\MemberChange;
use SanderMuller\Richter\Tests\TestCase;

/**
 * @phpstan-import-type DetectChangesResult from HtmlFormatter
 * @phpstan-import-type GateVerdict from HtmlFormatter
 */
final class HtmlFormatterTest extends TestCase
{
    private const string ENTRY = 'route::GET::/annotated';

    /**
     * Every renderable field on at once, plus the graph payload plan 036 added.
     *
     * @param  list<array{source: string, target: string, via: string, depth: int}>|null  $edges
     * @param  array<string, array<string, true>>|null  $reach
     * @param  list<string>|null  $seeds
     * @return DetectChangesResult
     */
    private function fixture(?array $edges = null, ?array $reach = null, ?array $seeds = null, bool $withLocations = true): array
    {
        return [
            'changed' => ['app/Models/Post.php' => 3, 'app/Services/Lost.php' => 0],
            'coverage' => ['app/Models/Post.php' => 'analyzed', 'app/Services/Lost.php' => 'unresolved'],
            'entryPoints' => [self::ENTRY],
            'entryPointPaths' => [
                self::ENTRY => [
                    ['node' => self::ENTRY, 'via' => 'route-to-controller', 'file' => 'routes/web.php', 'line' => 9],
                    ['node' => 'App\Http\Controllers\AnnotatedController::show', 'via' => 'action-to-service'],
                    ['node' => 'App\Services\AnnotatedService::run', 'via' => ''],
                ],
            ],
            'entryPointLocations' => $withLocations ? [self::ENTRY => ['file' => 'routes/web.php', 'line' => 9]] : [],
            'entryPointSecurity' => [
                self::ENTRY => ['exposure' => 'public', 'riskLevel' => 'high', 'issues' => [
                    ['type' => 'PUBLIC_WRITE', 'severity' => 'high', 'message' => 'POST route with no auth middleware'],
                ]],
            ],
            'entryPointGates' => [self::ENTRY => ['beta-feature']],
            'seeds' => $seeds ?? ['App\Services\AnnotatedService::run'],
            'reach' => $reach ?? [
                self::ENTRY => ['route-to-controller' => true],
                'App\Http\Controllers\AnnotatedController::show' => ['action-to-service' => true],
                'App\Models\Comment' => ['model-relationship' => true],
            ],
            'edges' => $edges ?? [
                ['source' => 'App\Http\Controllers\AnnotatedController::show', 'target' => 'App\Services\AnnotatedService::run', 'via' => 'action-to-service', 'depth' => 1],
                ['source' => self::ENTRY, 'target' => 'App\Http\Controllers\AnnotatedController::show', 'via' => 'route-to-controller', 'depth' => 2],
                ['source' => 'App\Services\AnnotatedService::run', 'target' => 'App\Models\Comment', 'via' => 'model-relationship', 'depth' => 1],
            ],
            'impacted' => 2,
            'relatedModels' => ['App\Models\Comment'],
            'risk' => RiskLevel::Medium,
            'lowConfidence' => true,
            'coarseCapApplied' => true,
            'findings' => ['app/Exports/X.php: eager-load string matches no relation'],
        ];
    }

    /** @return list<ChangedFileSymbols> */
    private function changedFiles(): array
    {
        return [
            new ChangedFileSymbols('app/Models/Post.php', 'App\Models\Post', [
                new MemberChange('publish', MemberChange::KIND_METHOD, MemberChange::CHANGE_MODIFIED, resolvable: true),
                // The coarse-seed driver: a real change the graph cannot pin to a member node.
                new MemberChange('Draft', MemberChange::KIND_ENUM_CASE, MemberChange::CHANGE_MODIFIED, resolvable: false),
            ], cosmeticOnly: false),
            new ChangedFileSymbols('app/Services/Lost.php', 'App\Services\Lost', [], cosmeticOnly: true),
            new ChangedFileSymbols('resources/js/app.ts', '', [], cosmeticOnly: false, findings: ['unmatched Wayfinder import'], unresolvedFrontendReferences: true),
        ];
    }

    /** @param  GateVerdict|null  $gate */
    private function render(?TestReferenceIndex $tests = null, bool $gateActive = false, ?array $gate = null, ?EditorLink $editor = null): string
    {
        return HtmlFormatter::detectChanges($this->fixture(), $this->changedFiles(), 'origin/main', $tests, $gateActive, $gate, $editor);
    }

    #[Test]
    public function the_report_is_self_contained(): void
    {
        // The whole delivery premise: one file that opens offline. Any external reference breaks it.
        $html = $this->render();

        $this->assertStringNotContainsString('http://', $html);
        $this->assertStringNotContainsString('https://', $html);
        $this->assertStringNotContainsString('<link ', $html);
        $this->assertDoesNotMatchRegularExpression('/<script[^>]+src=/', $html);
        $this->assertStringStartsWith('<!DOCTYPE html>', $html);
    }

    #[Test]
    public function the_five_tabs_are_present(): void
    {
        $html = $this->render();

        foreach (['Overview', 'Graph', 'Paths', 'Changes', 'Advisory'] as $tab) {
            $this->assertStringContainsString(">{$tab}</button>", $html);
        }
    }

    #[Test]
    public function every_class_the_script_toggles_has_a_css_rule(): void
    {
        // JS and CSS ship in one document, so a class the script sets with no backing rule is a
        // silent dead behaviour — which is exactly how the tooltip's below-flip once shipped inert.
        $html = $this->render();

        foreach (['below', 'on', 'dim'] as $class) {
            $this->assertMatchesRegularExpression('/\.' . $class . '[\s,{:]/', $html, "No CSS rule backs the toggled '{$class}' class.");
        }
    }

    #[Test]
    public function the_tabs_carry_aria_tab_semantics(): void
    {
        // Without the roles the selected state is a CSS class only, so a screen reader announces
        // nothing when the panel changes.
        $html = $this->render();

        $this->assertStringContainsString('role="tablist"', $html);
        $this->assertStringContainsString('role="tab" data-tab="overview" aria-controls="overview" aria-selected="true"', $html);
        $this->assertStringContainsString('<section id="overview" role="tabpanel"', $html);
    }

    #[Test]
    public function the_stat_row_uses_richters_own_vocabulary(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('>Files<', $html);
        $this->assertStringContainsString('>Impacted<', $html);
        $this->assertStringContainsString('>Depth<', $html);
        $this->assertStringContainsString('>Risk<', $html);
        // The mock's tile has no richter equivalent — it must not appear at all.
        $this->assertStringNotContainsString('Functions', $html);
        $this->assertStringContainsString('>MEDIUM</strong>', $html);
        // Depth is the deepest hop in the merged walks.
        $this->assertStringContainsString('<strong>2</strong>', $html);
    }

    #[Test]
    public function a_path_chain_runs_target_first_and_labels_each_arrow_with_the_previous_hops_via(): void
    {
        $html = $this->render();

        // The entry point heads the chain, then one step per hop. The route-to-controller label
        // sits on the FIRST step, not the second: a hop's `via` is the edge to the NEXT hop.
        // Getting this wrong makes the panel read backwards.
        $this->assertStringContainsString(
            '<p class="path-entry"><code>GET /annotated</code>'
                . '<span class="loc">routes/web.php:9</span></p>'
                . '<ol class="hops" role="list">'
                . '<li><span class="via">route-to-controller</span><code>App\Http\Controllers\AnnotatedController::show</code></li>'
                . '<li><span class="via">action-to-service</span><code>App\Services\AnnotatedService::run</code></li>'
                . '</ol>',
            $html,
        );
    }

    #[Test]
    public function node_ids_render_in_their_human_form(): void
    {
        // `route::GET::/annotated` is an internal address; a reviewer reads "GET /annotated".
        $html = $this->render();

        $this->assertStringContainsString('<code>GET /annotated</code>', $html);
        $this->assertStringNotContainsString('<code>route::GET::/annotated</code>', $html);
        // The raw id stays reachable on the graph node, so it can still be acted on.
        $this->assertStringContainsString('data-raw="route::GET::/annotated"', $html);
    }

    #[Test]
    public function member_level_changes_render_per_file(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('<code>publish</code>', $html);
        $this->assertStringContainsString('<td>method</td>', $html);
        $this->assertStringContainsString('<td>modified</td>', $html);
        $this->assertStringContainsString('<td>enum_case</td>', $html);
        $this->assertStringContainsString('cosmetic only', $html);
        $this->assertStringContainsString('unresolved frontend references', $html);
        $this->assertStringContainsString('unmatched Wayfinder import', $html);
    }

    #[Test]
    public function an_unpinnable_member_is_named_as_the_low_confidence_driver(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('drives the coarse class-level seed', $html);
        $this->assertStringContainsString('coarse class-level estimate', $html);
        $this->assertStringContainsString('risk capped at MEDIUM', $html);
    }

    #[Test]
    public function an_unresolved_file_never_reads_as_no_impact(): void
    {
        $html = $this->render();

        // Both the per-file badge and the advisory note carry the standing wording.
        $this->assertStringContainsString('UNRESOLVED — not graphed, never "no impact"', $html);
        $this->assertStringContainsString('UNRESOLVED never means "no impact"', $html);
        $this->assertStringContainsString('may be incomplete', $html);
    }

    #[Test]
    public function the_gate_block_renders_only_when_a_gate_is_active(): void
    {
        $gate = ['failOn' => 'medium', 'failOnUnresolved' => true, 'tripped' => true, 'reasons' => ['risk medium is at or above medium']];

        $this->assertStringContainsString('TRIPPED', $this->render(gateActive: true, gate: $gate));
        $this->assertStringContainsString('risk medium is at or above medium', $this->render(gateActive: true, gate: $gate));
        $this->assertStringNotContainsString('TRIPPED', $this->render());
    }

    #[Test]
    public function the_empty_diff_case_renders_a_valid_document(): void
    {
        $html = HtmlFormatter::detectChanges([
            'changed' => [],
            'coverage' => [], 'entryPoints' => [], 'entryPointPaths' => [],
            'entryPointLocations' => [], 'entryPointSecurity' => [], 'entryPointGates' => [],
            'seeds' => [], 'reach' => [], 'edges' => [], 'impacted' => 0, 'relatedModels' => [],
            'risk' => RiskLevel::Low, 'lowConfidence' => false, 'coarseCapApplied' => false, 'findings' => [],
        ], [], 'origin/main');

        $this->assertStringContainsString('<strong>0</strong>', $html);
        $this->assertStringContainsString('>LOW</strong>', $html);
        $this->assertStringContainsString('Nothing reached — no graph to draw.', $html);
        $this->assertStringContainsString('No changed files.', $html);
        $this->assertStringContainsString('</html>', $html);
    }

    #[Test]
    public function untrusted_project_data_is_escaped_everywhere_it_is_interpolated(): void
    {
        // Every one of these is real project-derived input: diff paths, node ids carrying route
        // URIs, and messages carrying arbitrary source text.
        $hostileEntry = 'route::GET::/search?q=<script>alert(1)</script>';

        $html = HtmlFormatter::detectChanges([
            'changed' => ['app/Weird<name>&"x".php' => 1],
            'coverage' => ['app/Weird<name>&"x".php' => 'analyzed'],
            'entryPoints' => [$hostileEntry],
            'entryPointPaths' => [$hostileEntry => [
                ['node' => $hostileEntry, 'via' => 'route-to-controller'],
                ['node' => 'App\Odd<T>\Svc::run', 'via' => ''],
            ]],
            'entryPointLocations' => [$hostileEntry => ['file' => 'routes/<web>.php', 'line' => 3]],
            'entryPointSecurity' => [$hostileEntry => ['exposure' => 'public', 'riskLevel' => 'high', 'issues' => [
                ['type' => 'X', 'severity' => 'high', 'message' => 'quote " and <b>bold</b>'],
            ]]],
            'entryPointGates' => [],
            'seeds' => ['App\Odd<T>\Svc::run'],
            'reach' => [$hostileEntry => ['route-to-controller' => true]],
            'edges' => [['source' => $hostileEntry, 'target' => 'App\Odd<T>\Svc::run', 'via' => '<call>', 'depth' => 1]],
            'impacted' => 1,
            'relatedModels' => [],
            'risk' => RiskLevel::Low,
            'lowConfidence' => false,
            'coarseCapApplied' => false,
            'findings' => ['app/X.php: <b>bad</b> & worse'],
        ], [
            new ChangedFileSymbols('app/Weird<name>&"x".php', 'App\Weird<T>', [
                new MemberChange('run<T>', MemberChange::KIND_METHOD, MemberChange::CHANGE_MODIFIED, resolvable: true),
            ], cosmeticOnly: false),
        ], 'origin/<main>');

        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
        $this->assertStringNotContainsString('<b>bad</b>', $html);
        $this->assertStringNotContainsString('<b>bold</b>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('&amp;', $html);
        $this->assertStringContainsString('&quot;', $html);
        // The payload did not break the document: every tab marker survives.
        foreach (['Overview', 'Graph', 'Paths', 'Changes', 'Advisory'] as $tab) {
            $this->assertStringContainsString(">{$tab}</button>", $html);
        }
    }

    #[Test]
    public function the_svg_is_a_stable_snapshot(): void
    {
        $result = $this->fixture(
            edges: [['source' => 'A', 'target' => 'S', 'via' => 'call', 'depth' => 1]],
            reach: ['A' => ['call' => true]],
            seeds: ['S'],
            withLocations: false,
        );

        $html = HtmlFormatter::detectChanges($result, [], 'origin/main');

        $this->assertSame($html, HtmlFormatter::detectChanges($result, [], 'origin/main'));
        // Geometry, ring guides, classification and the tooltip payload, pinned exactly — the
        // payoff of laying out in PHP instead of running a force simulation in the browser.
        $this->assertStringContainsString(
            '<svg class="graph" viewBox="0 0 300 300" role="group" aria-label="Blast radius: 2 nodes arranged by depth from the change">'
                . '<circle class="ring" cx="150" cy="150" r="90"/>'
                . '<line x1="150" y1="60" x2="150" y2="150" data-from="A" data-to="S"/>'
                . '<circle class="n-impacted" cx="150" cy="60" r="6" tabindex="0" role="button" data-id="A" data-label="A"'
                . ' data-kind="Impacted" data-raw="" data-depth="1" data-loc="" aria-label="A, Impacted, depth 1"/>'
                . '<circle class="n-seed" cx="150" cy="150" r="6" tabindex="0" role="button" data-id="S" data-label="S"'
                . ' data-kind="Directly changed" data-raw="" data-depth="0" data-loc="" aria-label="S, Directly changed, depth 0"/>'
                . '</svg>',
            $html,
        );
    }

    #[Test]
    public function the_drawn_risk_bearing_node_count_equals_the_impacted_tile(): void
    {
        // The invariant the whole classification design exists for. The fixture's reach mixes a
        // relationship-only node (not counted) with two behavioural ones (counted).
        $html = $this->render();

        $this->assertSame(2, substr_count($html, 'class="n-impacted" cx'));
        $this->assertSame(1, substr_count($html, 'class="n-association" cx'));
        // ...and that drawn count is exactly what the tile claims.
        $this->assertStringContainsString('<span class="k">Impacted</span><strong>2</strong>', $html);
    }

    #[Test]
    public function an_under_cap_graph_prints_no_hidden_node_note(): void
    {
        $this->assertStringNotContainsString('hidden —', $this->render());
    }

    #[Test]
    public function an_over_cap_graph_prints_the_hidden_node_note(): void
    {
        $edges = [];
        $reach = [];

        for ($i = 0; $i < RadialLayout::MAX_NODES + 5; ++$i) {
            $edges[] = ['source' => 'S', 'target' => "n{$i}", 'via' => 'call', 'depth' => 1];
            $reach["n{$i}"] = ['call' => true];
        }

        $html = HtmlFormatter::detectChanges($this->fixture($edges, $reach, ['S']), $this->changedFiles(), 'origin/main');

        // Dropped EDGES are reported too: capping nodes silently strips their lines, which would
        // otherwise leave surviving nodes drawn as unconnected dots with no explanation.
        $this->assertMatchesRegularExpression('/\d+ node\(s\) and \d+ edge\(s\) hidden/', $html);
        $this->assertStringContainsString('the counts above are not', $html);
    }

    #[Test]
    public function a_security_issue_renders_its_type_severity_and_message(): void
    {
        // The exposure badge alone hides the finding: "public" is context, the PUBLIC_WRITE issue
        // is what a reviewer has to act on. Text and markdown both list these.
        $html = $this->render();

        $this->assertStringContainsString('PUBLIC_WRITE', $html);
        $this->assertStringContainsString('POST route with no auth middleware', $html);
    }

    #[Test]
    public function file_references_are_plain_text_without_a_configured_editor(): void
    {
        // The default: no editor means no links, so a shared CI artifact never points readers at
        // absolute paths they do not have.
        $html = $this->render();

        $this->assertStringContainsString('<span class="loc">routes/web.php:9</span>', $html);
        $this->assertStringNotContainsString('<a class="ref"', $html);
    }

    #[Test]
    public function file_references_become_editor_links_when_an_editor_is_configured(): void
    {
        $html = $this->render(editor: EditorLink::fromConfig('phpstorm', '/project'));

        // The overview entry point, the path hop, and the changed-file heading all link through.
        $this->assertStringContainsString(
            '<a class="ref" href="phpstorm://open?file=/project/routes/web.php&amp;line=9">'
                . '<span class="loc">routes/web.php:9</span></a>',
            $html,
        );
        $this->assertStringContainsString('href="phpstorm://open?file=/project/app/Models/Post.php&amp;line=1"', $html);
    }

    #[Test]
    public function a_security_issue_location_links_to_the_editor(): void
    {
        // Reassign the whole key rather than mutate a nested offset, so the result keeps its shape.
        $result = $this->fixture();
        $result['entryPointSecurity'] = [self::ENTRY => ['exposure' => 'public', 'riskLevel' => 'high', 'issues' => [
            ['type' => 'PUBLIC_WRITE', 'severity' => 'high', 'message' => 'POST route with no auth middleware', 'file' => 'app/Http/Controllers/AnnotatedController.php', 'line' => 31],
        ]]];

        $html = HtmlFormatter::detectChanges(
            $result, $this->changedFiles(), 'origin/main', null, false, null,
            EditorLink::fromConfig('vscode', '/project'),
        );

        $this->assertStringContainsString('href="vscode://file//project/app/Http/Controllers/AnnotatedController.php:31"', $html);
    }

    #[Test]
    public function a_diagram_that_disagrees_with_the_impacted_tile_says_so(): void
    {
        // The two are computed from the same reach map, so this should be unreachable — but a
        // silent contradiction between a picture and a number is exactly what this tool exists to
        // prevent, so the report states it rather than letting the reader pick.
        $result = $this->fixture();
        $result['impacted'] = 99;

        $html = HtmlFormatter::detectChanges($result, $this->changedFiles(), 'origin/main');

        $this->assertStringContainsString('classifies 2 impacted node(s) but the summary counts 99', $html);
        $this->assertStringContainsString('Trust the summary', $html);
    }

    #[Test]
    public function a_matching_diagram_and_tile_add_no_divergence_note(): void
    {
        $this->assertStringNotContainsString('Trust the summary', $this->render());
    }

    #[Test]
    public function association_only_nodes_render_on_the_outside_impact_ring(): void
    {
        $html = $this->render();

        $this->assertStringContainsString('n-association', $html);
        $this->assertStringContainsString('Outside impact (association only)', $html);
    }
}
