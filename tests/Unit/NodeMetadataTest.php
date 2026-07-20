<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Graph\NodeMetadata;
use SanderMuller\Richter\Tests\TestCase;

final class NodeMetadataTest extends TestCase
{
    #[Test]
    public function a_brain_data_bag_yields_a_sparse_project_relative_record(): void
    {
        $metadata = NodeMetadata::fromBrainNodeData([
            'file' => '/srv/app/routes/web.php',
            'line' => 12,
            'uri' => '/posts/{post}/publish',
            'method' => 'POST',
        ], '/srv/app');

        $this->assertSame(['file' => 'routes/web.php', 'line' => 12, 'uri' => '/posts/{post}/publish'], $metadata);
    }

    #[Test]
    public function a_bag_with_nothing_worth_keeping_yields_null(): void
    {
        $this->assertNull(NodeMetadata::fromBrainNodeData(['fqcn' => 'App\Models\Post', 'method' => 'query'], '/srv/app'));
        $this->assertNull(NodeMetadata::fromBrainNodeData(['file' => '', 'line' => 0, 'uri' => ''], '/srv/app'));
    }

    #[Test]
    public function a_file_outside_the_project_root_stays_verbatim(): void
    {
        $metadata = NodeMetadata::fromBrainNodeData(['file' => '/elsewhere/thing.php'], '/srv/app');

        $this->assertSame(['file' => '/elsewhere/thing.php'], $metadata);
    }

    #[Test]
    public function an_empty_root_keeps_every_path_verbatim(): void
    {
        // The cache-revalidation contract: '' as root re-shapes a stored record without touching
        // its paths — an absolute outside-root path must not lose its leading slash.
        $metadata = NodeMetadata::fromBrainNodeData(['file' => '/tmp/foo.php', 'line' => 3], '');

        $this->assertSame(['file' => '/tmp/foo.php', 'line' => 3], $metadata);
    }

    #[Test]
    public function a_security_surface_is_kept_with_relative_issue_files(): void
    {
        $metadata = NodeMetadata::fromBrainNodeData([
            'security' => [
                'exposure' => 'public',
                'riskLevel' => 'high',
                'issues' => [
                    ['type' => 'PUBLIC_WRITE', 'severity' => 'high', 'message' => 'POST route with no auth middleware', 'file' => '/srv/app/app/Http/Controllers/X.php', 'line' => 42],
                ],
            ],
        ], '/srv/app');

        $this->assertSame([
            'security' => [
                'exposure' => 'public',
                'riskLevel' => 'high',
                'issues' => [
                    ['type' => 'PUBLIC_WRITE', 'severity' => 'high', 'message' => 'POST route with no auth middleware', 'file' => 'app/Http/Controllers/X.php', 'line' => 42],
                ],
            ],
        ], $metadata);
    }

    #[Test]
    public function a_malformed_security_surface_drops_without_taking_the_location_with_it(): void
    {
        $metadata = NodeMetadata::fromBrainNodeData([
            'file' => '/srv/app/routes/web.php',
            'security' => ['exposure' => 42, 'riskLevel' => 'high', 'issues' => []],
        ], '/srv/app');

        $this->assertSame(['file' => 'routes/web.php'], $metadata);
    }

    #[Test]
    public function a_malformed_issue_is_skipped_while_valid_siblings_survive(): void
    {
        $metadata = NodeMetadata::fromBrainNodeData([
            'security' => [
                'exposure' => 'authed',
                'riskLevel' => 'low',
                'issues' => [
                    ['type' => 'MASS_ASSIGNMENT', 'severity' => 'medium'], // message missing
                    ['type' => 'MISSING_THROTTLE', 'severity' => 'medium', 'message' => 'login route missing throttle'],
                ],
            ],
        ], '/srv/app');

        $this->assertSame(
            [['type' => 'MISSING_THROTTLE', 'severity' => 'medium', 'message' => 'login route missing throttle']],
            $metadata['security']['issues'] ?? null,
        );
    }

    #[Test]
    public function merge_keeps_the_first_value_per_field_and_fills_absent_ones(): void
    {
        $merged = NodeMetadata::merge(
            ['file' => 'app/Models/Post.php'],
            ['file' => 'other.php', 'line' => 7, 'uri' => '/posts'],
        );

        $this->assertSame(['file' => 'app/Models/Post.php', 'line' => 7, 'uri' => '/posts'], $merged);
    }

    #[Test]
    public function remap_keys_follows_a_rename_and_merges_on_collision(): void
    {
        $remapped = NodeMetadata::remapKeys(
            [
                'action::ReviewController::edit' => ['line' => 3],
                'App\Http\Controllers\ReviewController::edit' => ['file' => 'app/Http/Controllers/ReviewController.php'],
            ],
            static fn (string $node): string => $node === 'action::ReviewController::edit'
                ? 'App\Http\Controllers\ReviewController::edit'
                : $node,
        );

        $this->assertSame(
            ['App\Http\Controllers\ReviewController::edit' => ['line' => 3, 'file' => 'app/Http/Controllers/ReviewController.php']],
            $remapped,
        );
    }

