<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Support\InheritanceSurfaces;
use SanderMuller\Richter\Tests\TestCase;

/**
 * The inheritance-reach split: which entries stay in front of the reader, and how the rest group.
 *
 * Two of the four rules cannot fire against a payload the analyzer built — an entry always carries a
 * reason, and an `override` entry is always a member node. They are tested anyway, because they are the
 * reason an unexpected shape is SHOWN rather than dropped, and a guard nothing exercises is a guard that
 * gets deleted as dead code.
 */
final class InheritanceSurfacesTest extends TestCase
{
    #[Test]
    public function a_trait_user_stays_in_front_of_the_reader_and_an_override_groups(): void
    {
        [$inline, $groups] = InheritanceSurfaces::partition(
            ['App\Models\Post', 'App\Jobs\Archive::handle'],
            ['App\Models\Post' => ['uses-trait'], 'App\Jobs\Archive::handle' => ['override']],
        );

        $this->assertSame(['App\Models\Post'], $inline);
        $this->assertSame(['handle' => ['App\Jobs\Archive']], $groups);
    }

    #[Test]
    public function the_strongest_signal_wins_when_both_lanes_reached_one_entry(): void
    {
        // The INVERSE of the association fold's weakest-link rule, and deliberately so: one `uses-trait`
        // reason means the changed method is copied into this class, whatever else also reached it.
        [$inline, $groups] = InheritanceSurfaces::partition(
            ['App\Models\Post::save'],
            ['App\Models\Post::save' => ['override', 'uses-trait']],
        );

        $this->assertSame(['App\Models\Post::save'], $inline);
        $this->assertSame([], $groups);
    }

    #[Test]
    public function every_class_declaring_the_same_member_name_lands_in_one_group(): void
    {
        [$inline, $groups] = InheritanceSurfaces::partition(
            ['App\Jobs\Archive::handle', 'App\Jobs\Digest::handle', 'App\Resources\Post::toArray'],
            [
                'App\Jobs\Archive::handle' => ['override'],
                'App\Jobs\Digest::handle' => ['override'],
                'App\Resources\Post::toArray' => ['override'],
            ],
        );

        $this->assertSame([], $inline);
        $this->assertSame([
            'handle' => ['App\Jobs\Archive', 'App\Jobs\Digest'],
            'toArray' => ['App\Resources\Post'],
        ], $groups);
    }

    #[Test]
    public function group_keys_are_sorted_by_member_name_not_by_the_class_that_arrived_first(): void
    {
        // The incoming list is sorted by full node id, which orders by CLASS name. Without the explicit
        // sort here the member names come out in the order their first class happened to land — and all
        // three formats would inherit that accident. `assertSame` on the keys is what pins it: an
        // equality assertion on the whole map would pass in either order.
        [, $groups] = InheritanceSurfaces::partition(
            ['App\Aaa\One::zeta', 'App\Bbb\Two::alpha'],
            ['App\Aaa\One::zeta' => ['override'], 'App\Bbb\Two::alpha' => ['override']],
        );

        $this->assertSame(['alpha', 'zeta'], array_keys($groups));
    }

    #[Test]
    public function an_entry_with_no_recorded_reason_is_shown_rather_than_folded(): void
    {
        // Unreachable from an analyzer-built payload — `uncountedReachVia()` guarantees a non-empty
        // reason set. The guard exists so a shape the partition did not expect fails toward the reader.
        [$inline, $groups] = InheritanceSurfaces::partition(
            ['App\Jobs\Archive::handle'],
            [],
        );

        $this->assertSame(['App\Jobs\Archive::handle'], $inline);
        $this->assertSame([], $groups);
    }

    #[Test]
    public function an_override_entry_that_is_not_a_member_node_is_shown_rather_than_folded(): void
    {
        // Also unreachable: `overrideEdges()` always writes `Class::method`. Same fail-toward-showing
        // reasoning. The trailing-`::` case is here because `substr()` returns an empty member for it,
        // which would otherwise group everything under a blank name.
        [$inline, $groups] = InheritanceSurfaces::partition(
            ['App\Models\Post', 'App\Models\Comment::'],
            ['App\Models\Post' => ['override'], 'App\Models\Comment::' => ['override']],
        );

        $this->assertSame(['App\Models\Post', 'App\Models\Comment::'], $inline);
        $this->assertSame([], $groups);
    }

    #[Test]
    public function a_member_node_reached_by_some_other_edge_type_is_shown_rather_than_folded(): void
    {
        // Grouping is gated on the `override` reason itself, not on "not a trait". Only that edge type
        // proves the entry declares the member, so an entry routed here under any other reason must not
        // inherit the fold's claim — it would be a sentence that is not true of it.
        [$inline, $groups] = InheritanceSurfaces::partition(
            ['App\Models\Post::save'],
            ['App\Models\Post::save' => ['declares']],
        );

        $this->assertSame(['App\Models\Post::save'], $inline);
        $this->assertSame([], $groups);
    }

    #[Test]
    public function a_member_node_keeps_only_its_last_separator_as_the_split(): void
    {
        // Node ids are `Class::member`; a class name cannot contain `::`, but splitting on the FIRST
        // separator instead of the last would silently mangle any id that ever carries two.
        [$inline, $groups] = InheritanceSurfaces::partition(
            ['App\Models\Post::scope::run'],
            ['App\Models\Post::scope::run' => ['override']],
        );

        $this->assertSame([], $inline);
        $this->assertSame(['run' => ['App\Models\Post::scope']], $groups);
    }

    #[Test]
    public function an_empty_section_partitions_to_nothing(): void
    {
        $this->assertSame([[], []], InheritanceSurfaces::partition([], []));
    }
}
