<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Analysis\Hazard;
use SanderMuller\Richter\Analysis\RiskLadder;
use SanderMuller\Richter\Analysis\RiskLevel;
use SanderMuller\Richter\Analysis\TestReferenceIndex;
use SanderMuller\Richter\Tests\TestCase;

final class RiskLadderTest extends TestCase
{
    private function hazard(int $tier, string $reach): Hazard
    {
        return new Hazard('auth', $tier, null, 'App\Services\Publisher::publish', 'evidence', reach: $reach);
    }

    /**
     * @param  list<Hazard>  $hazards
     * @param  list<string>  $set
     * @return array{0: RiskLevel, 1: string, 2: array<string, bool|null>}
     */
    private function decide(array $hazards = [], bool $seeded = true, array $set = [], ?TestReferenceIndex $tests = null): array
    {
        return RiskLadder::decide($hazards, $seeded, $set, $tests);
    }

    /**
     * An index built from one in-memory test source, so the verification lane has something real to
     * grade rather than a stub that cannot disagree with it.
     */
    private function indexReferencing(string $fqcn, string $file = 'tests/Feature/PublisherTest.php'): TestReferenceIndex
    {
        $index = new TestReferenceIndex();
        $index->addSource("<?php\nuse {$fqcn};\nclass PublisherTest { public function test_it(): void { \$this->assertTrue(true); } }\n", $file);

        return $index;
    }

    // ------------------------------------------------------- the matrix

    #[Test]
    public function tier_three_is_high_at_every_reach_class(): void
    {
        // The graph's inability to name a caller is richter's limit, not evidence of safety — and
        // capping here would silence tier 3 on exactly the applications where reach is hardest.
        foreach ([Hazard::REACH_PUBLIC_WRITE, Hazard::REACH_GATED, Hazard::REACH_NO_GUARD_FOUND, Hazard::REACH_NO_KNOWN_PATH] as $reach) {
            [$level] = $this->decide([$this->hazard(3, $reach)]);

            $this->assertSame(RiskLevel::High, $level, "tier 3 at {$reach}");
        }
    }

    #[Test]
    public function tier_two_is_high_only_at_a_public_write(): void
    {
        $this->assertSame(RiskLevel::High, $this->decide([$this->hazard(2, Hazard::REACH_PUBLIC_WRITE)])[0]);
        $this->assertSame(RiskLevel::Medium, $this->decide([$this->hazard(2, Hazard::REACH_GATED)])[0]);
        $this->assertSame(RiskLevel::Medium, $this->decide([$this->hazard(2, Hazard::REACH_NO_GUARD_FOUND)])[0]);
        $this->assertSame(RiskLevel::Medium, $this->decide([$this->hazard(2, Hazard::REACH_NO_KNOWN_PATH)])[0]);
    }

    #[Test]
    public function tier_one_reaches_low_only_where_no_path_is_known(): void
    {
        $this->assertSame(RiskLevel::Medium, $this->decide([$this->hazard(1, Hazard::REACH_PUBLIC_WRITE)])[0]);
        $this->assertSame(RiskLevel::Medium, $this->decide([$this->hazard(1, Hazard::REACH_GATED)])[0]);
        $this->assertSame(RiskLevel::Medium, $this->decide([$this->hazard(1, Hazard::REACH_NO_GUARD_FOUND)])[0]);
        $this->assertSame(RiskLevel::Low, $this->decide([$this->hazard(1, Hazard::REACH_NO_KNOWN_PATH)])[0]);
    }

    #[Test]
    public function an_admission_scores_exactly_as_the_finding_beside_it(): void
    {
        // `no-guard-found` is an admission, and an admission must move the level in NEITHER direction.
        // Raising it would report HIGH across every application whose surfaces Brain cannot classify —
        // punishing a coverage gap as though it were a security one. Lowering it would read absence of
        // evidence as evidence. Splitting it out of `gated` changes what the report SAYS, not what it
        // scores, which is the whole reason the two are told apart.
        foreach ([1, 2, 3] as $tier) {
            $this->assertSame(
                $this->decide([$this->hazard($tier, Hazard::REACH_GATED)])[0],
                $this->decide([$this->hazard($tier, Hazard::REACH_NO_GUARD_FOUND)])[0],
                "tier {$tier}",
            );
        }
    }

