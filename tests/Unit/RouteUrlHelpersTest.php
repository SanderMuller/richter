<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Graph\TracerNodeCollector;
use SanderMuller\Richter\Support\AppFiles;
use SanderMuller\Richter\Tests\TestCase;
use SanderMuller\Richter\Tracers\RouteUrlHelpers;

final class RouteUrlHelpersTest extends TestCase
{
    #[Test]
    public function it_maps_each_method_that_returns_a_named_route_url_to_its_routes(): void
    {
        $helpers = $this->helpersOf(<<<'PHP'
        <?php
        namespace App\Models;
        use Illuminate\Support\Facades\URL;
        use Illuminate\Support\Uri;
        final class Post
        {
            public function editUrl(): string { return route('posts.edit', $this); }
            public function shareUrl(): string { return Uri::route('posts.share', ['post' => $this])->value(); }
            public function previewUrl(bool $draft): string
            {
                if ($draft) {
                    return URL::temporarySignedRoute('posts.preview.draft', now()->addHour());
                }

                return URL::signedRoute('posts.preview');
            }
        }
        PHP);

        $this->assertSame([
            'editUrl' => ['posts.edit'],
            'previewUrl' => ['posts.preview', 'posts.preview.draft'],
            'shareUrl' => ['posts.share'],
        ], $helpers);
    }

    #[Test]
    public function a_redirect_or_a_name_built_at_runtime_is_no_helper(): void
    {
        $helpers = $this->helpersOf(<<<'PHP'
        <?php
        namespace App\Http\Controllers;
        final class PostController
        {
            public function store() { return redirect()->route('posts.index'); }
            public function update() { return to_route('posts.index'); }
            public function linkFor(string $name): string { return route($name); }
            public function remember(): void { session()->put('back', route('posts.index')); }
        }
        PHP);

        $this->assertSame([], $helpers);
    }

    #[Test]
    public function a_name_another_app_method_declares_is_left_out(): void
    {
        $helpers = $this->helpersOf(
            "<?php namespace App\\Models; final class Post { public function url(): string { return route('posts.show', \$this); } public function editUrl(): string { return route('posts.edit', \$this); } }",
            "<?php namespace App\\Support; final class Asset { public function url(): string { return '/asset'; } }",
            // A method without a body declares the name but runs nothing, so it does not count.
            '<?php namespace App\\Contracts; interface HasEditUrl { public function editUrl(): string; }',
        );

        $this->assertSame(['editUrl' => ['posts.edit']], $helpers);
    }

    #[Test]
    public function a_payload_must_map_method_names_to_route_name_lists(): void
    {
        $this->assertSame(['editUrl' => ['posts.edit']], RouteUrlHelpers::fromPayload(['editUrl' => ['posts.edit']]));
        $this->assertNull(RouteUrlHelpers::fromPayload(null));
        $this->assertNull(RouteUrlHelpers::fromPayload(['editUrl' => 'posts.edit']));
        $this->assertNull(RouteUrlHelpers::fromPayload(['editUrl' => [1]]));
    }

    /** @return array<string, list<string>> */
    private function helpersOf(string ...$sources): array
    {
        $helpers = new RouteUrlHelpers();

        foreach ($sources as $source) {
            $collector = new TracerNodeCollector();
            AppFiles::parseResolved($source, $collector);
            $helpers->collect($collector->classMethods);
        }

        return $helpers->helpers();
    }
}
