<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Analysis\FrontendTestIndex;
use SanderMuller\Richter\Tests\TestCase;

final class FrontendTestIndexTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('richter.frontend.roots', ['resources/js']);
        Route::get('/videos/{video}', ['App\Http\Controllers\VideoController', 'show'])->name('videos.show');
        Route::post('/videos', ['App\Http\Controllers\VideoController', 'store'])->name('videos.store');
        $this->projectRoot = sys_get_temp_dir() . '/richter-frontend-tests-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        new Filesystem()->deleteDirectory($this->projectRoot);
        parent::tearDown();
    }

    #[Test]
    public function specs_register_per_referenced_route_node(): void
    {
        $index = new FrontendTestIndex();
        $index->addSource("import { store } from '@/actions/App/Http/Controllers/VideoController';", 'resources/js/Pages/Videos.test.ts');
        $index->addSource("await page.goto('/videos/7');", 'e2e/videos.spec.ts');

        $this->assertSame(['resources/js/Pages/Videos.test.ts'], $index->testsReferencing('route::POST::/videos'));
        $this->assertSame(['e2e/videos.spec.ts'], $index->testsReferencing('route::GET::/videos/{video}'));
        $this->assertSame([], $index->testsReferencing('route::GET::/unreferenced'));
    }

    #[Test]
    public function configured_paths_scan_only_spec_named_files(): void
    {
        mkdir("{$this->projectRoot}/resources/js/Pages", recursive: true);
        file_put_contents("{$this->projectRoot}/resources/js/Pages/Videos.test.ts", "route('videos.show');");
        // An application source referencing the same route must never register as a test.
        file_put_contents("{$this->projectRoot}/resources/js/Pages/Videos.vue", "route('videos.show');");

        $index = FrontendTestIndex::fromConfiguredPaths($this->projectRoot);

        $this->assertSame(['resources/js/Pages/Videos.test.ts'], $index->testsReferencing('route::GET::/videos/{video}'));
    }

    #[Test]
    public function explicit_test_paths_override_the_roots(): void
    {
        config()->set('richter.frontend.test_paths', ['e2e']);
        mkdir("{$this->projectRoot}/e2e", recursive: true);
        mkdir("{$this->projectRoot}/resources/js", recursive: true);
        file_put_contents("{$this->projectRoot}/e2e/videos.spec.ts", "route('videos.store');");
        file_put_contents("{$this->projectRoot}/resources/js/ignored.spec.ts", "route('videos.show');");

        $index = FrontendTestIndex::fromConfiguredPaths($this->projectRoot);

        $this->assertSame(['e2e/videos.spec.ts'], $index->testsReferencing('route::POST::/videos'));
        $this->assertSame([], $index->testsReferencing('route::GET::/videos/{video}'));
    }
}
