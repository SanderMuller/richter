<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Analysis\Hazard;
use SanderMuller\Richter\Analysis\HazardReach;
use SanderMuller\Richter\Graph\CodeGraph;
use SanderMuller\Richter\Tests\TestCase;

/**
 * The reach class a hazard carries. Two of the four states are findings — `public-write` and `gated`
 * — and two are admissions: `no-guard-found` and `no-known-path`. The tests below exist mostly to
 * hold that line, because the lane's first version let `gated` be a fallthrough and it silently
 * covered every surface Brain never classified.
 */
final class HazardReachTest extends TestCase
{
    private const string MEMBER = 'App\Services\Publisher::publish';

    private const string ROUTE = 'route::POST::/posts';

    /**
     * @param  array<string, array{exposure: string, riskLevel: string, issues: list<array{type: string, severity: string, message: string}>}>  $security
     * @param  array<string, list<string>>  $authGates
     * @param  array<string, list<string>>  $authMiddleware
     * @param  array<string, list<array{node: string, via: string}>>  $paths
     */
    private function reachOf(array $paths, array $security, array $authGates = [], array $authMiddleware = [], ?CodeGraph $graph = null): string
    {
        $hazards = new HazardReach(
            $graph ?? new CodeGraph([], hasUnparseableFiles: false),
            $paths,
            $security,
            $authGates,
            $authMiddleware,
            6,
        )->attach([new Hazard('contract', 2, null, self::MEMBER, 'the public method is gone')]);

        return (string) $hazards[0]->reach;
    }

    /**
     * A route reaching the changed member, as `entryPointPaths` shapes it: target-first, seed-last.
     *
     * @return array<string, list<array{node: string, via: string}>>
     */
    private function pathTo(string ...$entryPoints): array
    {
        $paths = [];

        foreach ($entryPoints as $entryPoint) {
            $paths[$entryPoint] = [
                ['node' => $entryPoint, 'via' => ''],
                ['node' => self::MEMBER, 'via' => 'action-to-service'],
            ];
        }

        return $paths;
    }

    /**
     * @param  list<string>  $issueTypes
     * @return array{exposure: string, riskLevel: string, issues: list<array{type: string, severity: string, message: string}>}
     */
    private function security(string $exposure, array $issueTypes = []): array
    {
        return [
            'exposure' => $exposure,
            'riskLevel' => 'low',
            'issues' => array_map(
                static fn (string $type): array => ['type' => $type, 'severity' => 'high', 'message' => 'x'],
                $issueTypes,
            ),
        ];
    }

    // ------------------------------------------------------------ findings

    #[Test]
    public function a_public_write_route_with_no_contradicting_guard_is_public_write(): void
    {
        $this->assertSame(Hazard::REACH_PUBLIC_WRITE, $this->reachOf(
            $this->pathTo(self::ROUTE),
            [self::ROUTE => $this->security('public', ['PUBLIC_WRITE'])],
        ));
    }

    #[Test]
    public function a_public_write_route_the_cross_check_contradicts_is_gated(): void
    {
        // Brain classifies exposure from the static middleware surface and misses an app subclass of
        // the framework's Authenticate. Where richter's own cross-check finds the guard, that evidence
        // wins over Brain's finding.
        $this->assertSame(Hazard::REACH_GATED, $this->reachOf(
            $this->pathTo(self::ROUTE),
            [self::ROUTE => $this->security('public', ['PUBLIC_WRITE'])],
            authMiddleware: [self::ROUTE => ['App\Http\Middleware\Authenticate']],
        ));
    }

    #[Test]
    public function an_authenticated_exposure_does_not_overturn_a_public_write_flag(): void
    {
        // Brain contradicting itself: a PUBLIC_WRITE issue on a route it also classified `authed`.
        // Exposure counts as a guard for the gated/no-guard-found split, but NOT here — resolving the
        // contradiction leniently would drop a tier-2 hazard from HIGH to MEDIUM on the strength of
        // the half of the surface that says less. Only the cross-check's positive finding overturns
        // the flag.
        $this->assertSame(Hazard::REACH_PUBLIC_WRITE, $this->reachOf(
            $this->pathTo(self::ROUTE),
            [self::ROUTE => $this->security('authed', ['PUBLIC_WRITE'])],
        ));
    }

