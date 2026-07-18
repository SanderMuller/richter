<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use SanderMuller\Richter\Changes\ChangedFileSymbols;
use SanderMuller\Richter\Changes\ChangedSymbols;
use SanderMuller\Richter\Changes\MemberChange;
use SanderMuller\Richter\Tests\TestCase;
use SanderMuller\Richter\Tracers\EagerLoadStringChecker;

final class ChangedSymbolsTest extends TestCase
{
    #[Test]
    public function a_failed_git_diff_throws_rather_than_reporting_no_changes(): void
    {
        $this->expectException(RuntimeException::class);

        // A base ref that cannot exist makes `git diff` fail; that must not look like an empty diff.
        ChangedSymbols::resolve('this-base-ref-does-not-exist-zzz');
    }

    #[Test]
    public function an_empty_but_successful_diff_returns_no_changed_symbols(): void
    {
        // HEAD...HEAD is a valid, empty diff — distinct from the failure case above.
        $this->assertSame([], ChangedSymbols::resolve('HEAD'));
    }

    #[Test]
    public function a_modified_method_is_a_resolvable_modification(): void
    {
        $head = "<?php\nclass Foo\n{\n    public function bar(): int\n    {\n        return 1;\n    }\n}\n";
        $base = "<?php\nclass Foo\n{\n    public function bar(): int\n    {\n        return 0;\n    }\n}\n";
        $hunk = $this->hunk([[6, '        return 1;']], [[6, '        return 0;']]);

        $result = ChangedSymbols::classifyFile('app/Foo.php', $head, $base, $hunk);

        $this->assertFalse($result->cosmeticOnly);
        $this->assertCount(1, $result->resolvableMembers());
        $bar = $result->resolvableMembers()[0];
        $this->assertSame('bar', $bar->name);
        $this->assertSame(MemberChange::CHANGE_MODIFIED, $bar->change);
    }

    #[Test]
    public function a_new_method_is_additive_and_seeds_nothing(): void
    {
        $head = "<?php\nclass Foo\n{\n    public function bar(): int\n    {\n        return 1;\n    }\n\n    public function baz(): int\n    {\n        return 2;\n    }\n}\n";
        $base = "<?php\nclass Foo\n{\n    public function bar(): int\n    {\n        return 1;\n    }\n}\n";
        $hunk = $this->hunk([
            [8, ''],
            [9, '    public function baz(): int'],
            [10, '    {'],
            [11, '        return 2;'],
            [12, '    }'],
        ], []);

        $result = ChangedSymbols::classifyFile('app/Foo.php', $head, $base, $hunk);

        $this->assertTrue($result->hasOnlyAdditiveOrCosmeticChanges());
        $this->assertSame([], $result->resolvableMembers());
        $this->assertFalse($result->needsCoarseSeed());
    }

    #[Test]
    public function a_newly_added_file_is_additive_not_a_class_level_change(): void
    {
        // A brand-new file has no base side; every line is added. The class header / braces are
        // "outer" lines, but with nothing to diff against they are part of the additive whole and
        // must not register as a non-additive class-level change — that would wrongly trip the
        // coarse seed and entry-point floor for e.g. a newly added job.
        $head = "<?php\nfinal class NewJob\n{\n    public function handle(): void\n    {\n        run();\n    }\n}\n";
        $hunk = $this->hunk([
            [1, '<?php'],
            [2, 'final class NewJob'],
            [3, '{'],
            [4, '    public function handle(): void'],
            [5, '    {'],
            [6, '        run();'],
            [7, '    }'],
            [8, '}'],
        ], []);

        $result = ChangedSymbols::classifyFile('app/Jobs/NewJob.php', $head, baseSrc: null, hunk: $hunk);

        $this->assertTrue($result->hasOnlyAdditiveOrCosmeticChanges());
        $this->assertFalse($result->needsCoarseSeed());
    }

