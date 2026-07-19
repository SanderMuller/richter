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
        Route::get('/ping', ['App\Http\Controllers\PingController', '__invoke']);
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

        $this->assertSame(['route::GET::/videos/{video}', 'route::POST::/videos'], $symbols->directSeeds);
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
