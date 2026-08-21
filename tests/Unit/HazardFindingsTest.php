<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Analysis\Hazard;
use SanderMuller\Richter\Analysis\HazardFindings;
use SanderMuller\Richter\Changes\ChangedFileSymbols;
use SanderMuller\Richter\Tests\TestCase;

final class HazardFindingsTest extends TestCase
{
    /**
     * @param  list<Hazard>  $hazards
     * @param  list<string>  $addedTokens
     */
    private function file(string $path, array $hazards = [], array $addedTokens = []): ChangedFileSymbols
    {
        return new ChangedFileSymbols($path, 'App\\' . basename($path, '.php'), [], cosmeticOnly: false, hazards: $hazards, addedHazardTokens: $addedTokens);
    }

    private function guardRemoval(string $member, string $token): Hazard
    {
        return new Hazard('auth', 3, 'CWE-862', $member, "the authorization check `{$token}` is gone from the body", $token);
    }

    #[Test]
    public function a_guard_that_moved_to_another_file_in_the_same_diff_is_not_a_removal(): void
    {
        // The controller's `authorize()` became the form request's. A per-file lane would call that a
        // removal, and it would be wrong most of the time — which is why this family is two-pass.
        $hazards = HazardFindings::for([
            $this->file('app/Http/Controllers/PostController.php', [$this->guardRemoval('App\Http\Controllers\PostController::update', 'ability:update')]),
            $this->file('app/Http/Requests/UpdatePostRequest.php', addedTokens: ['ability:update']),
        ], enabledOverride: true);

        $this->assertSame([], $hazards);
    }

    #[Test]
    public function a_guard_that_went_nowhere_survives_the_guard(): void
    {
        $hazards = HazardFindings::for([
            $this->file('app/Http/Controllers/PostController.php', [$this->guardRemoval('App\Http\Controllers\PostController::update', 'ability:update')]),
            $this->file('app/Http/Requests/UpdatePostRequest.php', addedTokens: ['ability:publish']),
        ], enabledOverride: true);

        $this->assertCount(1, $hazards);
    }

    #[Test]
    public function a_hazard_naming_nothing_is_never_suppressed_by_an_unrelated_arrival(): void
    {
        // A body reduced to `return true;` names no ability, so nothing elsewhere in the diff can be
        // that same guard arriving. An empty token must never match.
        $neutered = new Hazard('auth', 3, 'CWE-862', 'App\Http\Requests\UpdatePostRequest::authorize', 'the body is now exactly `return true;`, where it was not');

        $hazards = HazardFindings::for([
            $this->file('app/Http/Requests/UpdatePostRequest.php', [$neutered]),
            $this->file('app/Http/Controllers/PostController.php', addedTokens: ['ability:update', '']),
        ], enabledOverride: true);

        $this->assertCount(1, $hazards);
    }

    #[Test]
    public function the_worst_tier_reads_first_and_the_order_is_stable(): void
    {
        $one = new Hazard('contract', 1, null, 'App\A::a', 'signature');
        $two = new Hazard('parity', 2, null, 'App\B::b', 'payload');
        $three = $this->guardRemoval('App\C::c', 'ability:x');

        $hazards = HazardFindings::for([$this->file('app/A.php', [$one, $two, $three])], enabledOverride: true);

        $this->assertSame([3, 2, 1], array_column($hazards, 'tier'));
    }

    #[Test]
    public function disabling_the_family_silences_the_lanes_but_not_the_parity_hazards(): void
    {
        // `--no-hazards` gates the lanes this family owns. The parity results keep their own
        // `payload_parity` key, which is why they are passed in separately and stay.
        $parity = new Hazard('parity', 2, null, 'App\Models\Post', 'a field never reached its resource');

        $hazards = HazardFindings::for(
            [$this->file('app/Http/Controllers/PostController.php', [$this->guardRemoval('App\Http\Controllers\PostController::update', 'ability:update')])],
            enabledOverride: false,
            also: [$parity],
        );

        $this->assertSame(['parity'], array_column($hazards, 'lane'));
    }

    #[Test]
    public function an_ignored_member_is_suppressed_by_config(): void
    {
        config()->set('richter.hazards.ignore', ['App\Http\Controllers\PostController::update']);

        $hazards = HazardFindings::for([
            $this->file('app/Http/Controllers/PostController.php', [$this->guardRemoval('App\Http\Controllers\PostController::update', 'ability:update')]),
        ], enabledOverride: true);

        $this->assertSame([], $hazards);
    }

    #[Test]
    public function a_lane_may_name_a_narrower_ignore_key_than_its_member(): void
    {
        // A model's `$fillable` is ignored as `App\Models\Post::$fillable`, not by silencing every
        // hazard the model could ever carry.
        $hazard = new Hazard('model', 2, 'CWE-915', 'App\Models\Post::$fillable', 'gained status', ignoreKey: 'App\Models\Post::$fillable');
        config()->set('richter.hazards.ignore', ['App\Models\Post::$fillable']);

        $this->assertSame([], HazardFindings::for([$this->file('app/Models/Post.php', [$hazard])], enabledOverride: true));
    }
}
