<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Analysis\Hazard;
use SanderMuller\Richter\Analysis\HtmlFormatter;
use SanderMuller\Richter\Analysis\ImpactFormatter;
use SanderMuller\Richter\Analysis\JsonPresenter;
use SanderMuller\Richter\Analysis\MarkdownFormatter;
use SanderMuller\Richter\Analysis\RiskLevel;
use SanderMuller\Richter\Analysis\TestReferenceIndex;
use SanderMuller\Richter\Changes\ChangedFileSymbols;
use SanderMuller\Richter\Changes\MemberChange;
use SanderMuller\Richter\Tests\TestCase;

/**
 * One rich `detectChanges`-shaped fixture, rendered through all three surfaces
 * ({@see ImpactFormatter}, {@see MarkdownFormatter}, {@see JsonPresenter}). Unlike each formatter's
 * own test file — which builds a minimal, divergent fixture per assertion — this fixture turns on
 * every renderable field at once, so a field one format forgets shows up here as a missing
 * substring rather than as a silent gap noticed only in production. These are presence assertions,
 * not golden strings: they catch "field dropped in one format", not styling drift.
 */
final class FormatterContractTest extends TestCase
{
    private const string ANNOTATED_ENTRY = 'route::GET::/annotated';

    /**
     * Base shape lifted from {@see JsonPresenterTest::detectChangesResult()}, extended with: an
     * over-the-cap entry-point list (20, so both formatters' cap/collapse branch fires), a
     * multi-hop explain chain, an entry-point location, a security issue, a Pennant gate, an
     * unresolved changed file, related models, source findings, and a coarse-capped low-confidence
     * risk — every field either formatter can render, on at once.
     *
     * @return array{seeds: list<string>, reach: array<string, array<string, true>>, edges: list<array{source: string, target: string, via: string, depth: int}>, changed: array<string, int>, coverage: array<string, 'analyzed'|'unresolved'>, entryPoints: list<string>, entryPointPaths: array<string, list<array{node: string, via: string, file?: string, line?: int}>>, entryPointLocations: array<string, array{file: string, line?: int}>, entryPointSecurity: array<string, array{exposure: string, riskLevel: string, issues: list<array{type: string, severity: string, message: string, file?: string, line?: int}>}>, entryPointGates: array<string, list<string>>, entryPointAuthMiddleware: array<string, list<string>>, impacted: int, associationEntryPoints: list<string>, associationEntryPointsVia: array<string, list<string>>, relatedModels: list<string>, traitAndOverrideReach: list<string>, traitAndOverrideReachVia: array<string, list<string>>, risk: RiskLevel, riskCause: string, hazards: list<Hazard>, verification: array<string, bool>, lowConfidence: bool, findings: list<string>}
     */
    private function richFixture(): array
    {
        // 1 annotated entry + 19 plain ones = 20, five over LIST_CAP (15). "annotated" sorts before
        // "r00".."r18" so it lands in the visible/shown portion, not the collapsed overflow.
        $entryPoints = [self::ANNOTATED_ENTRY];

        for ($i = 0; $i < 19; ++$i) {
            $entryPoints[] = sprintf('route::GET::/r%02d', $i);
        }

        return [
            'changed' => ['app/Models/Post.php' => 3, 'app/Services/Lost.php' => 1],
            'coverage' => ['app/Models/Post.php' => 'analyzed', 'app/Services/Lost.php' => 'unresolved'],
            'entryPoints' => $entryPoints,
            'entryPointPaths' => [
                self::ANNOTATED_ENTRY => [
                    ['node' => self::ANNOTATED_ENTRY, 'via' => 'route-to-controller', 'file' => 'routes/web.php', 'line' => 9],
                    ['node' => 'App\Http\Controllers\AnnotatedController::show', 'via' => 'action-to-service'],
                    ['node' => 'App\Services\AnnotatedService::run', 'via' => ''],
                ],
            ],
            'entryPointLocations' => [
                self::ANNOTATED_ENTRY => ['file' => 'routes/web.php', 'line' => 9],
            ],
            'entryPointSecurity' => [
                self::ANNOTATED_ENTRY => ['exposure' => 'public', 'riskLevel' => 'high', 'issues' => [
                    ['type' => 'PUBLIC_WRITE', 'severity' => 'high', 'message' => 'POST route with no auth middleware', 'file' => 'app/Http/Controllers/AnnotatedController.php', 'line' => 31],
                ]],
            ],
            'entryPointAuthMiddleware' => [self::ANNOTATED_ENTRY => ['Acme\\Http\\Middleware\\AuthenticateUser']],
            'entryPointGates' => [self::ANNOTATED_ENTRY => ['beta-feature']],
            // The graph payload plan 036 added: HTML-only, ignored by the other three surfaces.
            'seeds' => ['App\Services\AnnotatedService::run'],
            'reach' => [
                self::ANNOTATED_ENTRY => ['route-to-controller' => true],
                'App\Models\Comment' => ['model-relationship' => true],
            ],
            'edges' => [
                ['source' => self::ANNOTATED_ENTRY, 'target' => 'App\Services\AnnotatedService::run', 'via' => 'route-to-controller', 'depth' => 1],
                ['source' => 'App\Services\AnnotatedService::run', 'target' => 'App\Models\Comment', 'via' => 'model-relationship', 'depth' => 1],
            ],
            'impacted' => 42,
            // Both an inline (named-relation) and a folded (registry fan-out) surface, so the section's
            // two branches are part of the every-field contract rather than only the dedicated test.
            'associationEntryPoints' => ['App\Filament\Resources\CommentResource', 'App\Filament\Pages\SettingsPage'],
            'associationEntryPointsVia' => [
                'App\Filament\Resources\CommentResource' => ['model-relationship'],
                'App\Filament\Pages\SettingsPage' => ['config-registry-fanout'],
            ],
            'relatedModels' => ['App\Models\Comment'],
            // Both inheritance lanes, and both group shapes. Until this landed the section was in no
            // contract test at all: the text and markdown wording was unpinned, and only the HTML card
            // had a presence check. One trait user stays inline; four overrides fold onto two member
            // names, one group of three and one of one.
            'traitAndOverrideReach' => [
                'App\Http\Resources\PostResource::toArray',
                'App\Jobs\ArchivePost::handle',
                'App\Jobs\GenerateReport::handle',
                'App\Jobs\SendDigest::handle',
                'App\Models\Post',
            ],
            'traitAndOverrideReachVia' => [
                'App\Http\Resources\PostResource::toArray' => ['override'],
                'App\Jobs\ArchivePost::handle' => ['override'],
                'App\Jobs\GenerateReport::handle' => ['override'],
                'App\Jobs\SendDigest::handle' => ['override'],
                'App\Models\Post' => ['uses-trait'],
            ],
            'risk' => RiskLevel::Medium,
            'lowConfidence' => true,
            'riskCause' => 'tier 3 `auth` hazard on App\\Http\\Controllers\\PostController::update, reach public-write',
            'hazards' => [new Hazard('auth', 3, 'CWE-862', 'App\Http\Controllers\PostController::update', 'the authorization check `ability:update` is gone from the body', ['ability:update'], Hazard::REACH_PUBLIC_WRITE)],
            'verification' => ['route::GET::/r01' => true, 'route::GET::/r02' => false],
            'findings' => ["app/Exports/X.php: eager-load string 'commentsreviews' matches no relation"],
        ];
    }