    #[Test]
    public function an_authenticated_exposure_is_a_guard_on_its_own(): void
    {
        // The evidence the lane used to ignore. Brain says the request was authenticated before it
        // arrived; that is a positive finding, not an absence.
        foreach (['authed', 'admin', 'internal'] as $exposure) {
            $this->assertSame(
                Hazard::REACH_GATED,
                $this->reachOf($this->pathTo(self::ROUTE), [self::ROUTE => $this->security($exposure)]),
                "exposure {$exposure}",
            );
        }
    }

    // ---------------------------------------------------------- admissions

    #[Test]
    public function a_public_read_is_not_gated_and_not_a_public_write(): void
    {
        // Classified, and classified as unguarded — but a GET is not a public WRITE, so neither
        // finding fits. This is the state that used to be swept into `gated`.
        $this->assertSame(Hazard::REACH_NO_GUARD_FOUND, $this->reachOf(
            $this->pathTo('route::GET::/posts'),
            ['route::GET::/posts' => $this->security('public')],
        ));
    }

    #[Test]
    public function a_surface_brain_never_classified_is_no_guard_found(): void
    {
        // A Livewire or Filament surface has no security entry at all. Absence of classification is
        // absence of evidence — the same reason a missing entry never reads as "public" either.
        $this->assertSame(Hazard::REACH_NO_GUARD_FOUND, $this->reachOf(
            $this->pathTo('App\Filament\Pages\ReportsPage'),
            [],
        ));
    }

    #[Test]
    public function a_command_entry_point_is_no_guard_found_not_gated(): void
    {
        // Brain classifies routes only, so a command node can never carry a guard. Under the old
        // fallthrough it read `gated`, which claimed evidence that cannot exist for it.
        $this->assertSame(Hazard::REACH_NO_GUARD_FOUND, $this->reachOf(
            $this->pathTo('command::reports:sync'),
            [],
        ));
    }

    #[Test]
    public function a_member_nothing_reaches_is_no_known_path(): void
    {
        $this->assertSame(Hazard::REACH_NO_KNOWN_PATH, $this->reachOf([], []));
    }

    // ------------------------------------------------- more than one route

    #[Test]
    public function one_unguarded_route_among_guarded_ones_decides_the_class(): void
    {
        // Averaging would hide the way in. `gated` has to hold for EVERY reaching entry point.
        $this->assertSame(Hazard::REACH_NO_GUARD_FOUND, $this->reachOf(
            $this->pathTo('route::GET::/admin/posts', 'route::GET::/posts'),
            [
                'route::GET::/admin/posts' => $this->security('admin'),
                'route::GET::/posts' => $this->security('public'),
            ],
        ));
    }

    #[Test]
    public function every_route_guarded_earns_the_gated_finding(): void
    {
        $this->assertSame(Hazard::REACH_GATED, $this->reachOf(
            $this->pathTo('route::GET::/admin/posts', 'route::POST::/admin/posts'),
            [
                'route::GET::/admin/posts' => $this->security('admin'),
                'route::POST::/admin/posts' => $this->security('authed'),
            ],
        ));
    }

    #[Test]
    public function a_public_write_among_guarded_routes_still_wins(): void
    {
        // The worst reachable exposure is the exposure.
        $this->assertSame(Hazard::REACH_PUBLIC_WRITE, $this->reachOf(
            $this->pathTo('route::GET::/admin/posts', self::ROUTE),
            [
                'route::GET::/admin/posts' => $this->security('admin'),
                self::ROUTE => $this->security('public', ['PUBLIC_WRITE']),
            ],
        ));
    }

    // ------------------------------------------------------ removed member

