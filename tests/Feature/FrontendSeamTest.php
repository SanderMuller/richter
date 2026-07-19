<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Feature;

use App\Http\Controllers\Video\DashboardSearchController;
use App\Http\Controllers\Video\QuestionController;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Analysis\FrontendTestIndex;
use SanderMuller\Richter\Analysis\JsonPresenter;
use SanderMuller\Richter\Changes\ChangedSymbols;
use SanderMuller\Richter\Changes\FrontendChanges;
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
    protected function setUp(): void
    {
        parent::setUp();

        $app = $this->app;
        $this->assertInstanceOf(Application::class, $app);
        $app->setBasePath(self::fixtureProjectPath());

        config()->set('richter.frontend.roots', ['resources/js']);

        Route::get('/videos/{video}/questions', [QuestionController::class, 'show'])->name('videos.questions.show');
        Route::get('/videos/{video}/edit', [QuestionController::class, 'edit'])->name('videos.edit');
        Route::get('/dashboard/search', [DashboardSearchController::class, 'index'])->name('dashboard.search');
    }

    #[Test]
    public function the_fixture_project_is_the_working_tree(): void
    {
        $this->assertFileExists(base_path('resources/js/components/VideoApi.ts'));
    }

    #[Test]
    public function a_changed_frontend_file_seeds_the_backend_routes_it_references(): void
    {
        // One added line is enough to register the file as changed — UnifiedDiffParser only needs a
        // hunk to exist; FrontendChanges::resolve() scans the whole head source, not the hunk.
        $diff = "diff --git a/resources/js/components/VideoApi.ts b/resources/js/components/VideoApi.ts\n"
            . "--- a/resources/js/components/VideoApi.ts\n"
            . "+++ b/resources/js/components/VideoApi.ts\n"
            . "@@ -0,0 +1,1 @@\n"
            . "+export function searchDashboard() {}\n";

        Process::fake([
            '*merge-base*' => Process::result("abc123\n"),
            '*show*' => Process::result(errorOutput: 'bad object', exitCode: 128),
            '*diff*' => Process::result($diff),
        ]);

        $this->withoutMockingConsoleOutput();
        $exitCode = Artisan::call('richter:detect-changes', ['--base' => 'some-base', '--json' => true]);
        $decoded = json_decode(Artisan::output(), associative: true);

        $this->assertSame(0, $exitCode);
        $this->assertIsArray($decoded);

        $changed = $decoded['changed'];
        $this->assertIsArray($changed);
        $this->assertArrayHasKey('resources/js/components/VideoApi.ts', $changed);

        $coverage = $decoded['coverage'];
        $this->assertIsArray($coverage);
        $this->assertSame('analyzed', $coverage['resources/js/components/VideoApi.ts']);

        // All three reference kinds — a Wayfinder action import (QuestionController::show), a Ziggy
        // route() call (videos.edit) and a bare URI literal (/dashboard/search) — resolve onto the
        // fixture's real routes and surface as touched entry points.
        $entryPoints = $decoded['entryPoints'];
        $this->assertIsArray($entryPoints);
        $this->assertContains('route::GET::/videos/{video}/questions', $entryPoints);
        $this->assertContains('route::GET::/videos/{video}/edit', $entryPoints);
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
        $diff = "diff --git a/resources/js/actions/App/Http/Controllers/Video/QuestionController.ts b/resources/js/actions/App/Http/Controllers/Video/QuestionController.ts\n"
            . "--- a/resources/js/actions/App/Http/Controllers/Video/QuestionController.ts\n"
            . "+++ b/resources/js/actions/App/Http/Controllers/Video/QuestionController.ts\n"
            . "@@ -0,0 +1,1 @@\n"
            . "+export function show() {}\n";

        Process::fake([
            '*merge-base*' => Process::result("abc123\n"),
            '*show*' => Process::result(errorOutput: 'bad object', exitCode: 128),
            '*diff*' => Process::result($diff),
        ]);

        $this->withoutMockingConsoleOutput();
        $exitCode = Artisan::call('richter:detect-changes', ['--base' => 'some-base', '--json' => true]);
        $decoded = json_decode(Artisan::output(), associative: true);

        $this->assertSame(0, $exitCode);
        $this->assertSame(JsonPresenter::emptyDetectChanges('some-base'), $decoded);
    }

    #[Test]
    public function the_fixture_spec_file_registers_as_a_frontend_test_reference(): void
    {
        // The committed spec fixture references QuestionController::show via a Wayfinder action
        // import — the index built from the configured roots must map it onto that route node.
        $index = FrontendTestIndex::fromConfiguredPaths(base_path());

        $this->assertSame(
            ['resources/js/specs/video.spec.ts'],
            $index->testsReferencing('route::GET::/videos/{video}/questions'),
        );
    }

    #[Test]
    public function a_changed_blade_view_seeds_the_endpoint_its_inline_script_calls(): void
    {
        $diff = "diff --git a/resources/views/videos/inline.blade.php b/resources/views/videos/inline.blade.php\n"
            . "--- a/resources/views/videos/inline.blade.php\n"
            . "+++ b/resources/views/videos/inline.blade.php\n"
            . "@@ -0,0 +1,1 @@\n"
            . "+<script>fetch('/dashboard/search');</script>\n";

        Process::fake([
            '*merge-base*' => Process::result("abc123\n"),
            '*show*' => Process::result(errorOutput: 'bad object', exitCode: 128),
            '*diff*' => Process::result($diff),
        ]);

        $this->withoutMockingConsoleOutput();
        $exitCode = Artisan::call('richter:detect-changes', ['--base' => 'some-base', '--json' => true]);
        $decoded = json_decode(Artisan::output(), associative: true);

        $this->assertSame(0, $exitCode);
        $this->assertIsArray($decoded);

        $changed = $decoded['changed'];
        $this->assertIsArray($changed);
        $this->assertArrayHasKey('resources/views/videos/inline.blade.php', $changed);

        // The fixture view is a standalone page: no controller renders it and no other view
        // @includes/@extends it, so Brain's graph never gains a node for it (BladeViewTracer only
        // links views that are actually referenced) — the view seed itself resolves to nothing, and
        // coverage honestly reads UNRESOLVED rather than a falsely-reassuring "analyzed".
        $coverage = $decoded['coverage'];
        $this->assertIsArray($coverage);
        $this->assertSame('unresolved', $coverage['resources/views/videos/inline.blade.php']);

        // The inline <script>'s endpoint reference is a separate lane from the view seed
        // (ImpactAnalyzer::detectChanges's directSeeds loop reports route:: seeds as touched entry
        // points regardless of whether the file's own coverage resolves) — it still surfaces here.
        $entryPoints = $decoded['entryPoints'];
        $this->assertIsArray($entryPoints);
        $this->assertContains('route::GET::/dashboard/search', $entryPoints);
        $this->assertSame('low', $decoded['risk']);
    }
}
