<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Analysis\ImpactFormatter;
use SanderMuller\Richter\Analysis\JsonPresenter;
use SanderMuller\Richter\Analysis\MarkdownFormatter;
use SanderMuller\Richter\Analysis\SymbolLocator;
use SanderMuller\Richter\Graph\CodeGraph;
use SanderMuller\Richter\Tests\TestCase;

final class SymbolLocatorTest extends TestCase
{
    /**
     * One small graph carrying every shape the locator has to label or refuse to label: a prefixed
     * entity node, a member of a namespaced class, a global-namespace member, a bare unnamespaced
     * id, and a node with no recorded location at all.
     */
    private function graph(): CodeGraph
    {
        return new CodeGraph([
            ['source' => 'route::GET::/posts', 'target' => 'model::App\Models\Post', 'type' => 'route-to-controller'],
            ['source' => 'model::App\Models\Post', 'target' => 'App\Models\Post::publish', 'type' => 'call'],
            ['source' => 'App\Models\Post::publish', 'target' => 'A::m', 'type' => 'call'],
            ['source' => 'A::m', 'target' => 'web', 'type' => 'call'],
            ['source' => 'web', 'target' => 'App\Services\Unlocated', 'type' => 'call'],
        ], hasUnparseableFiles: false, nodeMetadata: [
            'route::GET::/posts' => ['file' => 'routes/web.php', 'line' => 3],
            'model::App\Models\Post' => ['file' => 'app/Models/Post.php', 'line' => 12],
            // No line: locationOf() is sparse, and so is the match entry built from it.
            'App\Models\Post::publish' => ['file' => 'app/Models/Post.php'],
            'A::m' => ['file' => 'app/A.php', 'line' => 7],
            'web' => ['file' => 'app/Http/Kernel.php', 'line' => 1],
        ]);
    }

    #[Test]
    public function a_symbol_lookup_returns_every_matching_node_with_its_location(): void
    {
        $result = new SymbolLocator($this->graph())->locateSymbol('Post');

        $this->assertSame('Post', $result['query']);
        $this->assertSame('symbol', $result['by']);
        $this->assertSame(2, $result['total']);
        $this->assertFalse($result['bounded']);
        $this->assertArrayNotHasKey('limit', $result);
        $this->assertSame([
            ['node' => 'App\Models\Post::publish', 'kind' => 'member', 'file' => 'app/Models/Post.php'],
            ['node' => 'model::App\Models\Post', 'kind' => 'model', 'file' => 'app/Models/Post.php', 'line' => 12],
        ], $result['matches']);
    }

    #[Test]
    public function a_leading_separator_is_dropped_before_the_lookup(): void
    {
        // An FQCN pasted from a `use` statement or a docblock carries one; every existing
        // resolution site drops it, and a locator that did not would miss its own graph.
        $result = new SymbolLocator($this->graph())->locateSymbol('\App\Models\Post');

        $this->assertSame(2, $result['total']);
    }

    #[Test]
    public function matches_are_sorted_before_the_cap_so_the_visible_page_is_build_order_independent(): void
    {
        $edges = [
            ['source' => 'App\Z\Post', 'target' => 'App\A\Post', 'type' => 'call'],
            ['source' => 'App\A\Post', 'target' => 'App\M\Post', 'type' => 'call'],
        ];

        $forward = new SymbolLocator(new CodeGraph($edges, hasUnparseableFiles: false))->locateSymbol('Post', 2);
        $reversed = new SymbolLocator(new CodeGraph(array_reverse($edges), hasUnparseableFiles: false))->locateSymbol('Post', 2);

        $this->assertSame(['App\A\Post', 'App\M\Post'], array_column($forward['matches'], 'node'));
        $this->assertSame($forward, $reversed);
    }

    #[Test]
    public function a_cap_reports_the_uncapped_total_and_flags_itself(): void
    {
        $result = new SymbolLocator($this->graph())->locateSymbol('Post', 1);

        $this->assertSame(2, $result['total']);
        $this->assertArrayHasKey('limit', $result);
        $this->assertSame(1, $result['limit']);
        $this->assertTrue($result['bounded']);
        $this->assertCount(1, $result['matches']);
    }

    #[Test]
    public function a_symbol_miss_carries_the_nearest_nodes_rather_than_an_empty_list(): void
    {
        $result = new SymbolLocator($this->graph())->locateSymbol('App\Models\Pots');

        $this->assertSame(0, $result['total']);
        $this->assertSame([], $result['matches']);
        $this->assertArrayHasKey('suggestions', $result);
        $this->assertNotSame([], $result['suggestions']);
        // Exactly one of the two leads, never both.
        $this->assertArrayNotHasKey('graphNodeCount', $result);
    }

