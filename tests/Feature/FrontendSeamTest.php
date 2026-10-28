<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Feature;

use App\Http\Controllers\Post\DashboardSearchController;
use App\Http\Controllers\Post\PostController;
use App\Http\Controllers\Post\ReviewController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Analysis\FrontendTestIndex;
use SanderMuller\Richter\Analysis\JsonPresenter;
use SanderMuller\Richter\Changes\ChangedSymbols;
use SanderMuller\Richter\Changes\FrontendChanges;
use SanderMuller\Richter\Graph\CodeGraphBuilder;
use SanderMuller\Richter\Tests\Support\FakesGit;
use SanderMuller\Richter\Tests\TestCase;

/**
 * End-to-end coverage of the frontend/Blade-inline seam inside {@see ChangedSymbols::resolve()} —
 * the branches that gate on {@see FrontendChanges::handles()}, wire head/base sources in, and pick
 * the frontend-vs-Blade-view path. Every other frontend test calls {@see FrontendChanges} directly
 * with literal source strings; this class instead drives `richter:detect-changes` against the real
 * fixture project so the seam itself — not just the units either side of it — is under test.
 *
 * Points `base_path()` at the fixture project (same mechanism
 * `CommandsTest::fakeBenchmarkReplayReachingRoutes()` already relies on for the benchmark replay),
 * so `ChangedSymbols::headSource()` reads the fixture's real committed files from the working tree
 * and `CodeGraphBuilder` builds the real graph Laravel Brain derives from the fixture's actual
 * `routes/web.php`. The routes registered below mirror that file exactly (path, name, controller
 * action) so the frontend bridge's runtime router-backed index ({@see FrontendChanges}) and the
 * statically-built graph agree on the same `route::` node ids.
 */
final class FrontendSeamTest extends TestCase
{
    use FakesGit;

    protected function setUp(): void
    {
        parent::setUp();

        $app = $this->app;
        $this->assertInstanceOf(Application::class, $app);
        $app->setBasePath(self::fixtureProjectPath());

        config()->set('richter.frontend.roots', ['resources/js']);

        Route::get('/posts/{post}/reviews', [ReviewController::class, 'show'])->name('posts.reviews.show');
        Route::get('/posts/{post}/edit', [ReviewController::class, 'edit'])->name('posts.edit');
        Route::post('/posts', [PostController::class, 'store'])->name('posts.store');
        Route::put('/posts/{post}', [PostController::class, 'update'])->name('posts.update');
        Route::get('/dashboard/search', [DashboardSearchController::class, 'index'])->name('dashboard.search');
    }

    /** A one-added-line diff for the path — enough to register the file as changed; UnifiedDiffParser only needs a hunk to exist, and FrontendChanges::resolve() scans the whole head source, not the hunk. */
    private function additionDiff(string $path, string $addedLine): string
    {
        return "diff --git a/{$path} b/{$path}\n--- a/{$path}\n+++ b/{$path}\n@@ -0,0 +1,1 @@\n+{$addedLine}\n";
    }

    /** Fakes the git plumbing for one diff, runs richter:detect-changes --json, asserts success, and returns the decoded report. A null base source reads as a file new in this diff. */
    /** @param  array<string, bool>  $options */
    private function detectChangesFor(string $diff, ?string $baseSource = null, array $options = []): mixed
    {
        $this->fakeGit([
            '*merge-base*' => Process::result("abc123\n"),
            '*show*' => $baseSource === null ? Process::result(errorOutput: 'bad object', exitCode: 128) : Process::result($baseSource),
            '*diff*' => Process::result($diff),
        ]);

        $this->withoutMockingConsoleOutput();
        $exitCode = Artisan::call('richter:detect-changes', ['--base' => 'some-base', '--json' => true, ...$options]);
        $this->assertSame(0, $exitCode);

        return json_decode(Artisan::output(), associative: true);
    }