    #[Test]
    public function a_removed_method_still_seeds_from_the_base_side(): void
    {
        $head = "<?php\nclass Foo\n{\n    public function bar(): int\n    {\n        return 1;\n    }\n}\n";
        $base = "<?php\nclass Foo\n{\n    public function bar(): int\n    {\n        return 1;\n    }\n\n    public function gone(): int\n    {\n        return 2;\n    }\n}\n";
        $hunk = $this->hunk([], [
            [8, ''],
            [9, '    public function gone(): int'],
            [10, '    {'],
            [11, '        return 2;'],
            [12, '    }'],
        ]);

        $result = ChangedSymbols::classifyFile('app/Foo.php', $head, $base, $hunk);

        $resolvable = $result->resolvableMembers();
        $this->assertCount(1, $resolvable);
        $this->assertSame('gone', $resolvable[0]->name);
        $this->assertSame(MemberChange::CHANGE_REMOVED, $resolvable[0]->change);
    }

    #[Test]
    public function a_whitespace_only_change_inside_a_method_is_cosmetic(): void
    {
        $head = "<?php\nclass Foo\n{\n    public function bar(): int\n    {\n        return 1;\n    }\n}\n";
        $base = "<?php\nclass Foo\n{\n    public function bar(): int\n    {\n        return  1;\n    }\n}\n";
        $hunk = $this->hunk([[6, '        return 1;']], [[6, '        return  1;']]);

        $result = ChangedSymbols::classifyFile('app/Foo.php', $head, $base, $hunk);

        $this->assertTrue($result->cosmeticOnly);
        $this->assertSame([], $result->members);
    }

    #[Test]
    public function an_added_enum_case_is_additive(): void
    {
        $head = "<?php\nenum Status: string\n{\n    case Active = 'active';\n    case Archived = 'archived';\n}\n";
        $base = "<?php\nenum Status: string\n{\n    case Active = 'active';\n}\n";
        $hunk = $this->hunk([[5, "    case Archived = 'archived';"]], []);

        $result = ChangedSymbols::classifyFile('app/Status.php', $head, $base, $hunk);

        $this->assertTrue($result->hasOnlyAdditiveOrCosmeticChanges());
        $this->assertSame([], $result->resolvableMembers());
        $this->assertFalse($result->needsCoarseSeed());
    }

    #[Test]
    public function adding_final_is_a_class_level_change_needing_a_coarse_seed(): void
    {
        $head = "<?php\nfinal class Foo\n{\n    public function bar(): int\n    {\n        return 1;\n    }\n}\n";
        $base = "<?php\nclass Foo\n{\n    public function bar(): int\n    {\n        return 1;\n    }\n}\n";
        $hunk = $this->hunk([[2, 'final class Foo']], [[2, 'class Foo']]);

        $result = ChangedSymbols::classifyFile('app/Foo.php', $head, $base, $hunk);

        $this->assertFalse($result->cosmeticOnly);
        $this->assertTrue($result->needsCoarseSeed());
        $this->assertSame([], $result->resolvableMembers());
    }

    #[Test]
    public function a_mixed_file_seeds_only_the_modified_method_not_the_added_property(): void
    {
        $head = "<?php\nclass Foo\n{\n    protected array \$fillable = ['a'];\n\n    public function bar(): int\n    {\n        return 2;\n    }\n}\n";
        $base = "<?php\nclass Foo\n{\n    public function bar(): int\n    {\n        return 1;\n    }\n}\n";
        $hunk = $this->hunk([
            [4, "    protected array \$fillable = ['a'];"],
            [5, ''],
            [8, '        return 2;'],
        ], [[6, '        return 1;']]);

        $result = ChangedSymbols::classifyFile('app/Foo.php', $head, $base, $hunk);

        $resolvable = $result->resolvableMembers();
        $this->assertCount(1, $resolvable);
        $this->assertSame('bar', $resolvable[0]->name);
        $this->assertFalse($result->needsCoarseSeed());
    }

    #[Test]
    public function a_statement_reorder_in_a_method_is_not_cosmetic(): void
    {
        // Swapping two statements is a real behaviour change — the sorted comparison would wrongly
        // read it as cosmetic, so member bodies must be compared in order.
        $head = "<?php\nclass Foo\n{\n    public function bar(): void\n    {\n        a();\n        b();\n    }\n}\n";
        $base = "<?php\nclass Foo\n{\n    public function bar(): void\n    {\n        b();\n        a();\n    }\n}\n";
        $hunk = $this->hunk([[6, '        a();'], [7, '        b();']], [[6, '        b();'], [7, '        a();']]);

        $result = ChangedSymbols::classifyFile('app/Foo.php', $head, $base, $hunk);

        $this->assertFalse($result->cosmeticOnly);
        $this->assertCount(1, $result->resolvableMembers());
    }