    /** A test index that references the annotated entry's URI directly — exercises the
     *  test-referenced tag both formatters render. */
    private function richTestIndex(): TestReferenceIndex
    {
        $tests = new TestReferenceIndex();
        $tests->addSource('<?php $this->get("/annotated");');
        // A second, shallow-only reference (with a file, so it is gradable) exercises the
        // assertion-weak sub-tag on one of the plain entry points.
        $tests->addSource('<?php $this->get("/r01"); $response->assertOk();', 'tests/Feature/ShallowR01Test.php');

        return $tests;
    }

    #[Test]
    public function the_text_formatter_renders_every_populated_field(): void
    {
        $output = ImpactFormatter::detectChanges($this->richFixture(), $this->richTestIndex(), explain: true);

        $this->assertStringContainsString(self::ANNOTATED_ENTRY, $output);
        $this->assertStringContainsString('routes/web.php:9', $output);
        $this->assertStringContainsString('[public]', $output);
        $this->assertStringContainsString('[gated: beta-feature]', $output);
        $this->assertStringContainsString('test-referenced', $output);
        $this->assertStringContainsString('route::GET::/r01  [test-referenced — no behavioural assertion found]', $output);
        $this->assertStringContainsString('PUBLIC_WRITE (high): POST route with no auth middleware', $output);
        // Beside the finding, never instead of it: the subclassed auth middleware Brain's name match missed.
        $this->assertStringContainsString('Acme\\Http\\Middleware\\AuthenticateUser is applied to this route and extends a framework authentication middleware', $output);
        $this->assertStringContainsString('app/Http/Controllers/AnnotatedController.php:31', $output);
        $this->assertStringContainsString('App\Http\Controllers\AnnotatedController::show', $output);
        $this->assertStringContainsString('App\Services\AnnotatedService::run', $output);
        $this->assertStringContainsString('App\Models\Comment', $output);
        $this->assertStringContainsString("eager-load string 'commentsreviews' matches no relation", $output);
        $this->assertStringContainsString('UNRESOLVED', $output);
        $this->assertStringContainsString('… and 5 more', $output);
        $this->assertStringContainsStringIgnoringCase('low confidence', $output);
        // The hazard section, above Findings: a hazard says something may BREAK.
        $this->assertStringContainsString('Hazards (1)', $output);
        $this->assertStringContainsString('public-write', $output);
        // Every level renders with its cause.
        $this->assertStringContainsString('reach public-write', $output);
        // This fixture's hazard was graded from the walk's own chains, so nothing may claim otherwise:
        // an annotation applied to every hazard would still satisfy the substring assertions above.
        $this->assertStringNotContainsString('via its class', $output);
    }

