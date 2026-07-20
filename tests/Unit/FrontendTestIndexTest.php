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
        Route::get('/posts/{post}', ['App\Http\Controllers\PostController', 'show'])->name('posts.show');
        Route::post('/posts', ['App\Http\Controllers\PostController', 'store'])->name('posts.store');
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
        $index->addSource("import { store } from '@/actions/App/Http/Controllers/PostController';", 'resources/js/Pages/Posts.test.ts');
        $index->addSource("await page.goto('/posts/7');", 'e2e/posts.spec.ts');

        $this->assertSame(['resources/js/Pages/Posts.test.ts'], $index->testsReferencing('route::POST::/posts'));
        $this->assertSame(['e2e/posts.spec.ts'], $index->testsReferencing('route::GET::/posts/{post}'));
        $this->assertSame([], $index->testsReferencing('route::GET::/unreferenced'));
    }

    #[Test]
    public function configured_paths_scan_only_spec_named_files(): void
    {
        mkdir("{$this->projectRoot}/resources/js/Pages", recursive: true);
        file_put_contents("{$this->projectRoot}/resources/js/Pages/Posts.test.ts", "route('posts.show');");
        // An application source referencing the same route must never register as a test.
        file_put_contents("{$this->projectRoot}/resources/js/Pages/Posts.vue", "route('posts.show');");

        $index = FrontendTestIndex::fromConfiguredPaths($this->projectRoot);

        $this->assertSame(['resources/js/Pages/Posts.test.ts'], $index->testsReferencing('route::GET::/posts/{post}'));
    }

    #[Test]
    public function explicit_test_paths_override_the_roots(): void
    {
        config()->set('richter.frontend.test_paths', ['e2e']);
        mkdir("{$this->projectRoot}/e2e", recursive: true);
        mkdir("{$this->projectRoot}/resources/js", recursive: true);
        file_put_contents("{$this->projectRoot}/e2e/posts.spec.ts", "route('posts.store');");
        file_put_contents("{$this->projectRoot}/resources/js/ignored.spec.ts", "route('posts.show');");

        $index = FrontendTestIndex::fromConfiguredPaths($this->projectRoot);

        $this->assertSame(['e2e/posts.spec.ts'], $index->testsReferencing('route::POST::/posts'));
        $this->assertSame([], $index->testsReferencing('route::GET::/posts/{post}'));
    }
}