    #[Test]
    public function an_unparseable_changed_file_falls_back_to_a_coarse_seed_not_cosmetic(): void
    {
        $head = "<?php\nclass Foo { this is not valid php @@@ }\n";
        $base = "<?php\nclass Foo {}\n";
        $hunk = $this->hunk([[2, 'class Foo { this is not valid php @@@ }']], [[2, 'class Foo {}']]);

        $result = ChangedSymbols::classifyFile('app/Foo.php', $head, $base, $hunk);

        $this->assertFalse($result->cosmeticOnly);
        $this->assertTrue($result->needsCoarseSeed());
    }

    #[Test]
    public function a_modified_property_drives_a_coarse_seed(): void
    {
        // A non-config property modification — the addition-only config exemption (HPB-5382) does
        // not apply, so a non-resolvable property change still falls to a coarse class seed.
        $head = "<?php\nclass Foo\n{\n    protected array \$options = ['a', 'b'];\n}\n";
        $base = "<?php\nclass Foo\n{\n    protected array \$options = ['a'];\n}\n";
        $hunk = $this->hunk([[4, "    protected array \$options = ['a', 'b'];"]], [[4, "    protected array \$options = ['a'];"]]);

        $result = ChangedSymbols::classifyFile('app/Foo.php', $head, $base, $hunk);

        $this->assertFalse($result->cosmeticOnly);
        $this->assertTrue($result->needsCoarseSeed());
        $this->assertSame([], $result->resolvableMembers());
    }

    #[Test]
    public function a_rename_is_a_removed_member_plus_a_suppressed_added_member(): void
    {
        $head = "<?php\nclass Foo\n{\n    public function newName(): int\n    {\n        return 1;\n    }\n}\n";
        $base = "<?php\nclass Foo\n{\n    public function oldName(): int\n    {\n        return 1;\n    }\n}\n";
        $hunk = $this->hunk([
            [4, '    public function newName(): int'],
            [5, '    {'],
            [6, '        return 1;'],
            [7, '    }'],
        ], [
            [4, '    public function oldName(): int'],
            [5, '    {'],
            [6, '        return 1;'],
            [7, '    }'],
        ]);

        $result = ChangedSymbols::classifyFile('app/Foo.php', $head, $base, $hunk);

        // The old name is seeded (its callers may break); the new name is additive and suppressed.
        $resolvable = $result->resolvableMembers();
        $this->assertCount(1, $resolvable);
        $this->assertSame('oldName', $resolvable[0]->name);
        $this->assertSame(MemberChange::CHANGE_REMOVED, $resolvable[0]->change);
    }

    #[Test]
    public function an_addition_only_fillable_edit_is_additive_not_coarse(): void
    {
        $result = $this->propEdit("    protected \$fillable = ['a', 'b'];", "    protected \$fillable = ['a'];");

        $this->assertTrue($result->hasOnlyAdditiveOrCosmeticChanges());
        $this->assertFalse($result->needsCoarseSeed());
    }

    #[Test]
    public function changing_an_existing_cast_value_stays_a_coarse_modification(): void
    {
        $result = $this->propEdit("    protected \$casts = ['a' => 'string'];", "    protected \$casts = ['a' => 'int'];");

        $this->assertFalse($result->hasOnlyAdditiveOrCosmeticChanges());
        $this->assertTrue($result->needsCoarseSeed());
    }

    #[Test]
    public function removing_a_config_element_stays_a_coarse_modification(): void
    {
        $result = $this->propEdit("    protected \$fillable = ['a'];", "    protected \$fillable = ['a', 'b'];");

        $this->assertTrue($result->needsCoarseSeed());
    }

    #[Test]
    public function a_mixed_add_and_remove_in_one_config_array_stays_a_modification(): void
    {
        $result = $this->propEdit("    protected \$fillable = ['a', 'c'];", "    protected \$fillable = ['a', 'b'];");

        $this->assertTrue($result->needsCoarseSeed());
    }

