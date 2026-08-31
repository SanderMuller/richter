<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Support\EntryPointKeepSet;
use SanderMuller\Richter\Tests\TestCase;

/**
 * The ownership half of `specs/task-slice.md`: which reached surfaces the task owns, and which are
 * fan-out through a file the project calls a hub.
 */
final class EntryPointKeepSetTest extends TestCase
{
    private const string HUB_REACHED = 'App\Filament\Resources\ArticleResource';

    private const string OWN_ROUTE = 'route::GET::/stats/{article}';

    private const string UNATTRIBUTED = 'App\Livewire\Standalone';

    /** @var list<string> */
    private const array HUB_PATHS = ['app/Models/Article.php'];

    /** @return array<string, array{via: string, ownReach: int}> */
    private function attribution(): array
    {
        return [
            self::HUB_REACHED => ['via' => 'app/Models/Article.php', 'ownReach' => 90],
            self::OWN_ROUTE => ['via' => 'app/Http/Controllers/StatsController.php', 'ownReach' => 9],
        ];
    }

    #[Test]
    public function with_no_hub_configured_every_surface_is_kept(): void
    {
        $entryPoints = [self::HUB_REACHED, self::OWN_ROUTE, self::UNATTRIBUTED];

        $set = EntryPointKeepSet::for($entryPoints, $this->attribution(), [], ['app/Models/Article.php' => 1], [], []);

        $this->assertSame($entryPoints, $set->kept);
        $this->assertSame(0, $set->droppedHub);
    }

    #[Test]
    public function a_surface_reached_only_through_a_hub_is_dropped(): void
    {
        $set = EntryPointKeepSet::for(
            [self::HUB_REACHED, self::OWN_ROUTE],
            $this->attribution(),
            [],
            ['app/Models/Article.php' => 1, 'app/Http/Controllers/StatsController.php' => 1],
            self::HUB_PATHS,
            [],
        );

        $this->assertSame([self::OWN_ROUTE], $set->kept);
        $this->assertSame(1, $set->droppedHub);
    }

    #[Test]
    public function a_hub_configured_but_matching_nothing_drops_nothing(): void
    {
        $entryPoints = [self::HUB_REACHED, self::OWN_ROUTE];

        $set = EntryPointKeepSet::for($entryPoints, $this->attribution(), [], [], ['app/Models/Comment.php'], []);

        $this->assertSame($entryPoints, $set->kept);
        $this->assertSame(0, $set->droppedHub);
    }

    #[Test]
    public function a_surface_whose_own_file_is_in_the_diff_outranks_a_hub_prefix(): void
    {
        // You edited that resource. You did not merely touch the model behind it.
        $set = EntryPointKeepSet::for(
            [self::HUB_REACHED],
            $this->attribution(),
            [self::HUB_REACHED => ['file' => 'app/Filament/Resources/ArticleResource.php', 'line' => 12]],
            ['app/Filament/Resources/ArticleResource.php' => 1],
            self::HUB_PATHS,
            ['app/Filament/'],
        );

        $this->assertSame([self::HUB_REACHED], $set->kept);
        $this->assertSame(0, $set->droppedHub);
    }

    #[Test]
    public function an_unattributed_surface_is_kept(): void
    {
        // AssociationSurfaces' rule, for the same reason: absence of a reason is not evidence of a
        // weak one, and hiding what the walk could not classify is the wrong direction to fail.
        $set = EntryPointKeepSet::for([self::UNATTRIBUTED], [], [], [], self::HUB_PATHS, ['app/Filament/']);

        $this->assertSame([self::UNATTRIBUTED], $set->kept);
        $this->assertSame(0, $set->droppedHub);
    }

    #[Test]
    public function a_surface_with_neither_attribution_nor_location_is_kept(): void
    {
        $set = EntryPointKeepSet::for(['route::GET::/orphan'], [], [], [], self::HUB_PATHS, ['app/Filament/']);

        $this->assertSame(['route::GET::/orphan'], $set->kept);
    }

    #[Test]
    public function a_route_a_changed_frontend_file_references_is_kept(): void
    {
        // The case that makes the unattributed rule load-bearing rather than tidy: a frontend surface
        // carries no attribution AND its route file is not in the diff, so any rule dropping the
        // unexplained would drop exactly what a frontend change owns.
        $route = 'route::POST::/api/articles';

        $set = EntryPointKeepSet::for(
            [$route],
            [],
            [$route => ['file' => 'routes/api.php']],
            ['resources/js/Pages/Articles/Edit.vue' => 1],
            self::HUB_PATHS,
            ['app/Filament/'],
        );

        $this->assertSame([$route], $set->kept);
        $this->assertSame(0, $set->droppedHub);
    }

    #[Test]
    public function a_leading_dot_slash_means_the_same_file_on_both_sides(): void
    {
        // Asymmetric on purpose. With `./` on both sides the comparison succeeds whether or not
        // anything normalises, so the test would pass on an implementation that does not.
        $prefixed = [self::HUB_REACHED => ['via' => './app/Models/Article.php', 'ownReach' => 90]];
        $bare = [self::HUB_REACHED => ['via' => 'app/Models/Article.php', 'ownReach' => 90]];

        $this->assertSame(1, EntryPointKeepSet::for([self::HUB_REACHED], $prefixed, [], [], ['app/Models/Article.php'], [])->droppedHub);
        $this->assertSame(1, EntryPointKeepSet::for([self::HUB_REACHED], $bare, [], [], ['./app/Models/Article.php'], [])->droppedHub);
        $this->assertSame(1, EntryPointKeepSet::for([self::HUB_REACHED], $prefixed, [], [], [], ['./app/Models/'])->droppedHub);
    }

    #[Test]
    public function a_defining_file_matches_across_a_dot_slash_too(): void
    {
        $set = EntryPointKeepSet::for(
            [self::HUB_REACHED],
            $this->attribution(),
            [self::HUB_REACHED => ['file' => './app/Filament/Resources/ArticleResource.php']],
            ['app/Filament/Resources/ArticleResource.php' => 1],
            self::HUB_PATHS,
            [],
        );

        $this->assertSame([self::HUB_REACHED], $set->kept);
    }

    #[Test]
    public function a_prefix_is_compared_as_written(): void
    {
        // `app/Models/` does not match `app/ModelsArchive/…`; `app/Models` would. A project that means
        // a directory writes the trailing slash.
        $surface = 'App\Filament\Resources\ArchiveResource';
        $attribution = [$surface => ['via' => 'app/ModelsArchive/Article.php', 'ownReach' => 4]];

        $this->assertSame([$surface], EntryPointKeepSet::for([$surface], $attribution, [], [], [], ['app/Models/'])->kept);
        $this->assertSame([], EntryPointKeepSet::for([$surface], $attribution, [], [], [], ['app/Models'])->kept);
    }

    #[Test]
    public function the_kept_order_is_the_order_it_was_given(): void
    {
        $entryPoints = [self::OWN_ROUTE, self::UNATTRIBUTED, self::HUB_REACHED];

        $set = EntryPointKeepSet::for($entryPoints, $this->attribution(), [], [], self::HUB_PATHS, []);

        $this->assertSame([self::OWN_ROUTE, self::UNATTRIBUTED], $set->kept);
    }
}
