<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Analysis\AffectedTests;
use SanderMuller\Richter\Analysis\SelectionProvenance;
use SanderMuller\Richter\Analysis\TestReferenceIndex;
use SanderMuller\Richter\Changes\ChangedFileSymbols;
use SanderMuller\Richter\Changes\MemberChange;
use SanderMuller\Richter\Tests\TestCase;

final class SelectionProvenanceTest extends TestCase
{
    /**
     * @param  list<array{depth: int, node: string, via: string}>  $callers
     * @param  list<string>  $entryPoints
     * @return array{coverage: array<string, 'analyzed'|'unresolved'>, entryPoints: list<string>, registryEntryPoints: list<string>, lowConfidence: bool, callers: list<array{depth: int, node: string, via: string}>, dependencies: list<array{depth: int, node: string, via: string}>}
     */
    private function detectResult(array $entryPoints = [], array $callers = []): array
    {
        return ['coverage' => ['app/Services/X.php' => 'analyzed'], 'entryPoints' => $entryPoints, 'registryEntryPoints' => [], 'lowConfidence' => false, 'callers' => $callers, 'dependencies' => []];
    }

    private function changed(string $file, string $fqcn): ChangedFileSymbols
    {
        return new ChangedFileSymbols($file, $fqcn, [
            new MemberChange('run', MemberChange::KIND_METHOD, MemberChange::CHANGE_MODIFIED, resolvable: true),
        ], cosmeticOnly: false);
    }

    #[Test]
    public function a_file_selected_by_two_axes_carries_both_reasons(): void
    {
        // Every reason, not the first one that matched. A caller asking why a file is in the
        // selection is usually asking whether ONE of the reasons is the weak one.
        $index = new TestReferenceIndex();
        $index->addSource("<?php\n\$this->get('/errors/log');\nuse App\Services\X;\n", 'tests/Feature/ErrorLogTest.php');

        AffectedTests::select(
            $this->detectResult(['route::GET::/errors/log']),
            [$this->changed('app/Services/X.php', 'App\Services\X')],
            $index,
            unresolvedDispatchSites: [],
            changedTests: ['tests/Feature/ErrorLogTest.php'],
            provenance: $provenance,
        );

        $explanation = $provenance->explain('tests/Feature/ErrorLogTest.php', determinable: true);

        $this->assertTrue($explanation['selected']);
        $this->assertSame([
            ['axis' => 'changed-file'],
            ['axis' => 'entry-point', 'node' => 'route::GET::/errors/log'],
            ['axis' => 'import', 'class' => 'App\Services\X', 'origin' => 'changed'],
        ], $explanation['reasons'] ?? []);
    }

    #[Test]
    public function an_import_reason_says_whether_the_class_changed_or_calls_the_change(): void
    {
        // The two are worth different amounts of trust: a changed class is the diff itself, a caller
        // is one hop of reasoning.
        $index = new TestReferenceIndex();
        $index->addSource("<?php\nuse App\Services\Caller;\n", 'tests/Unit/CallerTest.php');

        AffectedTests::select(
            $this->detectResult(callers: [['depth' => 1, 'node' => 'App\Services\Caller::run', 'via' => 'call']]),
            [$this->changed('app/Services/X.php', 'App\Services\X')],
            $index,
            unresolvedDispatchSites: [],
            provenance: $provenance,
        );

        $this->assertSame(
            [['axis' => 'import', 'class' => 'App\Services\Caller', 'origin' => 'caller']],
            $provenance->explain('tests/Unit/CallerTest.php', determinable: true)['reasons'] ?? [],
        );
    }

    #[Test]
    public function an_excluded_file_reports_the_glob_and_keeps_the_reason_it_nearly_qualified_on(): void
    {
        // A caller who configured the glob wants to see what the file would have matched, not only
        // that a glob won.
        AffectedTests::select(
            $this->detectResult(),
            [$this->changed('app/Services/X.php', 'App\Services\X')],
            new TestReferenceIndex(),
            unresolvedDispatchSites: [],
            changedTests: ['tests/Browser/SmokeTest.php'],
            unrunnablePaths: ['tests/Browser/*'],
            provenance: $provenance,
        );

        $explanation = $provenance->explain('tests/Browser/SmokeTest.php', determinable: true);

        $this->assertFalse($explanation['selected']);
        $this->assertSame('excluded', $explanation['notSelected'] ?? null);
        $this->assertSame('tests/Browser/*', $explanation['excludedBy'] ?? null);
        $this->assertSame([['axis' => 'changed-file']], $explanation['reasons'] ?? []);
    }

