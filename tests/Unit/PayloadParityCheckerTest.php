<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use Illuminate\Filesystem\Filesystem;
use Iterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Analysis\PayloadParityChecker;
use SanderMuller\Richter\Graph\CodeGraph;
use SanderMuller\Richter\Tests\TestCase;

final class PayloadParityCheckerTest extends TestCase
{
    private const string MODEL = 'App\\Models\\Post';

    private string $projectRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->projectRoot = sys_get_temp_dir() . '/richter-payload-parity-' . bin2hex(random_bytes(8));
        mkdir($this->projectRoot . '/app/Http/Resources/Api/Post', recursive: true);
    }

    protected function tearDown(): void
    {
        new Filesystem()->deleteDirectory($this->projectRoot);

        parent::tearDown();
    }

    private function putResource(string $relativePath, string $body): void
    {
        $absolute = "{$this->projectRoot}/{$relativePath}";
        @mkdir(dirname($absolute), recursive: true);
        file_put_contents($absolute, $body);
    }

    /** A graph wiring the model's `reviews` relation to a controller method that references a resource. */
    private function wiredGraph(string $resourceFqcn): CodeGraph
    {
        return new CodeGraph([
            ['source' => 'App\Http\Controllers\Post\ReviewController::show', 'target' => self::MODEL . '::reviews', 'type' => 'loads-relation'],
            ['source' => 'App\Http\Controllers\Post\ReviewController::show', 'target' => $resourceFqcn, 'type' => 'resource'],
        ], hasUnparseableFiles: false, nodeMetadata: [
            $resourceFqcn => ['file' => 'app/Http/Resources/Api/Post/ReviewResource.php'],
        ]);
    }

    /** @param  list<string>  $ignore */
    private function checker(CodeGraph $graph, float $threshold = 1.0, array $ignore = []): PayloadParityChecker
    {
        return new PayloadParityChecker($graph, $threshold, $ignore, $this->projectRoot);
    }

    #[Test]
    public function a_wired_mirror_resource_missing_an_added_field_is_flagged(): void
    {
        $resourceFqcn = 'App\Http\Resources\Api\Post\ReviewResource';
        $this->putResource('app/Http/Resources/Api/Post/ReviewResource.php', <<<'PHP'
            <?php declare(strict_types=1);
            namespace App\Http\Resources\Api\Post;
            use Illuminate\Http\Resources\Json\JsonResource;
            final class ReviewResource extends JsonResource
            {
                public function toArray($request): array
                {
                    return ['title' => $this->title, 'slug' => $this->slug];
                }
            }
            PHP);

        $findings = $this->checker($this->wiredGraph($resourceFqcn))
            ->findingsFor(self::MODEL, ['title', 'slug', 'layout'], ['layout']);

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('layout', $findings[0]);
        $this->assertStringContainsString('ReviewResource.php', $findings[0]);
    }

    #[Test]
    public function a_narrow_control_resource_stays_silent(): void
    {
        $resourceFqcn = 'App\Http\Resources\Api\Post\ReviewResource';
        $this->putResource('app/Http/Resources/Api/Post/ReviewResource.php', <<<'PHP'
            <?php declare(strict_types=1);
            namespace App\Http\Resources\Api\Post;
            use Illuminate\Http\Resources\Json\JsonResource;
            final class ReviewResource extends JsonResource
            {
                public function toArray($request): array
                {
                    return ['nested' => 'something unrelated to the model fields'];
                }
            }
            PHP);

        $findings = $this->checker($this->wiredGraph($resourceFqcn))
            ->findingsFor(self::MODEL, ['title', 'slug', 'layout'], ['layout']);

        $this->assertSame([], $findings);
    }

    #[Test]
    public function a_resource_exposing_every_added_field_stays_silent(): void
    {
        $resourceFqcn = 'App\Http\Resources\Api\Post\ReviewResource';
        $this->putResource('app/Http/Resources/Api/Post/ReviewResource.php', <<<'PHP'
            <?php declare(strict_types=1);
            namespace App\Http\Resources\Api\Post;
            use Illuminate\Http\Resources\Json\JsonResource;
            final class ReviewResource extends JsonResource
            {
                public function toArray($request): array
                {
                    return ['title' => $this->title, 'slug' => $this->slug, 'layout' => $this->layout];
                }
            }
            PHP);

        $findings = $this->checker($this->wiredGraph($resourceFqcn))
            ->findingsFor(self::MODEL, ['title', 'slug', 'layout'], ['layout']);

        $this->assertSame([], $findings);
    }

    /** @return Iterator<int, array{string}> */
    public static function unknownKeyConstructs(): Iterator
    {
        yield ['return [...$base, \'title\' => $this->title];'];
        yield ['return array_merge($base, [\'title\' => $this->title]);'];
        yield ['return [...$this->mergeWhen(true, [\'title\' => $this->title])];'];
        yield ['return parent::toArray($request);'];
        yield ['return $this->only([\'title\']);'];
    }

    #[Test]
    #[DataProvider('unknownKeyConstructs')]
    public function an_unknown_key_construct_skips_the_whole_resource(string $returnStatement): void
    {
        $resourceFqcn = 'App\Http\Resources\Api\Post\ReviewResource';
        $this->putResource('app/Http/Resources/Api/Post/ReviewResource.php', <<<PHP
            <?php declare(strict_types=1);
            namespace App\Http\Resources\Api\Post;
            use Illuminate\Http\Resources\Json\JsonResource;
            final class ReviewResource extends JsonResource
            {
                public function toArray(\$request): array
                {
                    \$base = ['slug' => \$this->slug];

                    {$returnStatement}
                }
            }
            PHP);

        $findings = $this->checker($this->wiredGraph($resourceFqcn))
            ->findingsFor(self::MODEL, ['title', 'slug', 'layout'], ['layout']);

        $this->assertSame([], $findings);
    }

    #[Test]
    public function a_when_value_on_a_literal_key_is_counted_normally(): void
    {
        $resourceFqcn = 'App\Http\Resources\Api\Post\ReviewResource';
        $this->putResource('app/Http/Resources/Api/Post/ReviewResource.php', <<<'PHP'
            <?php declare(strict_types=1);
            namespace App\Http\Resources\Api\Post;
            use Illuminate\Http\Resources\Json\JsonResource;
            final class ReviewResource extends JsonResource
            {
                public function toArray($request): array
                {
                    return ['title' => $this->when(true, fn () => $this->title), 'slug' => $this->slug];
                }
            }
            PHP);

        $findings = $this->checker($this->wiredGraph($resourceFqcn))
            ->findingsFor(self::MODEL, ['title', 'slug', 'layout'], ['layout']);

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('layout', $findings[0]);
    }

    #[Test]
    public function a_foreign_class_constant_key_is_resolved_by_reflection(): void
    {
        // App\Models\Post::COMMENTS is a real, autoloaded constant ('comments') in the fixture project.
        $resourceFqcn = 'App\Http\Resources\Api\Post\ReviewResource';
        $this->putResource('app/Http/Resources/Api/Post/ReviewResource.php', <<<'PHP'
            <?php declare(strict_types=1);
            namespace App\Http\Resources\Api\Post;
            use App\Models\Post;
            use Illuminate\Http\Resources\Json\JsonResource;
            final class ReviewResource extends JsonResource
            {
                public function toArray($request): array
                {
                    return [Post::COMMENTS => $this->comments];
                }
            }
            PHP);

        $findings = $this->checker($this->wiredGraph($resourceFqcn))
            ->findingsFor(self::MODEL, ['comments', 'layout'], ['layout']);

        $this->assertCount(1, $findings);
    }

    #[Test]
    public function an_unloadable_resource_file_is_silently_skipped(): void
    {
        $resourceFqcn = 'App\Http\Resources\Api\Post\MissingResource';

        $graph = new CodeGraph([
            ['source' => 'App\Http\Controllers\Post\ReviewController::show', 'target' => self::MODEL . '::reviews', 'type' => 'loads-relation'],
            ['source' => 'App\Http\Controllers\Post\ReviewController::show', 'target' => $resourceFqcn, 'type' => 'resource'],
        ], hasUnparseableFiles: false, nodeMetadata: [
            $resourceFqcn => ['file' => 'app/Http/Resources/Api/Post/MissingResource.php'],
        ]);

        $findings = $this->checker($graph)->findingsFor(self::MODEL, ['title', 'slug', 'layout'], ['layout']);

        $this->assertSame([], $findings);
    }

    #[Test]
    public function a_resource_with_no_known_location_is_silently_skipped(): void
    {
        // No nodeMetadata at all — locationOf() returns null, so the candidate is dropped before ever
        // reading a file.
        $graph = new CodeGraph([
            ['source' => 'App\Http\Controllers\Post\ReviewController::show', 'target' => self::MODEL . '::reviews', 'type' => 'loads-relation'],
            ['source' => 'App\Http\Controllers\Post\ReviewController::show', 'target' => 'App\Http\Resources\Api\Post\ReviewResource', 'type' => 'resource'],
        ], hasUnparseableFiles: false);

        $findings = $this->checker($graph)->findingsFor(self::MODEL, ['title', 'slug', 'layout'], ['layout']);

        $this->assertSame([], $findings);
    }

    #[Test]
    public function the_fallback_only_runs_when_the_graph_result_is_empty(): void
    {
        // A graph with no edges at all reaching the model — falls back to the FQCN-segment name scan.
        $graph = new CodeGraph([], hasUnparseableFiles: false);

        $this->putResource('app/Http/Resources/Api/Post/ReviewResource.php', <<<'PHP'
            <?php declare(strict_types=1);
            namespace App\Http\Resources\Api\Post;
            use Illuminate\Http\Resources\Json\JsonResource;
            final class ReviewResource extends JsonResource
            {
                public function toArray($request): array
                {
                    return ['title' => $this->title, 'slug' => $this->slug];
                }
            }
            PHP);
        $this->putResource('app/Http/Resources/Api/Post/PlayerResource.php', <<<'PHP'
            <?php declare(strict_types=1);
            namespace App\Http\Resources\Api\Post;
            use Illuminate\Http\Resources\Json\JsonResource;
            final class PlayerResource extends JsonResource
            {
                public function toArray($request): array
                {
                    return ['nested' => 'unrelated'];
                }
            }
            PHP);

        $findings = $this->checker($graph)->findingsFor(self::MODEL, ['title', 'slug', 'layout'], ['layout']);

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('ReviewResource.php', $findings[0]);
    }

    #[Test]
    public function a_non_empty_graph_result_never_falls_back_to_the_name_scan(): void
    {
        // The graph wires exactly one resource (ReviewResource), which exposes every added field —
        // it stays silent. LookalikeResource sits on disk under the same namespace, carries the
        // model's short name as a segment, and is missing the added field — the fallback would flag
        // it, but it was never wired by the graph and must not be consulted at all.
        $resourceFqcn = 'App\Http\Resources\Api\Post\ReviewResource';
        $this->putResource('app/Http/Resources/Api/Post/ReviewResource.php', <<<'PHP'
            <?php declare(strict_types=1);
            namespace App\Http\Resources\Api\Post;
            use Illuminate\Http\Resources\Json\JsonResource;
            final class ReviewResource extends JsonResource
            {
                public function toArray($request): array
                {
                    return ['title' => $this->title, 'slug' => $this->slug, 'layout' => $this->layout];
                }
            }
            PHP);
        $this->putResource('app/Http/Resources/Api/Post/LookalikeResource.php', <<<'PHP'
            <?php declare(strict_types=1);
            namespace App\Http\Resources\Api\Post;
            use Illuminate\Http\Resources\Json\JsonResource;
            final class LookalikeResource extends JsonResource
            {
                public function toArray($request): array
                {
                    return ['title' => $this->title, 'slug' => $this->slug];
                }
            }
            PHP);

        $findings = $this->checker($this->wiredGraph($resourceFqcn))
            ->findingsFor(self::MODEL, ['title', 'slug', 'layout'], ['layout']);

        $this->assertSame([], $findings);
    }

    #[Test]
    public function the_fallback_minimum_of_two_shared_fields_is_enforced(): void
    {
        // Only one pre-existing field ('slug'); the fallback path's minimum of 2 shared fields keeps
        // it silent even though the ratio (1/1) would otherwise clear the threshold.
        $graph = new CodeGraph([], hasUnparseableFiles: false);

        $this->putResource('app/Http/Resources/Api/Post/ReviewResource.php', <<<'PHP'
            <?php declare(strict_types=1);
            namespace App\Http\Resources\Api\Post;
            use Illuminate\Http\Resources\Json\JsonResource;
            final class ReviewResource extends JsonResource
            {
                public function toArray($request): array
                {
                    return ['slug' => $this->slug];
                }
            }
            PHP);

        $findings = $this->checker($graph)->findingsFor(self::MODEL, ['slug', 'layout'], ['layout']);

        $this->assertSame([], $findings);
    }

    #[Test]
    public function the_graph_wired_minimum_of_one_shared_field_still_fires(): void
    {
        $resourceFqcn = 'App\Http\Resources\Api\Post\ReviewResource';
        $this->putResource('app/Http/Resources/Api/Post/ReviewResource.php', <<<'PHP'
            <?php declare(strict_types=1);
            namespace App\Http\Resources\Api\Post;
            use Illuminate\Http\Resources\Json\JsonResource;
            final class ReviewResource extends JsonResource
            {
                public function toArray($request): array
                {
                    return ['slug' => $this->slug];
                }
            }
            PHP);

        $findings = $this->checker($this->wiredGraph($resourceFqcn))
            ->findingsFor(self::MODEL, ['slug', 'layout'], ['layout']);

        $this->assertCount(1, $findings);
    }

    #[Test]
    public function a_model_with_only_the_added_field_stays_silent(): void
    {
        $graph = new CodeGraph([], hasUnparseableFiles: false);

        $findings = $this->checker($graph)->findingsFor(self::MODEL, ['layout'], ['layout']);

        $this->assertSame([], $findings);
    }

    #[Test]
    public function an_ignored_field_is_suppressed_and_excluded_from_the_denominator(): void
    {
        $resourceFqcn = 'App\Http\Resources\Api\Post\ReviewResource';
        $this->putResource('app/Http/Resources/Api/Post/ReviewResource.php', <<<'PHP'
            <?php declare(strict_types=1);
            namespace App\Http\Resources\Api\Post;
            use Illuminate\Http\Resources\Json\JsonResource;
            final class ReviewResource extends JsonResource
            {
                public function toArray($request): array
                {
                    return ['slug' => $this->slug];
                }
            }
            PHP);

        $findings = $this->checker($this->wiredGraph($resourceFqcn), ignore: [self::MODEL . '::layout'])
            ->findingsFor(self::MODEL, ['slug', 'layout'], ['layout']);

        $this->assertSame([], $findings);
    }

    #[Test]
    public function an_ignored_resource_fqcn_is_suppressed(): void
    {
        $resourceFqcn = 'App\Http\Resources\Api\Post\ReviewResource';
        $this->putResource('app/Http/Resources/Api/Post/ReviewResource.php', <<<'PHP'
            <?php declare(strict_types=1);
            namespace App\Http\Resources\Api\Post;
            use Illuminate\Http\Resources\Json\JsonResource;
            final class ReviewResource extends JsonResource
            {
                public function toArray($request): array
                {
                    return ['title' => $this->title, 'slug' => $this->slug];
                }
            }
            PHP);

        $findings = $this->checker($this->wiredGraph($resourceFqcn), ignore: [$resourceFqcn])
            ->findingsFor(self::MODEL, ['title', 'slug', 'layout'], ['layout']);

        $this->assertSame([], $findings);
    }

    #[Test]
    public function no_resources_and_no_resource_edges_stays_silent(): void
    {
        $graph = new CodeGraph([], hasUnparseableFiles: false);

        $findings = $this->checker($graph)->findingsFor(self::MODEL, ['title', 'slug', 'layout'], ['layout']);

        $this->assertSame([], $findings);
    }
}