    #[Test]
    public function fallback_files_derive_from_the_fqcn_only_when_the_file_exists(): void
    {
        $metadata = NodeMetadata::withFallbackFiles(
            [
                ['source' => 'App\Models\Post', 'target' => 'App\Models\Nonexistent', 'type' => 'model-relationship'],
                ['source' => 'App\Models\Post::publish', 'target' => 'view::blade__posts.show', 'type' => 'action-to-view'],
            ],
            [],
            self::fixtureProjectPath(),
        );

        // The class and its member node both resolve to the existing fixture file…
        $this->assertSame('app/Models/Post.php', $metadata['App\Models\Post']['file'] ?? null);
        $this->assertSame('app/Models/Post.php', $metadata['App\Models\Post::publish']['file'] ?? null);
        // …while a missing file and a non-App node stay unannotated rather than guessed.
        $this->assertArrayNotHasKey('App\Models\Nonexistent', $metadata);
        $this->assertArrayNotHasKey('view::blade__posts.show', $metadata);
    }

    #[Test]
    public function route_gates_read_pennant_middleware_in_alias_and_fqcn_string_form(): void
    {
        $metadata = NodeMetadata::withRouteGates(
            [
                ['source' => 'route::POST::/posts/{post}/ai-coach', 'target' => 'middleware::features:ai-coach,beta', 'type' => 'route-to-middleware'],
                ['source' => 'route::GET::/labs', 'target' => 'middleware::Laravel\Pennant\Middleware\EnsureFeaturesAreActive:labs', 'type' => 'route-to-middleware'],
                ['source' => 'route::GET::/posts', 'target' => 'middleware::throttle:60,1', 'type' => 'route-to-middleware'],
                ['source' => 'App\Services\X', 'target' => 'middleware::features:not-a-route', 'type' => 'call'],
            ],
            [],
            ['features' => 'Laravel\Pennant\Middleware\EnsureFeaturesAreActive'],
        );

        $this->assertSame(['ai-coach', 'beta'], $metadata['route::POST::/posts/{post}/ai-coach']['gates'] ?? null);
        $this->assertSame(['labs'], $metadata['route::GET::/labs']['gates'] ?? null);
        // Parameterised non-Pennant middleware and non-route sources never gate.
        $this->assertArrayNotHasKey('route::GET::/posts', $metadata);
        $this->assertArrayNotHasKey('App\Services\X', $metadata);
    }

    #[Test]
    public function an_unrelated_middleware_sharing_the_basename_never_gates(): void
    {
        // A non-Pennant class that happens to be called EnsureFeaturesAreActive must not have its
        // parameters read as feature flags.
        $metadata = NodeMetadata::withRouteGates(
            [['source' => 'route::GET::/x', 'target' => 'middleware::App\Http\Middleware\EnsureFeaturesAreActive:tenant-1', 'type' => 'route-to-middleware']],
            [],
            [],
        );

        $this->assertSame([], $metadata);
    }

    #[Test]
    public function route_gates_merge_into_existing_metadata_without_disturbing_it(): void
    {
        $metadata = NodeMetadata::withRouteGates(
            [['source' => 'route::GET::/labs', 'target' => 'middleware::features:labs', 'type' => 'route-to-middleware']],
            ['route::GET::/labs' => ['file' => 'routes/web.php', 'line' => 9]],
            ['features' => 'Laravel\Pennant\Middleware\EnsureFeaturesAreActive'],
        );

        $this->assertSame(['file' => 'routes/web.php', 'line' => 9, 'gates' => ['labs']], $metadata['route::GET::/labs']);
    }

    #[Test]
    public function gates_survive_the_cache_revalidation_shape_gate(): void
    {
        $metadata = NodeMetadata::fromBrainNodeData(['file' => 'routes/web.php', 'gates' => ['ai-coach', 42, '']], '');

        // Non-string entries drop, real flags survive the round trip.
        $this->assertSame(['file' => 'routes/web.php', 'gates' => ['ai-coach']], $metadata);
    }

    #[Test]
    public function fallback_never_overwrites_an_existing_file(): void
    {
        $metadata = NodeMetadata::withFallbackFiles(
            [['source' => 'App\Models\Post', 'target' => 'App\Models\Review', 'type' => 'model-relationship']],
            ['App\Models\Post' => ['file' => 'routes/web.php', 'line' => 5]],
            self::fixtureProjectPath(),
        );

        $this->assertSame(['file' => 'routes/web.php', 'line' => 5], $metadata['App\Models\Post']);
    }
}