    #[Test]
    public function a_symbol_miss_with_nothing_resembling_it_reports_the_scanned_count_instead(): void
    {
        $result = new SymbolLocator($this->graph())->locateSymbol('Zzz');

        $this->assertSame([], $result['matches']);
        $this->assertArrayNotHasKey('suggestions', $result);
        $this->assertArrayHasKey('graphNodeCount', $result);
        $this->assertSame(6, $result['graphNodeCount']);
    }

    #[Test]
    public function an_empty_graph_reports_a_zero_scanned_count_rather_than_an_error(): void
    {
        $result = new SymbolLocator(new CodeGraph([], hasUnparseableFiles: false))->locateSymbol('Post');

        $this->assertSame(0, $result['total']);
        $this->assertArrayHasKey('graphNodeCount', $result);
        $this->assertSame(0, $result['graphNodeCount']);
    }

    #[Test]
    public function a_kind_is_reported_only_when_the_node_id_proves_it(): void
    {
        $matches = new SymbolLocator($this->graph())->locateSymbol('m')['matches'];

        // `A::m` is a global-namespace class member. The tempting rule — "a backslash-free head is a
        // vocabulary prefix" — would label it kind `A`; richter says nothing instead.
        $this->assertSame([['node' => 'A::m', 'file' => 'app/A.php', 'line' => 7]], $matches);
    }

    #[Test]
    public function a_bare_unnamespaced_id_is_not_labelled_a_class(): void
    {
        // NodeNormalizer keeps Brain's id verbatim for routes, middleware and short names it could
        // not resolve, so `web` may be a middleware alias rather than a class.
        $matches = new SymbolLocator($this->graph())->locateSymbol('web')['matches'];

        $this->assertSame([['node' => 'web', 'file' => 'app/Http/Kernel.php', 'line' => 1]], $matches);
    }

    #[Test]
    public function a_node_the_build_never_pinned_carries_no_file_or_line_key_at_all(): void
    {
        $matches = new SymbolLocator($this->graph())->locateSymbol('Unlocated')['matches'];

        $this->assertSame([['node' => 'App\Services\Unlocated', 'kind' => 'class']], $matches);
    }

    #[Test]
    public function a_file_lookup_lists_the_nodes_defined_there(): void
    {
        $result = new SymbolLocator($this->graph())->locateFile('app/Models/Post.php');

        $this->assertSame('file', $result['by']);
        $this->assertSame(['App\Models\Post::publish', 'model::App\Models\Post'], array_column($result['matches'], 'node'));
    }

    #[Test]
    public function an_absolute_path_inside_the_project_resolves_against_a_relative_graph_key(): void
    {
        $result = new SymbolLocator($this->graph())->locateFile(base_path('app/Models/Post.php'));

        $this->assertSame(2, $result['total']);
    }

    #[Test]
    public function a_leading_dot_slash_is_stripped(): void
    {
        $this->assertSame(2, new SymbolLocator($this->graph())->locateFile('./app/Models/Post.php')['total']);
    }

    #[Test]
    public function an_absolute_key_the_graph_holds_absolute_is_probed_before_any_normalisation(): void
    {
        // NodeMetadata::relativeFile() keeps a path verbatim when the root is empty or the file sits
        // outside it, so the graph can hold an absolute key — including one inside the project.
        // Normalising first would rewrite this input into a relative form the graph does not hold.
        $absolute = base_path('app/Models/Post.php');
        $graph = new CodeGraph([
            ['source' => $absolute === '' ? '/x' : 'App\Models\Post', 'target' => 'App\Models\Post::publish', 'type' => 'call'],
        ], hasUnparseableFiles: false, nodeMetadata: [
            'App\Models\Post' => ['file' => $absolute, 'line' => 12],
            'App\Models\Post::publish' => ['file' => '/outside/the/project/Other.php', 'line' => 4],
        ]);

        $this->assertSame(['App\Models\Post'], array_column(new SymbolLocator($graph)->locateFile($absolute)['matches'], 'node'));
        $this->assertSame(['App\Models\Post::publish'], array_column(new SymbolLocator($graph)->locateFile('/outside/the/project/Other.php')['matches'], 'node'));
    }

    #[Test]
    public function a_path_form_outside_the_accepted_grammar_misses_rather_than_being_normalised(): void
    {
        $locator = new SymbolLocator($this->graph());

        // Refused on purpose: resolving path forms inconsistently makes a present file look absent,
        // and that miss is indistinguishable from a real one (see ScopedRebuild).
        $this->assertSame(0, $locator->locateFile('app//Models/Post.php')['total']);
        $this->assertSame(0, $locator->locateFile('app/Support/../Models/Post.php')['total']);
        $this->assertSame(0, $locator->locateFile('app\Models\Post.php')['total']);
    }