    #[Test]
    public function a_reorder_or_formatting_only_config_edit_is_additive(): void
    {
        $reorder = $this->propEdit("    protected \$fillable = ['b', 'a'];", "    protected \$fillable = ['a', 'b'];");
        $this->assertTrue($reorder->hasOnlyAdditiveOrCosmeticChanges());

        // Double-quoted key + added entry — quote style must not read as a change.
        $formatting = $this->propEdit("    protected \$casts = [\"a\" => 'int', 'b' => 'bool'];", "    protected \$casts = ['a' => 'int'];");
        $this->assertTrue($formatting->hasOnlyAdditiveOrCosmeticChanges());
    }

    #[Test]
    public function a_non_enumerable_config_array_falls_back_to_a_coarse_modification(): void
    {
        $result = $this->propEdit("    protected \$fillable = [...\$base, 'b'];", "    protected \$fillable = ['a'];");

        $this->assertTrue($result->needsCoarseSeed());
    }

    #[Test]
    public function adding_to_a_non_config_array_property_is_not_suppressed(): void
    {
        // $dispatchesEvents wires a new model event to fire — a real change, even though additive.
        $result = $this->propEdit(
            "    protected \$dispatchesEvents = ['saved' => Foo::class, 'deleted' => Bar::class];",
            "    protected \$dispatchesEvents = ['saved' => Foo::class];",
        );

        $this->assertTrue($result->needsCoarseSeed());
    }

    #[Test]
    public function an_addition_only_casts_method_is_additive(): void
    {
        $head = "<?php\nclass Foo\n{\n    protected function casts(): array\n    {\n        return ['a' => 'int', 'b' => 'bool'];\n    }\n}\n";
        $base = "<?php\nclass Foo\n{\n    protected function casts(): array\n    {\n        return ['a' => 'int'];\n    }\n}\n";
        $hunk = $this->hunk([[6, "        return ['a' => 'int', 'b' => 'bool'];"]], [[6, "        return ['a' => 'int'];"]]);

        $result = ChangedSymbols::classifyFile('app/Models/Foo.php', $head, $base, $hunk);

        $this->assertTrue($result->hasOnlyAdditiveOrCosmeticChanges());
        $this->assertSame([], $result->resolvableMembers());
    }

    #[Test]
    public function an_addition_only_casts_with_an_enum_class_value_is_additive(): void
    {
        // The canonical Laravel $casts shape — an existing enum ::class value kept, a new key added.
        $result = $this->propEdit(
            "    protected \$casts = ['type' => Status::class, 'count' => 'int'];",
            "    protected \$casts = ['type' => Status::class];",
        );

        $this->assertTrue($result->hasOnlyAdditiveOrCosmeticChanges());
        $this->assertFalse($result->needsCoarseSeed());
    }

    #[Test]
    public function changing_an_enum_class_cast_value_stays_a_coarse_modification(): void
    {
        // Same key, different enum class — a value change, not addition-only.
        $result = $this->propEdit(
            "    protected \$casts = ['type' => Other::class];",
            "    protected \$casts = ['type' => Status::class];",
        );

        $this->assertTrue($result->needsCoarseSeed());
    }

    #[Test]
    public function a_duplicate_key_in_a_config_array_collapses_before_comparison(): void
    {
        // Duplicate literal keys collapse last-write-wins (as PHP does), so the added 'b' is the
        // only real difference and the edit reads addition-only.
        $result = $this->propEdit(
            "    protected \$casts = ['a' => 'int', 'a' => 'int', 'b' => 'bool'];",
            "    protected \$casts = ['a' => 'int'];",
        );

        $this->assertTrue($result->hasOnlyAdditiveOrCosmeticChanges());
    }

