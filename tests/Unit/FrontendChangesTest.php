<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Changes\FrontendChanges;
use SanderMuller\Richter\Tests\TestCase;

final class FrontendChangesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('richter.frontend.roots', ['resources/js']);
        Route::get('/videos/{video}', ['App\Http\Controllers\VideoController', 'show'])->name('videos.show');
        Route::post('/videos', ['App\Http\Controllers\VideoController', 'store'])->name('videos.store');
        Route::get('/videos', ['App\Http\Controllers\VideoController', 'index'])->name('videos.index');
        Route::get('/ping', ['App\Http\Controllers\PingController', '__invoke']);
        Route::get('/users/{user?}', ['App\Http\Controllers\UserController', 'show'])->name('users.show');
    }

    private function frontend(): FrontendChanges
    {
        return new FrontendChanges();
    }

    #[Test]
    public function handles_requires_the_bridge_to_be_configured_on(): void
    {
        config()->set('richter.frontend.roots', []);

        $this->assertFalse($this->frontend()->handles('resources/js/Pages/Videos.vue'));
    }

    #[Test]
    public function handles_matches_frontend_extensions_under_a_configured_root(): void
    {
        $frontend = $this->frontend();

        $this->assertTrue($frontend->handles('resources/js/Pages/Videos.vue'));
        $this->assertTrue($frontend->handles('resources/js/lib/api.ts'));
        $this->assertFalse($frontend->handles('resources/css/app.css'));
        $this->assertFalse($frontend->handles('app/Models/Video.php'));
        $this->assertFalse($frontend->handles('other/js/thing.ts'));
    }

    #[Test]
    public function handles_excludes_wayfinder_generated_trees(): void
    {
        $frontend = $this->frontend();

        $this->assertFalse($frontend->handles('resources/js/actions/App/Http/Controllers/VideoController.ts'));
        $this->assertFalse($frontend->handles('resources/js/routes/videos.ts'));
        $this->assertFalse($frontend->handles('resources/js/wayfinder/index.ts'));
        // A file merely named after them, outside the generated tree, still scans.
        $this->assertTrue($frontend->handles('resources/js/Pages/actions.ts'));
    }

    #[Test]
    public function handles_excludes_generated_files_and_globs(): void
    {
        config()->set('richter.frontend.generated_paths', ['actions', 'ziggy.js', '*.generated.ts']);
        $frontend = $this->frontend();

        // An exact file directly under a root — inexpressible under directory-prefix-only rules.
        $this->assertFalse($frontend->handles('resources/js/ziggy.js'));
        $this->assertFalse($frontend->handles('resources/js/api.generated.ts'));
        // Str::is's `*` crosses `/`, so the glob matches at any depth under the root.
        $this->assertFalse($frontend->handles('resources/js/deep/nested/api.generated.ts'));
        // Directory-entry semantics keep working alongside the new file/glob matching.
        $this->assertFalse($frontend->handles('resources/js/actions/Foo.ts'));
        $this->assertTrue($frontend->handles('resources/js/lib/api.ts'));
    }

    #[Test]
    public function handles_excludes_ziggy_output_by_default(): void
    {
        // No generated_paths override — exercises the shipped default (ziggy.js joins it).
        $this->assertFalse($this->frontend()->handles('resources/js/ziggy.js'));
    }

    #[Test]
    public function a_wayfinder_action_import_maps_to_the_routes_of_that_action(): void
    {
        $symbols = $this->frontend()->resolve(
            'resources/js/Pages/Videos.vue',
            'import { store } from "@/actions/App/Http/Controllers/VideoController";',
            null,
        );

        $this->assertSame(['route::POST::/videos'], $symbols->directSeeds);
        $this->assertFalse($symbols->unresolvedFrontendReferences);
        $this->assertSame([], $symbols->findings);
    }

    #[Test]
    public function a_default_action_import_maps_to_every_route_of_the_controller(): void
    {
        $symbols = $this->frontend()->resolve(
            'resources/js/Pages/Videos.vue',
            'import VideoController from "@/actions/App/Http/Controllers/VideoController";',
            null,
        );

        $this->assertSame(['route::GET::/videos/{video}', 'route::POST::/videos', 'route::GET::/videos'], $symbols->directSeeds);
    }

    #[Test]
    public function an_invokable_controller_import_maps_through_its_invoke_route(): void
    {
        $symbols = $this->frontend()->resolve(
            'resources/js/lib/ping.ts',
            'import ping from "@/actions/App/Http/Controllers/PingController";',
            null,
        );

        $this->assertSame(['route::GET::/ping'], $symbols->directSeeds);
    }

    #[Test]
    public function ziggy_and_wayfinder_route_names_map_through_the_name_index(): void
    {
        $symbols = $this->frontend()->resolve(
            'resources/js/Pages/Videos.vue',
            "import { show } from '@/routes/videos';\nroute('videos.store');",
            null,
        );

        $this->assertSame(['route::GET::/videos/{video}', 'route::POST::/videos'], $symbols->directSeeds);
    }

    #[Test]
    public function a_literal_uri_maps_through_the_route_templates(): void
    {
        $symbols = $this->frontend()->resolve(
            'resources/js/lib/api.ts',
            "axios.post('/videos'); fetch('/videos/123?tab=stats');",
            null,
        );

        // '/videos' matches only its own path (never the parameterised '/videos/{video}'), and
        // the pinned `.post` scopes it past the GET registration sharing that path.
        $this->assertSame(['route::POST::/videos', 'route::GET::/videos/{video}'], $symbols->directSeeds);
        $this->assertFalse($symbols->unresolvedFrontendReferences);
    }

    #[Test]
    public function an_unpinned_literal_on_a_shared_path_seeds_every_method(): void
    {
        $symbols = $this->frontend()->resolve('resources/js/lib/api.ts', "load('/videos');", null);

        $this->assertSame(['route::POST::/videos', 'route::GET::/videos'], $symbols->directSeeds);
    }

    #[Test]
    public function a_verb_no_route_serves_keeps_the_path_match_whole(): void
    {
        // Tests referencing '/ping' are method-agnostic — dropping the match on a verb mismatch
        // would under-select them.
        $symbols = $this->frontend()->resolve('resources/js/lib/api.ts', "axios.delete('/ping');", null);

        $this->assertSame(['route::GET::/ping'], $symbols->directSeeds);
    }

    #[Test]
    public function an_optional_parameter_route_matches_literals_with_and_without_the_segment(): void
    {
        $frontend = $this->frontend();

        $withSegment = $frontend->resolve('resources/js/a.ts', "fetch('/users/7');", null);
        $withoutSegment = $frontend->resolve('resources/js/b.ts', "fetch('/users');", null);

        $this->assertSame(['route::GET::/users/{user?}'], $withSegment->directSeeds);
        $this->assertSame(['route::GET::/users/{user?}'], $withoutSegment->directSeeds);
    }

    #[Test]
    public function an_optional_parameter_route_matches_a_trailing_slash_literal(): void
    {
        // '/videos/{video?}' would register alongside the required '/videos/{video}' — instead
        // reuse the existing optional-param route and assert the trailing-slash form of its
        // base path (without the segment) still matches.
        $symbols = $this->frontend()->resolve('resources/js/a.ts', "fetch('/users/');", null);

        $this->assertSame(['route::GET::/users/{user?}'], $symbols->directSeeds);
    }

    #[Test]
    public function a_bare_root_literal_matches_a_root_optional_parameter_route(): void
    {
        // A registration-local route: '/{locale?}' is a catch-all shape that would otherwise
        // swallow every other test's literal if registered in setUp().
        Route::get('/{locale?}', ['App\Http\Controllers\HomeController', 'index'])->name('home.index');

        $symbols = $this->frontend()->resolve('resources/js/a.ts', "fetch('/');", null);

        $this->assertSame(['route::GET::/{locale?}'], $symbols->directSeeds);
    }

    #[Test]
    public function a_template_literal_endpoint_matches_through_its_wildcarded_interpolation(): void
    {
        $symbols = $this->frontend()->resolve('resources/js/lib/api.ts', 'fetch(`/videos/${id}`);', null);

        $this->assertSame(['route::GET::/videos/{video}'], $symbols->directSeeds);
        $this->assertFalse($symbols->unresolvedFrontendReferences);
    }

    #[Test]
    public function inline_uri_seeds_map_script_literals_only(): void
    {
        // The Blade lane: fetch literals inside <script> seed; markup hrefs, form actions and
        // Blade's route() helper are navigation, not endpoint calls.
        $seeds = $this->frontend()->inlineUriSeeds(
            "<a href=\"/videos/9\">watch</a>\n<form action=\"/videos\" method=\"POST\"></form>\n<script>fetch('/videos/7', {method: 'GET'});</script>",
            "<a href=\"{{ route('videos.store') }}\">old</a>",
        );

        $this->assertSame(['route::GET::/videos/{video}'], $seeds);
    }

    #[Test]
    public function route_nodes_in_unions_all_three_reference_kinds(): void
    {
        $nodes = $this->frontend()->routeNodesIn(<<<'TS'
            import { store } from "@/actions/App/Http/Controllers/VideoController";
            route('users.show');
            fetch(`/videos/${id}`);
            TS);

        $this->assertSame(
            ['route::POST::/videos', 'route::GET::/users/{user?}', 'route::GET::/videos/{video}'],
            $nodes,
        );
    }

    #[Test]
    public function a_non_endpoint_literal_matches_no_template_and_is_not_a_reference(): void
    {
        $symbols = $this->frontend()->resolve(
            'resources/js/Pages/Videos.vue',
            "const logo = '/img/logo.svg';",
            null,
        );

        $this->assertSame([], $symbols->directSeeds);
        $this->assertFalse($symbols->unresolvedFrontendReferences);
    }

    #[Test]
    public function a_data_file_of_route_matching_literals_seeds_nothing(): void
    {
        // Today (pre call-argument anchoring) this seeds route::POST::/videos,
        // route::GET::/videos, and route::GET::/videos/{video} — the false-positive flood in
        // miniature that a constants/nav-link file produces when its strings happen to match
        // real route templates.
        $symbols = $this->frontend()->resolve(
            'resources/js/Pages/Videos.vue',
            "const LINKS = { videos: '/videos', video: '/videos/9' };",
            null,
        );

        $this->assertSame([], $symbols->directSeeds);
        $this->assertFalse($symbols->unresolvedFrontendReferences);
    }

    #[Test]
    public function an_unmatched_wayfinder_action_import_reads_as_unresolved(): void
    {
        $symbols = $this->frontend()->resolve(
            'resources/js/Pages/Gone.vue',
            'import { destroy } from "@/actions/App/Http/Controllers/GhostController";',
            null,
        );

        $this->assertSame([], $symbols->directSeeds);
        $this->assertTrue($symbols->unresolvedFrontendReferences);
        $this->assertSame(
            ['Wayfinder import references App\Http\Controllers\GhostController::destroy which matches no registered route'],
            $symbols->findings,
        );
    }

    #[Test]
    public function an_unmatched_route_name_silently_is_not_a_reference(): void
    {
        // `route('x')` and `routes/…` imports collide with frontend-router idioms — an unknown
        // name drops rather than guessing or blocking determination.
        $symbols = $this->frontend()->resolve('resources/js/Pages/Videos.vue', "route('not.a.backend.route');", null);

        $this->assertSame([], $symbols->directSeeds);
        $this->assertFalse($symbols->unresolvedFrontendReferences);
    }

    #[Test]
    public function a_dynamic_route_argument_reads_as_unresolved_with_a_finding(): void
    {
        $symbols = $this->frontend()->resolve('resources/js/Pages/Videos.vue', 'route(`videos.${action}`);', null);

        $this->assertTrue($symbols->unresolvedFrontendReferences);
        $this->assertSame(['a dynamic route() argument prevents resolving every referenced endpoint'], $symbols->findings);
    }

    #[Test]
    public function a_reference_removed_by_the_change_still_seeds_from_the_base_side(): void
    {
        $symbols = $this->frontend()->resolve(
            'resources/js/Pages/Videos.vue',
            'const nothing = 1;',
            "route('videos.store');",
        );

        $this->assertSame(['route::POST::/videos'], $symbols->directSeeds);
    }

    #[Test]
    public function a_file_without_references_is_a_determined_empty_answer(): void
    {
        $symbols = $this->frontend()->resolve('resources/js/Pages/About.vue', 'const x = 1;', 'const x = 0;');

        $this->assertSame([], $symbols->directSeeds);
        $this->assertFalse($symbols->unresolvedFrontendReferences);
        $this->assertTrue($symbols->hasOnlyAdditiveOrCosmeticChanges());
    }
}
