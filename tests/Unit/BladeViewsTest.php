<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Graph\BladeViews;
use SanderMuller\Richter\Tests\TestCase;

final class BladeViewsTest extends TestCase
{
    /**
     * Guards the one rule coupled to Laravel Brain's view node id format: hyphens fold to `_`, dots
     * are kept, and the `blade__` prefix is carried. A drift here means tracer edges miss Brain's nodes.
     */
    #[Test]
    public function it_mirrors_brains_view_node_id_format(): void
    {
        $this->assertSame('view::blade__dashboard.home.video_item', BladeViews::nodeId('dashboard.home.video-item'));

        $this->assertSame('view::blade__components.video_dashboard.video_action_buttons', BladeViews::nodeId('components.video-dashboard.video-action-buttons'));
    }

    #[Test]
    public function it_derives_the_dotted_view_name_only_for_resources_views_blade_files(): void
    {
        $this->assertSame('dashboard.home.video-item', BladeViews::viewNameFromPath('resources/views/dashboard/home/video-item.blade.php'));
        $this->assertNull(BladeViews::viewNameFromPath('app/Http/Controllers/Video/DashboardSearchController.php'));
        $this->assertNull(BladeViews::viewNameFromPath('resources/js/app.ts'));
    }

    #[Test]
    public function it_seeds_a_changed_blade_file_with_its_view_node_id(): void
    {
        $this->assertSame('view::blade__components.video_dashboard.video_action_buttons', BladeViews::seedForChangedFile('resources/views/components/video-dashboard/video-action-buttons.blade.php'));
        $this->assertNull(BladeViews::seedForChangedFile('routes/web.php'));
    }
}