    #[Test]
    public function a_file_miss_offers_a_known_path_sharing_the_basename(): void
    {
        $result = new SymbolLocator($this->graph())->locateFile('app/Modles/Post.php');

        $this->assertSame([], $result['matches']);
        $this->assertArrayHasKey('suggestions', $result);
        $this->assertSame(['app/Models/Post.php'], $result['suggestions']);
    }

    #[Test]
    public function a_file_miss_carries_a_file_denominator_rather_than_the_symbol_lanes_node_count(): void
    {
        // A structured consumer must never read a miss as a bare empty list — the prose reader is
        // not the only one owed a lead. A node count would be that lead in the wrong currency: it
        // says nothing about a path, so the file lane counts FILES the graph can answer for.
        $noLead = new SymbolLocator($this->graph())->locateFile('app/Nowhere/Thing.php');

        $this->assertArrayNotHasKey('suggestions', $noLead);
        $this->assertArrayNotHasKey('graphNodeCount', $noLead);
        $this->assertArrayHasKey('graphFileCount', $noLead);
        $this->assertSame(4, $noLead['graphFileCount']);

        // With a basename lead, the suggestion IS the lead and the denominator is redundant.
        $withLead = new SymbolLocator($this->graph())->locateFile('app/Modles/Post.php');
        $this->assertArrayNotHasKey('graphNodeCount', $withLead);
        $this->assertArrayNotHasKey('graphFileCount', $withLead);

        // A graph that pins no files at all says so, which is what separates it from a wrong path.
        $empty = new SymbolLocator(new CodeGraph([], hasUnparseableFiles: false))->locateFile('app/Models/Post.php');
        $this->assertArrayNotHasKey('graphNodeCount', $empty);
        $this->assertArrayHasKey('graphFileCount', $empty);
        $this->assertSame(0, $empty['graphFileCount']);
    }

    #[Test]
    public function a_file_whose_nodes_carry_no_edge_is_indistinguishable_from_an_unknown_path(): void
    {
        // nodesDefinedIn() indexes edge-backed nodes only, by design: offering an edge-less node
        // would read as "placed, reaches nothing". So both cases are one absence, and one message.
        $graph = new CodeGraph([
            ['source' => 'A', 'target' => 'B', 'type' => 'call'],
        ], hasUnparseableFiles: false, nodeMetadata: [
            'App\Orphan' => ['file' => 'app/Orphan.php', 'line' => 2],
        ]);

        $this->assertSame([], new SymbolLocator($graph)->locateFile('app/Orphan.php')['matches']);
        $this->assertSame([], $graph->definedFiles());
    }