    #[Test]
    public function only_no_known_path_moves_a_cell_and_only_at_tier_one(): void
    {
        // The one admission that does change a score: a signature change nothing reaches is genuinely
        // low, where one reached by a surface richter could not classify is not.
        $this->assertSame(RiskLevel::Low, $this->decide([$this->hazard(1, Hazard::REACH_NO_KNOWN_PATH)])[0]);
        $this->assertSame(RiskLevel::Medium, $this->decide([$this->hazard(1, Hazard::REACH_NO_GUARD_FOUND)])[0]);

        foreach ([2, 3] as $tier) {
            $this->assertSame(
                $this->decide([$this->hazard($tier, Hazard::REACH_NO_KNOWN_PATH)])[0],
                $this->decide([$this->hazard($tier, Hazard::REACH_NO_GUARD_FOUND)])[0],
                "tier {$tier}",
            );
        }
    }

    #[Test]
    public function the_worst_cell_any_hazard_lands_in_decides_and_names_itself(): void
    {
        [$level, $cause] = $this->decide([
            $this->hazard(1, Hazard::REACH_NO_KNOWN_PATH),
            $this->hazard(3, Hazard::REACH_GATED),
            $this->hazard(2, Hazard::REACH_GATED),
        ]);

        $this->assertSame(RiskLevel::High, $level);
        $this->assertStringContainsString('tier 3', $cause);
        $this->assertStringContainsString('and 2 more', $cause);
    }

    // -------------------------------------------------------- the ladder

    #[Test]
    public function nothing_to_assess_reads_low_and_says_so(): void
    {
        // Step 0. Without it a whitespace commit reports MEDIUM and trips `--fail-on=medium`.
        [$level, $cause] = $this->decide(seeded: false);

        $this->assertSame(RiskLevel::Low, $level);
        $this->assertStringContainsString('no analysable change', $cause);
    }

    #[Test]
    public function a_hazard_outranks_nothing_to_assess(): void
    {
        // An additive `$fillable` entry seeds nothing yet widens the write surface. Step 1 sits above
        // step 0 precisely so a hazard on an otherwise-unanalysable diff is not silenced.
        [$level] = $this->decide([$this->hazard(2, Hazard::REACH_NO_KNOWN_PATH)], seeded: false);

        $this->assertSame(RiskLevel::Medium, $level);
    }

    #[Test]
    public function an_analysed_change_that_reaches_nothing_is_unplaced_not_safe(): void
    {
        // Step 2, and it is NOT step 0: something that already existed was analysed and could not be
        // placed. "Could not place" is never evidence of safety.
        [$level, $cause] = $this->decide(seeded: true, set: []);

        $this->assertSame(RiskLevel::Medium, $level);
        $this->assertStringContainsString('could not place', $cause);
    }

    #[Test]
    public function an_unreferenced_reached_surface_is_medium_and_counts_itself(): void
    {
        $index = $this->indexReferencing('App\Services\Publisher');

        [$level, $cause, $verification] = $this->decide(
            set: ['App\Services\Publisher', 'App\Services\Archiver'],
            tests: $index,
        );

        $this->assertSame(RiskLevel::Medium, $level);
        $this->assertStringContainsString('1 of 2 reached surfaces', $cause);
        $this->assertSame(['App\Services\Publisher' => true, 'App\Services\Archiver' => false], $verification);
    }

    #[Test]
    public function every_surface_referenced_is_the_only_road_to_low_besides_tier_one(): void
    {
        [$level, $cause] = $this->decide(
            set: ['App\Services\Publisher'],
            tests: $this->indexReferencing('App\Services\Publisher'),
        );

        $this->assertSame(RiskLevel::Low, $level);
        $this->assertStringContainsString('every reached surface is referenced', $cause);
    }

    // ------------------------------------------------- verification rules

    #[Test]
    public function only_a_runnable_test_file_counts_as_a_class_reference(): void
    {
        // `fromTests()` indexes every PHP file under tests/, fixtures and base cases included. Letting
        // one of those grade a class "referenced" would open a false LOW, the one direction this
        // model must not fail in.
        $fixtureOnly = $this->indexReferencing('App\Services\Publisher', 'tests/Fixtures/PublisherFixture.php');

        [$level, , $verification] = $this->decide(set: ['App\Services\Publisher'], tests: $fixtureOnly);

        $this->assertSame(RiskLevel::Medium, $level);
        $this->assertFalse($verification['App\Services\Publisher']);
    }