    #[Test]
    public function the_markdown_formatter_renders_every_populated_field(): void
    {
        $output = MarkdownFormatter::detectChanges($this->richFixture(), $this->richTestIndex(), explain: true);

        $this->assertStringContainsString(self::ANNOTATED_ENTRY, $output);
        $this->assertStringContainsString('routes/web.php:9', $output);
        $this->assertStringContainsString('🔓 public', $output);
        $this->assertStringContainsString('🚩 beta-feature', $output);
        $this->assertStringContainsString('test-referenced', $output);
        $this->assertStringContainsString('`route::GET::/r01` — 🟡 test-referenced, no behavioural assertion found', $output);
        $this->assertStringContainsString('PUBLIC_WRITE** (high): POST route with no auth middleware', $output);
        $this->assertStringContainsString('`Acme\\Http\\Middleware\\AuthenticateUser` is applied to this route and extends a framework authentication middleware', $output);
        $this->assertStringContainsString('app/Http/Controllers/AnnotatedController.php:31', $output);
        $this->assertStringContainsString('App\Http\Controllers\AnnotatedController::show', $output);
        $this->assertStringContainsString('App\Services\AnnotatedService::run', $output);
        $this->assertStringContainsString('App\Models\Comment', $output);
        $this->assertStringContainsString("eager-load string 'commentsreviews' matches no relation", $output);
        $this->assertStringContainsString('UNRESOLVED', $output);
        $this->assertStringContainsString('… and 5 more', $output);
        $this->assertStringContainsStringIgnoringCase('low confidence', $output);
        // The hazard section, above Findings: a hazard says something may BREAK.
        $this->assertStringContainsString('Hazards (1)', $output);
        $this->assertStringContainsString('public-write', $output);
        // Every level renders with its cause.
        $this->assertStringContainsString('reach public-write', $output);
        // This fixture's hazard was graded from the walk's own chains, so nothing may claim otherwise:
        // an annotation applied to every hazard would still satisfy the substring assertions above.
        $this->assertStringNotContainsString('via its class', $output);
    }

