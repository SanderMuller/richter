<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use SanderMuller\Richter\Analysis\FrontendConsumerIndex;
use SanderMuller\Richter\Tests\TestCase;

/**
 * Inline `addSource()` sources and a throwaway temp project — never the shared fixture
 * project, whose node counts other suites assert against.
 */
final class FrontendConsumerIndexTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('richter.frontend.roots', ['resources/js']);
        Route::get('/posts/{post}', ['App\Http\Controllers\PostController', 'show'])->name('posts.show');
        Route::post('/posts', ['App\Http\Controllers\PostController', 'store'])->name('posts.store');
        $this->projectRoot = sys_get_temp_dir() . '/richter-consumer-index-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        new Filesystem()->deleteDirectory($this->projectRoot);
        parent::tearDown();
    }

    #[Test]
    public function sources_register_per_referenced_route_node(): void
    {
        $index = new FrontendConsumerIndex();
        $index->addSource("axios.post('/posts');", 'resources/js/Pages/Posts/Create.vue');
        $index->addSource('await fetch(`/posts/${id}`);', 'resources/js/composables/usePost.ts');
        // Registering the same file twice must not duplicate it.
        $index->addSource("axios.post('/posts');", 'resources/js/Pages/Posts/Create.vue');

        $this->assertSame(['resources/js/Pages/Posts/Create.vue'], $index->filesReferencing('route::POST::/posts'));
        $this->assertSame(['resources/js/composables/usePost.ts'], $index->filesReferencing('route::GET::/posts/{post}'));
        $this->assertSame([], $index->filesReferencing('route::GET::/unreferenced'));
    }

    #[Test]
    public function a_project_scan_covers_js_roots_and_blade_inline_scripts(): void
    {
        mkdir("{$this->projectRoot}/resources/js", recursive: true);
        mkdir("{$this->projectRoot}/resources/views", recursive: true);
        file_put_contents("{$this->projectRoot}/resources/js/consumer.ts", "fetch('/posts/7');");
        file_put_contents("{$this->projectRoot}/resources/views/widget.blade.php", "<div>hi</div>\n<script>fetch('/posts', { method: 'POST' });\naxios.post('/posts');</script>\n");
        // No <script> block: navigation links and Blade route() helpers are link
        // GENERATION, not consumption — the file must not register at all.
        file_put_contents("{$this->projectRoot}/resources/views/nav.blade.php", "<a href=\"{{ route('posts.show', 1) }}\">post</a>");

        $index = FrontendConsumerIndex::fromProject($this->projectRoot);

        $this->assertSame(['resources/js/consumer.ts'], $index->filesReferencing('route::GET::/posts/{post}'));
        $this->assertSame(['resources/views/widget.blade.php'], $index->filesReferencing('route::POST::/posts'));
    }

    #[Test]
    public function generated_paths_and_declaration_files_stay_out_of_the_index(): void
    {
        mkdir("{$this->projectRoot}/resources/js/actions", recursive: true);
        file_put_contents("{$this->projectRoot}/resources/js/ziggy.js", "route('posts.store');");
        file_put_contents("{$this->projectRoot}/resources/js/actions/generated.ts", "route('posts.store');");
        file_put_contents("{$this->projectRoot}/resources/js/types.d.ts", "type X = 'posts.store';\nroute('posts.store');");

        $index = FrontendConsumerIndex::fromProject($this->projectRoot);

        $this->assertSame([], $index->filesReferencing('route::POST::/posts'));
    }

    #[Test]
    public function blade_views_are_indexed_even_without_configured_frontend_roots(): void
    {
        // A pure-Blade app has no frontend.roots — its Alpine/vanilla fetch widgets are
        // still the consumer surface, so the gate never requires roots.
        config()->set('richter.frontend.roots', []);
        mkdir("{$this->projectRoot}/resources/views", recursive: true);
        file_put_contents("{$this->projectRoot}/resources/views/widget.blade.php", "<script>fetch('/posts', { method: 'POST' });\naxios.post('/posts');</script>");

        $index = FrontendConsumerIndex::fromProject($this->projectRoot);

        $this->assertSame(['resources/views/widget.blade.php'], $index->filesReferencing('route::POST::/posts'));
    }

    #[Test]
    public function an_unavailable_router_degrades_to_an_empty_index(): void
    {
        // The same accepted degradation FrontendTestIndex has: no router, no nodes,
        // never an exception out of an advisory lane.
        Route::swap(new class {
            public function getRoutes(): never
            {
                throw new RuntimeException('router unavailable');
            }
        });

        $index = new FrontendConsumerIndex();
        $index->addSource("axios.post('/posts');", 'resources/js/Pages/Posts/Create.vue');

        $this->assertSame([], $index->filesReferencing('route::POST::/posts'));
    }

    #[Test]
    public function an_unconfigured_project_yields_an_empty_index(): void
    {
        config()->set('richter.frontend.roots', []);
        mkdir($this->projectRoot, recursive: true);

        $index = FrontendConsumerIndex::fromProject($this->projectRoot);

        $this->assertSame([], $index->filesReferencing('route::POST::/posts'));
    }
}