    #[Test]
    public function a_removed_member_takes_its_declaring_classs_reach(): void
    {
        // A removed member has no node in the head graph, so it appears in no chain. Filtering the
        // chains alone would grade every removal `no-known-path` and flatten the matrix for the whole
        // contract lane.
        $graph = new CodeGraph([
            ['source' => self::ROUTE, 'target' => 'App\Services\Publisher', 'type' => 'route-to-controller'],
        ], hasUnparseableFiles: false);

        $reach = new HazardReach($graph, [], [self::ROUTE => $this->security('admin')], [], [], 6)
            ->attach([new Hazard('contract', 2, null, 'App\Services\Publisher::goneForGood', 'the public method is gone')]);

        $this->assertSame(Hazard::REACH_GATED, $reach[0]->reach);
    }

    #[Test]
    public function a_removed_member_keeps_reach_through_a_class_based_entry_point(): void
    {
        // Livewire, Filament and Nova CLASSES are entry surfaces too. Filtering the declaring class's
        // callers by the route/command/schedule prefixes alone dropped them, sending a removed member
        // reached only that way to `no-known-path` — and a tier-1 hazard on it to LOW.
        $graph = new CodeGraph([
            ['source' => 'App\Livewire\PostIndex', 'target' => 'App\Services\Publisher', 'type' => 'action-to-service'],
        ], hasUnparseableFiles: false);

        $reach = new HazardReach(
            $graph, [], [], [], [], 6,
            static fn (array $callers): array => array_values(array_filter(
                array_column($callers, 'node'),
                static fn (string $node): bool => str_contains($node, '\\Livewire\\'),
            )),
        )->attach([new Hazard('contract', 2, null, 'App\Services\Publisher::goneForGood', 'the public method is gone')]);

        $this->assertSame(Hazard::REACH_NO_GUARD_FOUND, $reach[0]->reach);
    }

    #[Test]
    public function a_removed_member_on_a_class_the_graph_never_charted_is_no_known_path(): void
    {
        $reach = new HazardReach(new CodeGraph([], hasUnparseableFiles: false), [], [], [], [], 6)
            ->attach([new Hazard('contract', 2, null, 'App\Unknown\Thing::gone', 'the public method is gone')]);

        $this->assertSame(Hazard::REACH_NO_KNOWN_PATH, $reach[0]->reach);
    }

    #[Test]
    public function a_route_file_hazard_named_by_its_route_node_resolves_through_that_route(): void
    {
        // A closure route has no action to name, so the route-file lane makes the route's own node id
        // the member. The reach lane matches it against the entry-point set directly — the branch that
        // exists because an entry point's own chain may not repeat it.
        $hazards = new HazardReach(
            new CodeGraph([], hasUnparseableFiles: false),
            ['route::GET::/ping' => [['node' => 'route::GET::/ping', 'via' => '']]],
            ['route::GET::/ping' => ['exposure' => 'authed', 'riskLevel' => 'low', 'issues' => []]],
            [],
            [],
            6,
        )->attach([new Hazard('auth', 3, 'CWE-306', 'route::GET::/ping', 'the `auth` middleware is gone')]);

        $this->assertSame(Hazard::REACH_GATED, $hazards[0]->reach);
    }

    #[Test]
    public function a_route_node_member_no_entry_point_matches_is_an_admission_not_a_guess(): void
    {
        // A group prefix makes the declared URI something other than the registered one. The honest
        // answer is that richter cannot see what reaches it — never that nothing does.
        $hazards = new HazardReach(
            new CodeGraph([], hasUnparseableFiles: false),
            ['route::GET::/admin/ping' => [['node' => 'route::GET::/admin/ping', 'via' => '']]],
            [],
            [],
            [],
            6,
        )->attach([new Hazard('auth', 3, 'CWE-306', 'route::GET::/ping', 'the `auth` middleware is gone')]);

        $this->assertSame(Hazard::REACH_NO_KNOWN_PATH, $hazards[0]->reach);
    }
}