    #[Test]
    public function the_declaring_class_provenance_renders_in_prose_and_stays_out_of_the_payload(): void
    {
        // A hazard graded from its declaring class is the one shape whose verdict the diff's own
        // counts cannot corroborate, so all three prose surfaces say where the grade came from. The
        // JSON `reach` must NOT: a consumer matches on the four states, and folding a sentence into
        // the value it matches would break every such consumer.
        $fixture = $this->richFixture();
        $fixture['hazards'] = [
            new Hazard('model', 2, 'CWE-915', 'App\Models\Comment::$fillable', '$fillable gained `role`', [], Hazard::REACH_NO_GUARD_FOUND, null, true),
        ];

        $suffix = 'no-guard-found (via its class)';

        $this->assertStringContainsString($suffix, ImpactFormatter::detectChanges($fixture, $this->richTestIndex(), explain: true));
        $this->assertStringContainsString($suffix, MarkdownFormatter::detectChanges($fixture, $this->richTestIndex(), explain: true));
        $this->assertStringContainsString($suffix, HtmlFormatter::detectChanges($fixture, [], 'origin/main', $this->richTestIndex()));

        $json = JsonPresenter::detectChanges($fixture, 'origin/main', $this->richTestIndex());

        $this->assertSame(Hazard::REACH_NO_GUARD_FOUND, $json['hazards'][0]['reach']);
    }

    #[Test]
    public function every_format_renders_both_branches_of_the_association_fold(): void
    {
        // The section splits per surface: a named relation stays inline, a registry fan-out folds under
        // one shared cause. Each format writes that summary in its own words, and the HTML one had no
        // test at all — a wording change there was invisible to the suite.
        $fixture = $this->richFixture();
        $inline = 'App\Filament\Resources\CommentResource';
        $folded = 'App\Filament\Pages\SettingsPage';

        $text = ImpactFormatter::detectChanges($fixture, $this->richTestIndex(), explain: true);
        $markdown = MarkdownFormatter::detectChanges($fixture, $this->richTestIndex(), explain: true);
        $html = HtmlFormatter::detectChanges($fixture, [], 'origin/main', $this->richTestIndex());

        // Every format names the surface that stayed inline, and every format states the fold's count.
        foreach (['text' => $text, 'markdown' => $markdown, 'html' => $html] as $format => $output) {
            $this->assertStringContainsString($inline, $output, "{$format} lost the inline surface");
            $this->assertStringContainsString('1 surface reached only through a registry lookup', $output, "{$format} lost the fold summary");
        }

        // The FOLDED names are where the formats legitimately differ. Markdown and HTML truncate
        // nothing — the fold is a `<details>`, one click away. The text report caps its lists by design
        // ({@see ImpactFormatter::summarisedList()}), so there the count IS the whole record, and
        // asserting otherwise would demand a completeness that format never promised.
        $this->assertStringContainsString($folded, $markdown, 'markdown must keep the folded surface');
        $this->assertStringContainsString($folded, $html, 'html must keep the folded surface');
        $this->assertStringNotContainsString($folded, $text, 'the text report caps rather than folds');

        // The payload keeps the raw reasons; the fold is a rendering decision, not a payload one.
        $json = JsonPresenter::detectChanges($fixture, 'origin/main', $this->richTestIndex());
        $this->assertSame(['config-registry-fanout'], $json['associationEntryPointsVia'][$folded]);
    }