    #[Test]
    public function a_queried_path_is_normalised_before_it_is_looked_up(): void
    {
        // Every recorded path comes from a git diff or a normalised scan, so a caller pasting a
        // Windows path or a `./`-prefixed one from their shell would otherwise never match.
        AffectedTests::select(
            $this->detectResult(),
            [$this->changed('app/Services/X.php', 'App\Services\X')],
            new TestReferenceIndex(),
            unresolvedDispatchSites: [],
            changedTests: ['tests/Feature/PostTest.php'],
            provenance: $provenance,
        );

        foreach (['tests/Feature/PostTest.php', './tests/Feature/PostTest.php', 'tests\\Feature\\PostTest.php', '  tests/Feature/PostTest.php  '] as $query) {
            $this->assertTrue($provenance->explain($query, determinable: true)['selected'], $query);
        }
    }

    #[Test]
    public function normalising_never_eats_a_leading_dot_from_a_directory_name(): void
    {
        $this->assertSame('.github/scripts/RunTest.php', SelectionProvenance::normalise('.github/scripts/RunTest.php'));
    }

    #[Test]
    public function a_test_no_axis_matched_is_told_what_was_consulted(): void
    {
        // The bounded not-selected answer. It names the axes and their sizes rather than guessing at
        // a cause it cannot know.
        AffectedTests::select(
            $this->detectResult(['route::GET::/errors/log'], callers: [['depth' => 1, 'node' => 'App\Services\Caller::run', 'via' => 'call']]),
            [$this->changed('app/Services/X.php', 'App\Services\X')],
            new TestReferenceIndex(),
            unresolvedDispatchSites: [],
            provenance: $provenance,
        );

        $explanation = $provenance->explain('tests/Feature/UnrelatedTest.php', determinable: true);

        $this->assertSame('no-axis-matched', $explanation['notSelected'] ?? null);
        $this->assertSame(1, $explanation['entryPointsConsidered']);
        // The changed class and its caller — the two the import axis walked.
        $this->assertSame(2, $explanation['classesConsidered']);
    }

    #[Test]
    public function a_file_no_axis_could_ever_name_gets_a_different_answer_from_one_nothing_matched(): void
    {
        // A helper and an unmatched test are different problems for the reader: one is never
        // selectable, the other simply was not reached.
        AffectedTests::select(
            $this->detectResult(),
            [$this->changed('app/Services/X.php', 'App\Services\X')],
            new TestReferenceIndex(),
            unresolvedDispatchSites: [],
            provenance: $provenance,
        );

        $this->assertSame('not-a-test-file', $provenance->explain('tests/Support/InteractsWithPosts.php', determinable: true)['notSelected'] ?? null);
        $this->assertSame('not-a-test-file', $provenance->explain('app/Services/X.php', determinable: true)['notSelected'] ?? null);
        // Conventionally named and still unreachable: the index is built from `tests/` alone.
        $this->assertSame('not-a-test-file', $provenance->explain('src/Support/HelperTest.php', determinable: true)['notSelected'] ?? null);
        $this->assertSame('no-axis-matched', $provenance->explain('tests/Feature/PostTest.php', determinable: true)['notSelected'] ?? null);
        // A frontend spec is selectable, so it gets the reached-nothing answer rather than the
        // never-selectable one.
        $this->assertSame('no-axis-matched', $provenance->explain('resources/js/lib/thresholds.test.ts', determinable: true)['notSelected'] ?? null);
    }

    #[Test]
    public function a_selected_file_carries_no_not_selected_key_at_all(): void
    {
        // Sparse, like every other document here: a key that does not apply is absent, never null.
        AffectedTests::select(
            $this->detectResult(),
            [$this->changed('app/Services/X.php', 'App\Services\X')],
            new TestReferenceIndex(),
            unresolvedDispatchSites: [],
            changedTests: ['tests/Feature/PostTest.php'],
            provenance: $provenance,
        );

        $explanation = $provenance->explain('tests/Feature/PostTest.php', determinable: true);

        $this->assertArrayNotHasKey('notSelected', $explanation);
        $this->assertArrayNotHasKey('excludedBy', $explanation);
    }
}