    #[Test]
    public function a_casts_method_with_logic_falls_back_to_a_coarse_modification(): void
    {
        // A casts() body that isn't a single `return [...]` can't be statically enumerated.
        $head = "<?php\nclass Foo\n{\n    protected function casts(): array\n    {\n        \$base = ['a' => 'int'];\n\n        return [...\$base, 'b' => 'bool'];\n    }\n}\n";
        $base = "<?php\nclass Foo\n{\n    protected function casts(): array\n    {\n        \$base = ['a' => 'int'];\n\n        return [...\$base];\n    }\n}\n";
        $hunk = $this->hunk([[8, "        return [...\$base, 'b' => 'bool'];"]], [[8, '        return [...$base];']]);

        $result = ChangedSymbols::classifyFile('app/Models/Foo.php', $head, $base, $hunk);

        // casts() is a resolvable method, so the real change is seeded precisely (not coarsely) —
        // the point is only that it is NOT suppressed as additive.
        $this->assertFalse($result->hasOnlyAdditiveOrCosmeticChanges());
        $this->assertSame(['casts'], array_map(static fn (MemberChange $m): string => $m->name, $result->resolvableMembers()));
    }

    #[Test]
    public function a_config_member_in_a_multi_class_file_is_not_suppressed(): void
    {
        // Two classes both declare $fillable; the array can't be attributed to the touched class,
        // so a value-changed $fillable must stay a coarse modification rather than be suppressed.
        $head = "<?php\nclass A\n{\n    protected \$fillable = ['a'];\n}\nclass B\n{\n    protected \$fillable = ['x'];\n}\n";
        $base = "<?php\nclass A\n{\n    protected \$fillable = ['a'];\n}\nclass B\n{\n    protected \$fillable = ['y'];\n}\n";
        $hunk = $this->hunk([[7, "    protected \$fillable = ['x'];"]], [[7, "    protected \$fillable = ['y'];"]]);

        $result = ChangedSymbols::classifyFile('app/Models/Foo.php', $head, $base, $hunk);

        $this->assertTrue($result->needsCoarseSeed());
    }

    #[Test]
    public function an_addition_with_a_co_occurring_visibility_change_is_not_suppressed(): void
    {
        // The array gains an element AND the property visibility changes — not a pure column add,
        // so the declaration change must keep it a real (coarse) modification.
        $result = $this->propEdit("    public \$fillable = ['a', 'b'];", "    protected \$fillable = ['a'];");

        $this->assertTrue($result->needsCoarseSeed());
    }

    #[Test]
    public function adding_to_guarded_is_not_suppressed(): void
    {
        // $guarded is a block-list — adding to it restricts existing mass-assignment, so it is a
        // real change, not additive (deliberately excluded from the config-member set).
        $result = $this->propEdit("    protected \$guarded = ['a', 'b'];", "    protected \$guarded = ['a'];");

        $this->assertTrue($result->needsCoarseSeed());
    }

    private function propEdit(string $headLine, string $baseLine): ChangedFileSymbols
    {
        $head = "<?php\nclass Foo\n{\n{$headLine}\n}\n";
        $base = "<?php\nclass Foo\n{\n{$baseLine}\n}\n";

        return ChangedSymbols::classifyFile('app/Models/Foo.php', $head, $base, $this->hunk([[4, $headLine]], [[4, $baseLine]]));
    }

    #[Test]
    public function a_failing_git_show_for_an_adding_diff_seeds_a_coarse_class_change(): void
    {
        // `git show` failing for a file the diff says gained lines must not classify as cosmetic —
        // that would be the forbidden falsely-empty "no impact". It seeds coarsely instead. (The
        // sibling HEAD-worktree branch — file_get_contents returning false — shares the same null
        // consumer but needs FS fault injection to exercise; this pins the shared guard.)
        $diff = "diff --git a/app/Fake/Nope.php b/app/Fake/Nope.php\n--- a/app/Fake/Nope.php\n+++ b/app/Fake/Nope.php\n@@ -0,0 +1,1 @@\n+    public function x(): void {}\n";

        // Patterns are matched against the shell-quoted command string, so wrap in wildcards.
        Process::fake([
            '*merge-base*' => Process::result("abc123\n"),
            '*diff*' => Process::result($diff),
            '*show*' => Process::result(errorOutput: 'bad object', exitCode: 128),
        ]);

        $changed = ChangedSymbols::resolve('base-ref', 'head-ref');

        $this->assertCount(1, $changed);
        $this->assertFalse($changed[0]->cosmeticOnly);
        $this->assertCount(1, $changed[0]->members);
        $this->assertSame(MemberChange::KIND_CLASS, $changed[0]->members[0]->kind);
        $this->assertFalse($changed[0]->members[0]->resolvable);
    }