    #[Test]
    public function every_format_splits_the_inheritance_section_into_its_two_lanes(): void
    {
        // A trait user has the changed method copied into it, so the changed bytes run there. An override
        // declares its own version, so it does not. The section printed both as one flat list, which
        // spent the reader's attention as though they were the same fact.
        $fixture = $this->richFixture();
        $text = ImpactFormatter::detectChanges($fixture, $this->richTestIndex(), explain: true);
        $markdown = MarkdownFormatter::detectChanges($fixture, $this->richTestIndex(), explain: true);
        $html = HtmlFormatter::detectChanges($fixture, [], 'origin/main', $this->richTestIndex());

        foreach (['text' => $text, 'markdown' => $markdown, 'html' => $html] as $format => $output) {
            // The trait user stays named and keeps its reason; the overrides are counted both ways —
            // how many entries, across how many member names — so the fold can never read as shorter
            // than the reach it found.
            $this->assertStringContainsString('App\Models\Post', $output, "{$format} lost the trait user");
            $this->assertStringContainsString('uses-trait', $output, "{$format} lost the inline reason");
            $this->assertStringContainsString('4 overrides across 2 member names', $output, "{$format} lost the fold counts");

            // Grouped by member name, with every class kept. The class names are the only thing that
            // identifies the entry, and the fold — not truncation — is what solves the length problem.
            $this->assertStringContainsString('handle', $output, "{$format} lost the multi-class group");
            $this->assertStringContainsString('toArray', $output, "{$format} lost the single-class group");

            foreach (['App\Jobs\ArchivePost', 'App\Jobs\GenerateReport', 'App\Jobs\SendDigest', 'App\Http\Resources\PostResource'] as $class) {
                $this->assertStringContainsString($class, $output, "{$format} dropped the grouped class {$class}");
            }

            // The claim is exactly what the `override` edge proves and no more. `overrideEdges()` draws
            // it from a method the class declares itself; that the changed BODY does not run there is
            // usually true as well but has a traced exception, so no format may say it.
            $this->assertStringContainsString('each declares the member itself', $output, "{$format} lost the fold's claim");
            $this->assertStringNotContainsString('does not run', $output, "{$format} states a claim with a known exception");
        }

        // Markdown and HTML fold; the text report has no fold and states the counts on a plain line.
        $this->assertStringContainsString('<summary>4 overrides across 2 member names', $markdown);
        $this->assertStringContainsString('<details><summary>4 overrides across 2 member names', $html);
        $this->assertStringNotContainsString('<details>', $text);

        // A rendering decision, not a payload one: both keys keep every entry, ungrouped.
        $json = JsonPresenter::detectChanges($fixture, 'origin/main', $this->richTestIndex());
        $this->assertCount(5, $json['traitAndOverrideReach']);
        $this->assertSame(['override'], $json['traitAndOverrideReachVia']['App\Jobs\ArchivePost::handle']);
        $this->assertSame(['uses-trait'], $json['traitAndOverrideReachVia']['App\Models\Post']);
    }

    #[Test]
    public function a_nested_markdown_list_keeps_the_two_spaces_github_needs_to_nest_it(): void
    {
        // GFM nests a list item under a `- ` parent only from TWO spaces. At one space the child becomes
        // a SIBLING, checked against GitHub's own renderer, which silently flattens a member group back
        // into the flat list the grouping replaced — the group headings then read as if they were members.
        // Nothing pinned this: the indent was asserted only incidentally by a test about singular
        // wording, so narrowing it would have kept the suite green. Same shape of gap as the blank lines
        // below, and the same fix.
        $fixture = [...$this->richFixture(), 'traitAndOverrideReach' => [
            'App\Jobs\ArchivePost::handle',
            'App\Jobs\GenerateReport::handle',
        ], 'traitAndOverrideReachVia' => [
            'App\Jobs\ArchivePost::handle' => ['override'],
            'App\Jobs\GenerateReport::handle' => ['override'],
        ]];

        $markdown = MarkdownFormatter::detectChanges($fixture, $this->richTestIndex(), explain: true);
        $lines = explode("\n", $markdown);
        $nested = 0;

        foreach ($lines as $line) {
            if (preg_match('/^( +)- /', $line, $m) !== 1) {
                continue;
            }

            ++$nested;
            $this->assertSame(
                0,
                strlen($m[1]) % 2,
                "a nested list item must be indented in twos for GFM to nest it, got {$m[1]} on: {$line}",
            );
            $this->assertGreaterThanOrEqual(2, strlen($m[1]), "one space makes this a sibling, not a child: {$line}");
        }

        // Assert the fixture actually renders a nested list, or this passes while covering nothing — the
        // single-class shape has no sub-list at all, which is why this fixture uses a group of two.
        $this->assertGreaterThan(0, $nested, 'the fixture should render at least one nested list item');
        $this->assertStringContainsString("- `handle` — 2 classes\n  - `App\Jobs\ArchivePost`\n  - `App\Jobs\GenerateReport`", $markdown);
    }