    #[Test]
    public function the_graph_links_the_routers_action_to_its_form_request(): void
    {
        // The dropped-request-field lane takes a route to its action through the router and from the
        // action to its form request through the graph. Both halves must name the action the same way.
        Route::getRoutes()->refreshNameLookups();
        $route = Route::getRoutes()->getByName('posts.store');
        $this->assertInstanceOf(\Illuminate\Routing\Route::class, $route);
        [$class, $method] = explode('@', $route->getActionName(), 2);
        $action = ltrim($class, '\\') . "::{$method}";

        $graph = new CodeGraphBuilder()->build(self::fixtureProjectPath());

        $this->assertSame('App\\Http\\Controllers\\Post\\PostController::store', $action);
        $this->assertSame(['App\\Http\\Requests\\Post\\StorePostRequest::validated'], $graph->outgoingTargetsOfType([$action], 'action-to-form-request'));
    }

    #[Test]
    public function a_field_the_frontend_stops_sending_is_a_finding_that_leaves_risk_alone(): void
    {
        // `subtitle` is `present` in the fixture's StorePostRequest, so dropping it reports.
        $file = 'resources/js/components/PostForm.ts';
        $decoded = $this->detectChangesFor($this->postFormDiff($file), $this->postFormBase($file));

        $this->assertIsArray($decoded);
        $this->assertIsArray($decoded['entryPoints']);
        $this->assertIsArray($decoded['findings']);
        $this->assertContains('route::POST::/posts', $decoded['entryPoints']);
        $this->assertContains(
            "{$file}: no longer sends 'subtitle' to POST /posts, which App\\Http\\Requests\\Post\\StorePostRequest::rules() still requires",
            $decoded['findings'],
        );
        $this->assertSame([], $decoded['hazards']);
        $this->assertSame('low', $decoded['risk']);
    }

    #[Test]
    public function inline_validation_in_the_routes_action_is_read_on_the_built_graph(): void
    {
        // The base also updated a post. The fixture's `PostController::update` requires `summary`
        // inline; `excerpt` is `sometimes|required`, so dropping it is not a break.
        $file = 'resources/js/components/PostForm.ts';
        $base = $this->postFormBase($file) . "\nexport function updatePost(id: number) {\n    return axios.put(route('posts.update', id), { summary, excerpt });\n}\n";

        $decoded = $this->detectChangesFor($this->postFormDiff($file), $base);

        $this->assertIsArray($decoded);
        $this->assertIsArray($decoded['findings']);
        $this->assertContains("{$file}: no longer sends 'summary' to PUT /posts/{post}, which App\\Http\\Controllers\\Post\\PostController::update still requires", $decoded['findings']);
        $this->assertSame([], array_values(array_filter($decoded['findings'], static fn (mixed $finding): bool => is_string($finding) && str_contains($finding, "'excerpt'"))));
    }

    #[Test]
    public function the_payload_parity_switch_turns_the_dropped_field_lane_off(): void
    {
        $file = 'resources/js/components/PostForm.ts';
        $decoded = $this->detectChangesFor($this->postFormDiff($file), $this->postFormBase($file), ['--no-payload-parity' => true]);

        $this->assertIsArray($decoded);
        $this->assertSame([], $decoded['findings']);
    }

    /** The fixture's head sends `{ title, body }`; this base also sent `subtitle`. */
    private function postFormBase(string $file): string
    {
        $head = (string) file_get_contents(base_path($file));
        $base = str_replace('{ title, body }', '{ title, subtitle, body }', $head);
        $this->assertNotSame($head, $base);

        return $base;
    }

    private function postFormDiff(string $file): string
    {
        return "diff --git a/{$file} b/{$file}\n--- a/{$file}\n+++ b/{$file}\n@@ -2,1 +2,1 @@\n"
            . "-    return axios.post('/posts', { title, subtitle, body });\n"
            . "+    return axios.post('/posts', { title, body });\n";
    }

    #[Test]
    public function the_fixture_project_is_the_working_tree(): void
    {
        $this->assertFileExists(base_path('resources/js/components/PostApi.ts'));
    }

