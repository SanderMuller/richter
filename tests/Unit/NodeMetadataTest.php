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
            'uri' => '/videos/{video}/publish',
            'method' => 'POST',
        ], '/srv/app');

        $this->assertSame(['file' => 'routes/web.php', 'line' => 12, 'uri' => '/videos/{video}/publish'], $metadata);
    }

    #[Test]
    public function a_bag_with_nothing_worth_keeping_yields_null(): void
    {
        $this->assertNull(NodeMetadata::fromBrainNodeData(['fqcn' => 'App\Models\Video', 'method' => 'query'], '/srv/app'));
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
            ['file' => 'app/Models/Video.php'],
            ['file' => 'other.php', 'line' => 7, 'uri' => '/videos'],
        );

        $this->assertSame(['file' => 'app/Models/Video.php', 'line' => 7, 'uri' => '/videos'], $merged);
    }

    #[Test]
    public function remap_keys_follows_a_rename_and_merges_on_collision(): void
    {
        $remapped = NodeMetadata::remapKeys(
            [
                'action::QuestionController::edit' => ['line' => 3],
                'App\Http\Controllers\QuestionController::edit' => ['file' => 'app/Http/Controllers/QuestionController.php'],
            ],
            static fn (string $node): string => $node === 'action::QuestionController::edit'
                ? 'App\Http\Controllers\QuestionController::edit'
                : $node,
        );

        $this->assertSame(
            ['App\Http\Controllers\QuestionController::edit' => ['line' => 3, 'file' => 'app/Http/Controllers/QuestionController.php']],
            $remapped,
        );
    }

    #[Test]
    public function fallback_files_derive_from_the_fqcn_only_when_the_file_exists(): void
    {
        $metadata = NodeMetadata::withFallbackFiles(
            [
                ['source' => 'App\Models\Video', 'target' => 'App\Models\Nonexistent', 'type' => 'model-relationship'],
                ['source' => 'App\Models\Video::publish', 'target' => 'view::blade__videos.show', 'type' => 'action-to-view'],
            ],
            [],
            self::fixtureProjectPath(),
        );

        // The class and its member node both resolve to the existing fixture file…
        $this->assertSame('app/Models/Video.php', $metadata['App\Models\Video']['file'] ?? null);
        $this->assertSame('app/Models/Video.php', $metadata['App\Models\Video::publish']['file'] ?? null);
        // …while a missing file and a non-App node stay unannotated rather than guessed.
        $this->assertArrayNotHasKey('App\Models\Nonexistent', $metadata);
        $this->assertArrayNotHasKey('view::blade__videos.show', $metadata);
    }

    #[Test]
    public function fallback_never_overwrites_an_existing_file(): void
    {
        $metadata = NodeMetadata::withFallbackFiles(
            [['source' => 'App\Models\Video', 'target' => 'App\Models\Question', 'type' => 'model-relationship']],
            ['App\Models\Video' => ['file' => 'routes/web.php', 'line' => 5]],
            self::fixtureProjectPath(),
        );

        $this->assertSame(['file' => 'routes/web.php', 'line' => 5], $metadata['App\Models\Video']);
    }
}