    #[Test]
    public function the_inheritance_section_prints_only_the_lane_it_has(): void
    {
        // Two single-lane cases, both normal in the wild: an application whose inheritance reach is all
        // overrides prints no inline list, and one with only trait users prints no fold. Neither may
        // leave an empty `<details>`, and — the reason "N more" was dropped everywhere — neither may
        // imply a list that is not above it.
        $overridesOnly = [...$this->richFixture(), 'traitAndOverrideReach' => ['App\Jobs\Archive::handle'], 'traitAndOverrideReachVia' => ['App\Jobs\Archive::handle' => ['override']]];
        $traitsOnly = [...$this->richFixture(), 'traitAndOverrideReach' => ['App\Models\Post'], 'traitAndOverrideReachVia' => ['App\Models\Post' => ['uses-trait']]];

        $markdown = MarkdownFormatter::detectChanges($overridesOnly, $this->richTestIndex(), explain: true);
        $this->assertStringContainsString('1 override across 1 member name', $markdown);
        $this->assertStringNotContainsString('more', substr($markdown, (int) strpos($markdown, 'Related by inheritance'), 200));
        // Singular throughout, and the fold still carries a body — an all-folded section is the normal
        // case on a real application, not the edge case, so this is the branch that has to read right.
        // A lone class sits on the member's line: no "1 class" count and no sub-list of one.
        $this->assertStringContainsString('- `handle` — `App\Jobs\Archive`', $markdown);
        $this->assertStringNotContainsString('1 class', $markdown);
        $this->assertStringNotContainsString('overrides across', $markdown);

        // Every format, not only the two that fold: markdown with no override lane must not open a
        // `<details>` at all, because `collapsed()` would happily write "0 overrides across 0 member
        // names" over an empty body.
        foreach ([
            'html' => HtmlFormatter::detectChanges($traitsOnly, [], 'origin/main', $this->richTestIndex()),
            'text' => ImpactFormatter::detectChanges($traitsOnly, $this->richTestIndex(), explain: true),
            'markdown' => MarkdownFormatter::detectChanges($traitsOnly, $this->richTestIndex(), explain: true),
        ] as $format => $output) {
            $this->assertStringContainsString('App\Models\Post', $output, "{$format} lost the trait user");
            $this->assertStringNotContainsString('declares the member itself', $output, "{$format} rendered an empty override lane");
        }
    }