    #[Test]
    public function a_pure_rename_is_a_class_level_change_that_seeds_both_fqcns(): void
    {
        // A 100%-similarity rename has no hunks, but the old FQCN vanishes — every caller of it
        // breaks. It must classify as a real class-level change, never cosmetic, and seed the old
        // FQCN directly (head-tree callers still reference it) plus the new FQCN coarsely.
        $diff = "diff --git a/app/Services/Old.php b/app/Services/New.php\nsimilarity index 100%\nrename from app/Services/Old.php\nrename to app/Services/New.php\n";

        Process::fake([
            '*merge-base*' => Process::result("abc123\n"),
            '*diff*' => Process::result($diff),
            '*show*' => Process::result("<?php\nnamespace App\Services;\nclass New {}\n"),
        ]);

        $changed = ChangedSymbols::resolve('base-ref', 'head-ref');

        $this->assertCount(1, $changed);
        $this->assertSame('app/Services/New.php', $changed[0]->file);
        $this->assertSame('App\Services\New', $changed[0]->fqcn);
        $this->assertFalse($changed[0]->cosmeticOnly);
        $this->assertCount(1, $changed[0]->members);
        $this->assertSame(MemberChange::KIND_CLASS, $changed[0]->members[0]->kind);
        $this->assertSame(MemberChange::CHANGE_MODIFIED, $changed[0]->members[0]->change);
        $this->assertFalse($changed[0]->members[0]->resolvable);
        $this->assertSame(['App\Services\Old'], $changed[0]->directSeeds);
    }

    #[Test]
    public function a_changed_file_with_a_broken_eager_load_string_carries_a_finding(): void
    {
        $head = "<?php\nnamespace App\Exports;\nuse App\Models\Video;\nclass Foo\n{\n    public function bar(): void\n    {\n        \$this->video->load([Video::INTERACTIONS . Video::QUESTIONS]);\n    }\n}\n";
        $base = "<?php\nnamespace App\Exports;\nuse App\Models\Video;\nclass Foo\n{\n    public function bar(): void\n    {\n    }\n}\n";
        $hunk = $this->hunk([[8, '        $this->video->load([Video::INTERACTIONS . Video::QUESTIONS]);']], []);

        $checker = new EagerLoadStringChecker(self::fixtureProjectPath() . '/app/Models');
        $result = ChangedSymbols::classifyFile('app/Exports/Foo.php', $head, $base, $hunk, $checker);

        $this->assertCount(1, $result->findings);
        $this->assertStringContainsString('interactionsquestions', $result->findings[0]);
    }

    #[Test]
    public function a_cosmetic_only_change_is_not_checked_for_findings(): void
    {
        // Same broken eager-load string on both sides — the change itself is whitespace-only, so the
        // checker must not fire on pre-existing code the diff didn't touch behaviourally.
        $head = "<?php\nnamespace App\Exports;\nuse App\Models\Video;\nclass Foo\n{\n    public function bar(): void\n    {\n        \$this->video->load([Video::INTERACTIONS . Video::QUESTIONS]);\n    }\n}\n";
        $hunk = $this->hunk([[8, '        $this->video->load([Video::INTERACTIONS . Video::QUESTIONS]);']], [[8, '  $this->video->load([Video::INTERACTIONS . Video::QUESTIONS]);']]);

        $result = ChangedSymbols::classifyFile('app/Exports/Foo.php', $head, $head, $hunk);

        $this->assertTrue($result->cosmeticOnly);
        $this->assertSame([], $result->findings);
    }

    /**
     * @param  list<array{0: int, 1: string}>  $added
     * @param  list<array{0: int, 1: string}>  $removed
     * @return array{added: list<array{line: int, text: string}>, removed: list<array{line: int, text: string}>}
     */
    private function hunk(array $added, array $removed): array
    {
        return [
            'added' => $this->hunkLines($added),
            'removed' => $this->hunkLines($removed),
        ];
    }

    /**
     * @param  list<array{0: int, 1: string}>  $pairs
     * @return list<array{line: int, text: string}>
     */
    private function hunkLines(array $pairs): array
    {
        return array_map(static fn (array $pair): array => ['line' => $pair[0], 'text' => $pair[1]], $pairs);
    }
}