    #[Test]
    public function an_empty_or_blank_argument_is_refused_rather_than_looked_up(): void
    {
        $locator = new SymbolLocator($this->graph());

        foreach (['', '   '] as $blank) {
            try {
                $locator->locateSymbol($blank);
                self::fail('An empty symbol must not resolve.');
            } catch (InvalidArgumentException $invalidArgumentException) {
                $this->assertSame('The symbol argument must not be empty.', $invalidArgumentException->getMessage());
            }
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The file argument must not be empty.');

        $locator->locateFile('  ');
    }

    #[Test]
    public function the_json_document_carries_every_documented_key_with_its_values(): void
    {
        $document = JsonPresenter::locate(new SymbolLocator($this->graph())->locateSymbol('Post', 1));

        // Key ORDER is the contract too: the MCP structured content and the CLI --json document are
        // the same document, and a consumer reading them side by side must see one shape.
        $this->assertSame(['query', 'by', 'total', 'limit', 'bounded', 'matches'], array_keys($document));
        $this->assertSame('Post', $document['query']);
        $this->assertSame(2, $document['total']);
        $this->assertTrue($document['bounded']);
    }

    #[Test]
    public function the_json_document_omits_the_sparse_keys_rather_than_nulling_them(): void
    {
        $hit = JsonPresenter::locate(new SymbolLocator($this->graph())->locateSymbol('Unlocated'));
        $miss = JsonPresenter::locate(new SymbolLocator($this->graph())->locateSymbol('Zzz'));

        $this->assertSame(['query', 'by', 'total', 'bounded', 'matches'], array_keys($hit));
        $this->assertSame(['node' => 'App\Services\Unlocated', 'kind' => 'class'], $hit['matches'][0]);
        $this->assertSame(['query', 'by', 'total', 'bounded', 'matches', 'graphNodeCount'], array_keys($miss));
        $this->assertStringNotContainsString('null', JsonPresenter::encode($miss));
    }

    #[Test]
    public function the_prose_report_drops_the_segment_each_absent_field_would_have_filled(): void
    {
        $prose = ImpactFormatter::locate(new SymbolLocator($this->graph())->locateSymbol('Post'));

        // kind + file + line, then kind + file with the line absent.
        $this->assertStringContainsString('[model] model::App\Models\Post — app/Models/Post.php:12', $prose);
        $this->assertStringContainsString('[member] App\Models\Post::publish — app/Models/Post.php', $prose);
        $this->assertStringNotContainsString('publish — app/Models/Post.php:', $prose);

        // No kind, and no location at all.
        $unlabelled = ImpactFormatter::locate(new SymbolLocator($this->graph())->locateSymbol('m'));
        $this->assertStringContainsString('A::m — app/A.php:7', $unlabelled);
        $this->assertStringNotContainsString('[', $unlabelled);
        $this->assertStringContainsString('App\Services\Unlocated — location unknown', ImpactFormatter::locate(new SymbolLocator($this->graph())->locateSymbol('Unlocated')));
    }

    #[Test]
    public function the_prose_report_names_the_remainder_a_cap_held_back(): void
    {
        $prose = ImpactFormatter::locate(new SymbolLocator($this->graph())->locateSymbol('Post', 1));

        $this->assertStringContainsString('2 node(s) matching "Post":', $prose);
        $this->assertStringContainsString('… and 1 more', $prose);
    }

    #[Test]
    public function the_symbol_miss_prose_is_the_same_diagnostic_the_other_surfaces_give(): void
    {
        $withLead = ImpactFormatter::locate(new SymbolLocator($this->graph())->locateSymbol('App\Models\Pots'));
        $without = ImpactFormatter::locate(new SymbolLocator($this->graph())->locateSymbol('Zzz'));

        $this->assertStringContainsString('No graph nodes matched "App\Models\Pots".', $withLead);
        $this->assertStringContainsString('Nearest graph nodes:', $withLead);
        $this->assertStringContainsString('Scanned 6 graph nodes; none share an identifier with it.', $without);
    }

    #[Test]
    public function the_file_miss_prose_never_borrows_the_node_shaped_sentences(): void
    {
        $withLead = ImpactFormatter::locate(new SymbolLocator($this->graph())->locateFile('app/Modles/Post.php'));
        $without = ImpactFormatter::locate(new SymbolLocator($this->graph())->locateFile('app/Nowhere/Thing.php'));

        $this->assertStringContainsString('No graph nodes are defined in "app/Modles/Post.php".', $withLead);
        $this->assertStringContainsString('The graph knows app/Models/Post.php, which has the same file name.', $withLead);
        $this->assertStringContainsString('The graph pins nodes to 4 file(s)', $without);
        $this->assertStringContainsString('carries an edge', $without);
        $this->assertStringNotContainsString('Nearest graph nodes', $withLead);
        $this->assertStringNotContainsString('Scanned', $without);
    }

    #[Test]
    public function the_markdown_report_renders_the_same_segments_as_the_prose_one(): void
    {
        $markdown = MarkdownFormatter::locate(new SymbolLocator($this->graph())->locateSymbol('Post', 1));

        $this->assertStringContainsString('## Richter locate: `Post`', $markdown);
        // Sorted before the cap, so the member sorts ahead of the `model::`-prefixed entity node.
        $this->assertStringContainsString('- **member** `App\Models\Post::publish` — `app/Models/Post.php`', $markdown);
        $this->assertStringContainsString('_… and 1 more', $markdown);
        $this->assertStringContainsString(
            '- **model** `model::App\Models\Post` — `app/Models/Post.php:12`',
            MarkdownFormatter::locate(new SymbolLocator($this->graph())->locateSymbol('Post')),
        );
    }

    #[Test]
    public function the_markdown_report_renders_both_miss_lanes(): void
    {
        $locator = new SymbolLocator($this->graph());

        $this->assertStringContainsString('Nearest: `', MarkdownFormatter::locate($locator->locateSymbol('App\Models\Pots')));
        $this->assertStringContainsString('Scanned 6 graph nodes', MarkdownFormatter::locate($locator->locateSymbol('Zzz')));
        $this->assertStringContainsString('same file name', MarkdownFormatter::locate($locator->locateFile('app/Modles/Post.php')));
        $this->assertStringContainsString('carries an edge', MarkdownFormatter::locate($locator->locateFile('app/Nowhere/Thing.php')));
    }

    #[Test]
    public function a_markdown_location_is_escaped_because_a_queried_path_is_not_identifier_shaped(): void
    {
        $graph = new CodeGraph([
            ['source' => 'App\Odd', 'target' => 'App\Other', 'type' => 'call'],
        ], hasUnparseableFiles: false, nodeMetadata: [
            'App\Odd' => ['file' => 'app/we|ird/`Name`.php', 'line' => 2],
        ]);

        $markdown = MarkdownFormatter::locate(new SymbolLocator($graph)->locateSymbol('Odd'));

        $this->assertStringContainsString('we\|ird', $markdown);
        $this->assertStringContainsString('`` app/we\|ird/`Name`.php:2 ``', $markdown);
    }
}