    #[Test]
    public function every_collapsed_block_keeps_the_blank_lines_github_needs(): void
    {
        // GitHub does not parse markdown inside a `<details>` unless a blank line follows
        // `</summary>` — without it a bullet list renders as literal text, checked against GitHub's own
        // renderer. `collapsed()` writes those lines and five sections share it — the association
        // fan-out, the inheritance override lane, related models, the entry-point checklist overflow and
        // the hop-list overflow — so nothing pinned them, and tidying that helper would break every fold
        // at once with the suite still green. This fixture drives the first four; the hop-list overflow
        // belongs to the impact report.
        //
        // The inheritance fold is the one whose body is NESTED (a member group, then its classes), so it
        // is also the one a blank-line regression would disfigure most.
        $output = MarkdownFormatter::detectChanges($this->richFixture(), $this->richTestIndex(), explain: true);
        $lines = explode("\n", $output);

        $folds = 0;

        foreach ($lines as $i => $line) {
            if (! str_starts_with($line, '<summary>')) {
                continue;
            }

            ++$folds;
            $this->assertSame('', $lines[$i + 1] ?? null, "fold {$folds}: no blank line after </summary>");
        }

        // The whole point is that several folds share the helper — assert the fixture actually exercises
        // more than one, or this test could pass while covering nothing.
        $this->assertGreaterThan(1, $folds, 'the fixture should render more than one fold');

        foreach ($lines as $i => $line) {
            if ($line === '</details>') {
                $this->assertSame('', $lines[$i - 1] ?? null, 'no blank line before </details>');
            }
        }
    }

    #[Test]
    public function the_json_presenter_carries_every_documented_key(): void
    {
        $json = JsonPresenter::detectChanges($this->richFixture(), 'origin/main', $this->richTestIndex());

        foreach ([
            'base', 'changed', 'coverage', 'entryPoints', 'entryPointPaths', 'entryPointLocations',
            'entryPointSecurity', 'entryPointGates', 'entryPointTestReferences', 'impacted',
            'associationEntryPoints', 'associationEntryPointsVia',
            'relatedModels', 'risk', 'riskCause', 'hazards', 'verification', 'lowConfidence', 'findings', 'unresolved',
        ] as $key) {
            $this->assertArrayHasKey($key, $json);
        }

        // The annotated entry's reference has no file to grade (fileless) — plain "referenced".
        // The r01 entry carries only a shallow status-check assertion — the weak sub-state.
        $this->assertSame('referenced', $json['entryPointTestReferences'][self::ANNOTATED_ENTRY]);
        $this->assertSame('referenced-no-behavioural-assertion', $json['entryPointTestReferences']['route::GET::/r01']);
        $this->assertSame('unreferenced', $json['entryPointTestReferences']['route::GET::/r02']);

        // Key presence alone is tautological — JsonPresenter hard-codes every key in one array
        // literal — so assert the populated VALUES survived the mapping, mirroring what the text
        // and markdown tests prove through substrings.
        $this->assertSame(self::ANNOTATED_ENTRY, array_key_first($json['entryPointPaths']));
        $this->assertCount(3, $json['entryPointPaths'][self::ANNOTATED_ENTRY]);
        $this->assertSame(['file' => 'routes/web.php', 'line' => 9], $json['entryPointLocations'][self::ANNOTATED_ENTRY]);
        $this->assertSame('public', $json['entryPointSecurity'][self::ANNOTATED_ENTRY]['exposure']);
        $this->assertSame('PUBLIC_WRITE', $json['entryPointSecurity'][self::ANNOTATED_ENTRY]['issues'][0]['type']);
        $this->assertSame(['beta-feature'], $json['entryPointGates'][self::ANNOTATED_ENTRY]);
        $this->assertSame(['App\Models\Comment'], $json['relatedModels']);
        $this->assertSame(['app/Models/Post.php' => 3, 'app/Services/Lost.php' => 1], $json['changed']);
        $this->assertSame('unresolved', $json['coverage']['app/Services/Lost.php']);
        $this->assertSame(["app/Exports/X.php: eager-load string 'commentsreviews' matches no relation"], $json['findings']);
        $this->assertSame(42, $json['impacted']);
        $this->assertSame('origin/main', $json['base']);
        $this->assertTrue($json['unresolved']);
        $this->assertTrue($json['lowConfidence']);
        // Every level carries its cause into the machine contract too: a consumer surfacing `risk`
        // alone would otherwise reproduce the bare render the model exists to prevent.
        $this->assertNotSame('', $json['riskCause']);
        $this->assertSame('medium', $json['risk']);
        $this->assertCount(20, $json['entryPoints']);
    }