    #[Test]
    public function a_changed_frontend_file_seeds_the_backend_routes_it_references(): void
    {
        $decoded = $this->detectChangesFor($this->additionDiff('resources/js/components/PostApi.ts', 'export function searchDashboard() {}'));

        $this->assertIsArray($decoded);

        $changed = $decoded['changed'];
        $this->assertIsArray($changed);
        $this->assertArrayHasKey('resources/js/components/PostApi.ts', $changed);

        $coverage = $decoded['coverage'];
        $this->assertIsArray($coverage);
        $this->assertSame('analyzed', $coverage['resources/js/components/PostApi.ts']);

        // All three reference kinds — a Wayfinder action import (ReviewController::show), a Ziggy
        // route() call (posts.edit) and a bare URI literal (/dashboard/search) — resolve onto the
        // fixture's real routes and surface as touched entry points.
        $entryPoints = $decoded['entryPoints'];
        $this->assertIsArray($entryPoints);
        $this->assertContains('route::GET::/posts/{post}/reviews', $entryPoints);
        $this->assertContains('route::GET::/posts/{post}/edit', $entryPoints);
        $this->assertContains('route::GET::/dashboard/search', $entryPoints);

        // A frontend change never alters backend behaviour — it must never move the risk level.
        $this->assertSame('low', $decoded['risk']);
    }

    #[Test]
    public function a_wayfinder_generated_path_is_excluded_from_the_frontend_bridge(): void
    {
        // Inside resources/js/actions/ — the default richter.frontend.generated_paths tree. handles()
        // must gate it out before any source is even read, so the file never enters $changed at all;
        // the report reads as if the diff were empty.
        $decoded = $this->detectChangesFor($this->additionDiff('resources/js/actions/App/Http/Controllers/Post/ReviewController.ts', 'export function show() {}'));

        $this->assertSame(JsonPresenter::emptyDetectChanges('some-base'), $decoded);
    }

    #[Test]
    public function the_fixture_spec_file_registers_as_a_frontend_test_reference(): void
    {
        // The committed spec fixture references ReviewController::show via a Wayfinder action
        // import — the index built from the configured roots must map it onto that route node.
        $index = FrontendTestIndex::fromConfiguredPaths(base_path());

        $this->assertSame(
            ['resources/js/specs/post.spec.ts'],
            $index->testsReferencing('route::GET::/posts/{post}/reviews'),
        );
    }

    #[Test]
    public function a_changed_blade_view_seeds_the_endpoint_its_inline_script_calls(): void
    {
        $decoded = $this->detectChangesFor($this->additionDiff('resources/views/posts/inline.blade.php', "<script>fetch('/dashboard/search');</script>"));

        $this->assertIsArray($decoded);

        $changed = $decoded['changed'];
        $this->assertIsArray($changed);
        $this->assertArrayHasKey('resources/views/posts/inline.blade.php', $changed);

        // The fixture view is a standalone page: no controller renders it and no other view
        // @includes/@extends it, so Brain's graph never gains a node for it (BladeViewTracer only
        // links views that are actually referenced) — the view seed itself resolves to nothing, and
        // coverage honestly reads UNRESOLVED rather than a falsely-reassuring "analyzed".
        $coverage = $decoded['coverage'];
        $this->assertIsArray($coverage);
        $this->assertSame('unresolved', $coverage['resources/views/posts/inline.blade.php']);

        // The inline <script>'s endpoint reference is a separate lane from the view seed
        // (ImpactAnalyzer::detectChanges's directSeeds loop reports route:: seeds as touched entry
        // points regardless of whether the file's own coverage resolves) — it still surfaces here.
        $entryPoints = $decoded['entryPoints'];
        $this->assertIsArray($entryPoints);
        $this->assertContains('route::GET::/dashboard/search', $entryPoints);
        $this->assertSame('low', $decoded['risk']);
    }
}