    #[Test]
    public function a_route_referenced_only_by_a_fixture_does_not_open_the_low_path(): void
    {
        // `fromTests()` indexes every PHP file under tests/, so a fixture holding `route('posts.edit')`
        // or a literal URI would verify a surface no test exercises. The per-row annotation may still
        // call that "referenced"; the LEVEL may not.
        $index = new TestReferenceIndex();
        $index->addSource("<?php\nclass PostFactory { public function make(): void { \$this->get('/posts/1'); } }\n", 'tests/Fixtures/PostFactory.php');

        [$level, , $verification] = $this->decide(set: ['route::GET::/posts/{post}'], tests: $index);

        $this->assertSame(RiskLevel::Medium, $level);
        $this->assertFalse($verification['route::GET::/posts/{post}']);
    }

    #[Test]
    public function the_same_route_referenced_by_a_runnable_test_does(): void
    {
        $index = new TestReferenceIndex();
        $index->addSource("<?php\nclass PostTest { public function test_it(): void { \$this->get('/posts/1'); } }\n", 'tests/Feature/PostTest.php');

        [$level] = $this->decide(set: ['route::GET::/posts/{post}'], tests: $index);

        $this->assertSame(RiskLevel::Low, $level);
    }

    #[Test]
    public function an_absent_index_reads_unchecked_and_says_so(): void
    {
        // The level is unchanged — a surface nothing looked at is not a verified one. What must not
        // happen is the REASON claiming something about tests that were never read: "1 of 1 reached
        // surfaces have no test referencing them" is evidence-shaped, and there is no evidence.
        [$level, $cause, $verification] = $this->decide(set: ['App\Services\Publisher']);

        $this->assertSame(RiskLevel::Medium, $level);
        $this->assertNull($verification['App\Services\Publisher']);
        $this->assertStringContainsString('were not checked', $cause);
        $this->assertStringNotContainsString('no test referencing them', $cause);
    }

    #[Test]
    public function a_reference_state_that_could_not_be_checked_reads_unchecked(): void
    {
        // `hasReference()` returns null for a node shape it does not recognise. Reading null as
        // "not unreferenced" would open the LOW path on a surface nothing checked; reading it as
        // "unreferenced" would state a fact nobody established. It is neither.
        [$level, $cause, $verification] = $this->decide(set: ['view::posts.show'], tests: new TestReferenceIndex());

        $this->assertSame(RiskLevel::Medium, $level);
        $this->assertNull($verification['view::posts.show']);
        $this->assertStringContainsString('were not checked', $cause);
    }

    #[Test]
    public function a_mix_of_unreferenced_and_unchecked_names_both(): void
    {
        $index = $this->indexReferencing('App\Services\Publisher');

        [$level, $cause] = $this->decide(
            set: ['App\Services\Publisher', 'App\Services\Archiver', 'view::posts.show'],
            tests: $index,
        );

        $this->assertSame(RiskLevel::Medium, $level);
        $this->assertStringContainsString('1 of 3 reached surfaces have no test referencing them', $cause);
        $this->assertStringContainsString('1 could not be checked', $cause);
    }

    #[Test]
    public function a_caller_that_renders_references_must_hand_the_index_to_the_level_too(): void
    {
        // The defect this pins: three callers built a TestReferenceIndex for the RENDERERS and passed
        // none to the analyzer. The report then contradicted itself — a row rendered "referenced"
        // beside a cause line calling the same surface unreferenced — because the level had been
        // computed as though no test existed. An absent index is a legitimate state; silently
        // disagreeing with the output beside it is not.
        $index = $this->indexReferencing('App\Services\Publisher');

        [$withIndex] = $this->decide(set: ['App\Services\Publisher'], tests: $index);
        [$withoutIndex] = $this->decide(set: ['App\Services\Publisher']);

        // Omitting the index is visible in the level, never silent.
        $this->assertSame(RiskLevel::Low, $withIndex);
        $this->assertSame(RiskLevel::Medium, $withoutIndex);
    }

    #[Test]
    public function every_outcome_carries_a_cause(): void
    {
        $outcomes = [
            $this->decide(seeded: false),
            $this->decide([$this->hazard(3, Hazard::REACH_GATED)]),
            $this->decide(seeded: true, set: []),
            $this->decide(set: ['App\Services\Publisher'], tests: new TestReferenceIndex()),
            $this->decide(set: ['App\Services\Publisher'], tests: $this->indexReferencing('App\Services\Publisher')),
        ];

        foreach ($outcomes as $index => [, $cause]) {
            $this->assertNotSame('', $cause, "outcome {$index} rendered a bare level");
        }
    }
}