    /**
     * The note contradicts a security finding, so the reason it gives must not depend on which
     * formatter rendered it. It also has to stay true to Brain: since Laravel Brain 2.5.0 the miss
     * is not "matches by name" but "walks an `extends` chain that terminates on `Authenticate`",
     * and three copies of one sentence drift apart silently.
     */
    #[Test]
    public function every_formatter_gives_the_same_reason_the_auth_middleware_was_missed(): void
    {
        $outputs = [
            'text' => ImpactFormatter::detectChanges($this->richFixture(), $this->richTestIndex(), explain: true),
            'markdown' => MarkdownFormatter::detectChanges($this->richFixture(), $this->richTestIndex(), explain: true),
            'html' => HtmlFormatter::detectChanges($this->richFixture(), [], 'origin/main', $this->richTestIndex()),
        ];

        $reasons = [];

        foreach ($outputs as $format => $output) {
            $this->assertStringContainsString('extends a framework authentication middleware', $output, "{$format}: the evidence");
            // The sentence this replaced was WRONG, not merely older: Brain has walked an `extends`
            // chain since 2.5.0. A formatter left on it contradicts the other two.
            $this->assertStringNotContainsString('by name, not by ancestry', $output, "{$format}: the pre-2.5.0 reason");

            $reasons[$format] = $this->normalisedReason($output, $format);
        }

        // Fragment assertions would pass on three explanations that contradict each other, so the
        // whole sentence is compared — stripped of the markup each formatter wraps it in.
        $this->assertSame(
            ['Brain walks an extends chain to Authenticate only, so a descendant of another auth middleware still reads public'],
            array_values(array_unique($reasons)),
            'the formatters disagree on why Brain missed the middleware: ' . json_encode($reasons, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * The reason sentence with each formatter's own wrapping removed: HTML tags, markdown backticks,
     * and the brackets the two text formats parenthesise it with.
     */
    private function normalisedReason(string $output, string $format): string
    {
        $matched = preg_match('/(Brain walks an .{0,400}?reads public)/s', $output, $matches) === 1;
        $this->assertTrue($matched, "{$format}: no reason sentence rendered at all");

        return trim(str_replace(['`', '<code>', '</code>'], '', $matches[1]));
    }

    #[Test]
    public function the_html_formatter_renders_every_populated_field(): void
    {
        $html = HtmlFormatter::detectChanges(
            $this->richFixture(),
            // Both files from the fixture's `changed` map: the Changes tab is driven by the
            // member-level list, so a file missing from it would simply not render.
            [
                new ChangedFileSymbols('app/Models/Post.php', 'App\Models\Post', [
                    new MemberChange('publish', MemberChange::KIND_METHOD, MemberChange::CHANGE_MODIFIED, resolvable: true),
                ], cosmeticOnly: false),
                new ChangedFileSymbols('app/Services/Lost.php', 'App\Services\Lost', [
                    new MemberChange('run', MemberChange::KIND_METHOD, MemberChange::CHANGE_MODIFIED, resolvable: true),
                ], cosmeticOnly: false),
            ],
            'origin/main',
            $this->richTestIndex(),
        );

        foreach ([
            'app/Models/Post.php',
            'app/Services/Lost.php',
            'UNRESOLVED',
            'GET /annotated',
            'routes/web.php:9',
            'public',
            // The auth-middleware contradiction, escaped like every other project-derived value.
            'extends a framework authentication middleware',
            'PUBLIC_WRITE',
            'POST route with no auth middleware',
            'beta-feature',
            'test-referenced',
            'coarse class-level estimate',
            'Hazards (1)',
            'public-write',
            'eager-load string &#039;commentsreviews&#039; matches no relation',
            'MEDIUM',
            '<strong>42</strong>',
            'publish',
            'n-association',
        ] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }
    }
}
