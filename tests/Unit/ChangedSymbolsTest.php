<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use SanderMuller\Richter\Changes\ChangedFileSymbols;
use SanderMuller\Richter\Changes\ChangedSymbols;
use SanderMuller\Richter\Changes\MemberChange;
use SanderMuller\Richter\Tests\TestCase;
use SanderMuller\Richter\Tracers\EagerLoadStringChecker;
use SanderMuller\Richter\Tracers\InertiaPageChecker;

final class ChangedSymbolsTest extends TestCase
{
    /** Set only by the working-tree-diff tests below; {@see tearDown()} cleans it up when present. */
    private ?string $tempWorkingTree = null;

    protected function tearDown(): void
    {
        if ($this->tempWorkingTree !== null) {
            new Filesystem()->deleteDirectory($this->tempWorkingTree);
        }

        parent::tearDown();
    }

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
    public function an_inertia_render_in_a_changed_member_is_noted_with_its_page_file(): void
    {
        $head = "<?php\nuse Inertia\\Inertia;\n\nclass PostController\n{\n    public function show(): mixed\n    {\n        return Inertia::render('Posts/Show');\n    }\n}\n";
        $base = "<?php\nuse Inertia\\Inertia;\n\nclass PostController\n{\n    public function show(): mixed\n    {\n        return null;\n    }\n}\n";
        $hunk = $this->hunk([[8, "        return Inertia::render('Posts/Show');"]], [[8, '        return null;']]);

        $result = ChangedSymbols::classifyFile('app/Http/Controllers/PostController.php', $head, $base, $hunk, inertiaPageChecker: new InertiaPageChecker(self::fixtureProjectPath()));

        $this->assertSame(
            ["renders Inertia page 'Posts/Show' (resources/js/Pages/Posts/Show.vue) — that page is part of this change's surface"],
            $result->findings,
        );
    }

    #[Test]
    public function an_inertia_render_in_an_untouched_sibling_member_is_not_noted(): void
    {
        $head = "<?php\nuse Inertia\\Inertia;\n\nclass PostController\n{\n    public function show(): mixed\n    {\n        return Inertia::render('Posts/Show');\n    }\n\n    public function touch(): int\n    {\n        return 1;\n    }\n}\n";
        $base = "<?php\nuse Inertia\\Inertia;\n\nclass PostController\n{\n    public function show(): mixed\n    {\n        return Inertia::render('Posts/Show');\n    }\n\n    public function touch(): int\n    {\n        return 0;\n    }\n}\n";
        $hunk = $this->hunk([[13, '        return 1;']], [[13, '        return 0;']]);

        $result = ChangedSymbols::classifyFile('app/Http/Controllers/PostController.php', $head, $base, $hunk, inertiaPageChecker: new InertiaPageChecker(self::fixtureProjectPath()));

        $this->assertSame([], $result->findings);
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

        // isNew: true — the diff's `--- /dev/null` marks a genuine new file (no base to read), so the
        // null base is legitimately additive, not an unreadable-base I/O failure.
        $result = ChangedSymbols::classifyFile('app/Jobs/NewJob.php', $head, baseSrc: null, hunk: $hunk, isNew: true);

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
    public function a_modification_with_an_unreadable_base_seeds_coarse_not_additive(): void
    {
        // A null base source on a diff that REMOVES lines is an unreadable base on a file that existed
        // — an I/O failure (a transient `git show` error, or a mis-rooted path in a nested-app repo),
        // NOT a new file (whose diff is additions only). Its members must NOT read as CHANGE_ADDED
        // (additive / no-impact): that silently under-selects a real modification. classifyFile()
        // seeds a coarse resolvable:false class change instead. (Without the guard, `bar` returns as a
        // resolvable additive member and the file reads additive-only — the forbidden under-selection.)
        $head = "<?php\n\nclass Foo\n{\n    public function bar(): int\n    {\n        return 1;\n    }\n}\n";
        $hunk = ['added' => [['line' => 7, 'text' => '        return 1;']], 'removed' => [['line' => 7, 'text' => '        return 0;']]];

        $result = ChangedSymbols::classifyFile('app/Foo.php', $head, baseSrc: null, hunk: $hunk);

        $this->assertFalse($result->hasOnlyAdditiveOrCosmeticChanges());
        $this->assertCount(1, $result->members);
        $this->assertSame(MemberChange::KIND_CLASS, $result->members[0]->kind);
        $this->assertFalse($result->members[0]->resolvable);
        $this->assertFalse($result->members[0]->isAdditive());
    }

    #[Test]
    public function an_addition_inside_an_existing_method_with_an_unreadable_base_seeds_coarse_not_additive(): void
    {
        // The sibling shape with NO removed lines: a statement ADDED inside an existing method whose
        // base is unreadable is still a modification, not a new file — it must not read as additive /
        // no-impact. Only `isNew` (the diff's `--- /dev/null`) makes a null base legitimately additive,
        // and this hunk carries no isNew, so it fails closed to a coarse class change.
        $head = "<?php\n\nclass Foo\n{\n    public function bar(): int\n    {\n        log_value();\n        return 1;\n    }\n}\n";
        $hunk = ['added' => [['line' => 7, 'text' => '        log_value();']], 'removed' => []];

        $result = ChangedSymbols::classifyFile('app/Foo.php', $head, baseSrc: null, hunk: $hunk);

        $this->assertFalse($result->hasOnlyAdditiveOrCosmeticChanges());
        $this->assertCount(1, $result->members);
        $this->assertSame(MemberChange::KIND_CLASS, $result->members[0]->kind);
        $this->assertFalse($result->members[0]->isAdditive());
    }

    #[Test]
    public function a_failing_git_show_for_an_adding_frontend_diff_reads_unresolved(): void
    {
        // The identical I/O failure on a frontend file must not scan as an empty string — that
        // would read as a determined "no references", the forbidden falsely-empty result.
        config()->set('richter.frontend.roots', ['resources/js']);

        $diff = "diff --git a/resources/js/Pages/Posts.vue b/resources/js/Pages/Posts.vue\n--- a/resources/js/Pages/Posts.vue\n+++ b/resources/js/Pages/Posts.vue\n@@ -0,0 +1,1 @@\n+<template>x</template>\n";

        Process::fake([
            '*merge-base*' => Process::result("abc123\n"),
            '*diff*' => Process::result($diff),
            '*show*' => Process::result(errorOutput: 'bad object', exitCode: 128),
        ]);

        $changed = ChangedSymbols::resolve('base-ref', 'head-ref');

        $this->assertCount(1, $changed);
        $this->assertTrue($changed[0]->unresolvedFrontendReferences);
        $this->assertSame([], $changed[0]->directSeeds);
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
    public function head_mode_still_surfaces_a_committed_edit_after_the_diff_form_changes(): void
    {
        // Regression pin: a committed edit vs base, working tree == HEAD (nothing uncommitted on
        // top), must classify exactly as it always has — the diff form change is a strict superset,
        // never a regression on the already-working committed case.
        $file = 'app/Http/Controllers/PostController.php';
        $before = "<?php\nclass PostController\n{\n    public function show(): int\n    {\n        return 1;\n    }\n}\n";
        $after = "<?php\nclass PostController\n{\n    public function show(): int\n    {\n        return 2;\n    }\n}\n";
        $line = $this->lineOf($before, '        return 1;');

        $tempDir = $this->useTempWorkingTree();
        file_put_contents("{$tempDir}/{$file}", $after);

        Process::fake([
            '*merge-base*' => Process::result("mergebasesha\n"),
            '*show*' => Process::result($before),
            '*diff*' => Process::result($this->diffHeader($file) . $this->unifiedHunk($line, ['        return 1;'], $line, ['        return 2;'])),
        ]);

        $changed = ChangedSymbols::resolve('some-base');

        $this->assertCount(1, $changed);
        $resolvable = $changed[0]->resolvableMembers();
        $this->assertCount(1, $resolvable);
        $this->assertSame('show', $resolvable[0]->name);
        $this->assertSame(MemberChange::CHANGE_MODIFIED, $resolvable[0]->change);
    }

    #[Test]
    public function a_historical_ref_still_diffs_the_three_dot_range_and_reads_head_source_via_git_show(): void
    {
        // Guards the untouched replay path: a non-HEAD ref (e.g. a benchmark fix commit) must keep
        // using the committed `<base>...<ref>` diff and read its head source via `git show`, never
        // the working tree — this is byte-for-byte unchanged by the HEAD-mode fix.
        $file = 'app/Http/Controllers/PostController.php';
        $mergeBaseSrc = "<?php\nclass PostController\n{\n    public function show(): int\n    {\n        return 1;\n    }\n}\n";
        $committedHeadSrc = "<?php\nclass PostController\n{\n    public function show(): int\n    {\n        return 2;\n    }\n}\n";
        $line = $this->lineOf($mergeBaseSrc, '        return 1;');
        $historicalRef = 'historical-fix-sha123';

        Process::fake([
            '*merge-base*' => Process::result("mergebasesha\n"),
            '*show*mergebasesha:*' => Process::result($mergeBaseSrc),
            "*show*{$historicalRef}:*" => Process::result($committedHeadSrc),
            '*diff*' => Process::result($this->diffHeader($file) . $this->unifiedHunk($line, ['        return 1;'], $line, ['        return 2;'])),
        ]);

        $changed = ChangedSymbols::resolve('some-base', $historicalRef);

        Process::assertRan(static fn (PendingProcess $process): bool => is_array($process->command) && end($process->command) === "some-base...{$historicalRef}");

        $this->assertCount(1, $changed);
        $resolvable = $changed[0]->resolvableMembers();
        $this->assertCount(1, $resolvable);
        $this->assertSame('show', $resolvable[0]->name);
        $this->assertSame(MemberChange::CHANGE_MODIFIED, $resolvable[0]->change);
    }

    #[Test]
    public function head_mode_surfaces_a_purely_uncommitted_edit(): void
    {
        // The false negative this plan closes: nothing is committed yet (the three-dot committed
        // diff is empty), but the working tree already carries the edit. HEAD mode must still see it.
        $file = 'app/Http/Controllers/PostController.php';
        $before = "<?php\nclass PostController\n{\n    public function show(): int\n    {\n        return 1;\n    }\n}\n";
        $after = "<?php\nclass PostController\n{\n    public function show(): int\n    {\n        return 2;\n    }\n}\n";
        $line = $this->lineOf($before, '        return 1;');

        $tempDir = $this->useTempWorkingTree();
        file_put_contents("{$tempDir}/{$file}", $after);

        Process::fake([
            '*merge-base*' => Process::result("mergebasesha\n"),
            '*show*' => Process::result($before),
            // The old, committed-only three-dot form sees nothing (unmatched here since nothing was
            // ever committed); the fixed single-ref form (matched by the fallback below) sees the edit.
            '*diff*...*' => Process::result(''),
            '*diff*' => Process::result($this->diffHeader($file) . $this->unifiedHunk($line, ['        return 1;'], $line, ['        return 2;'])),
        ]);

        $changed = ChangedSymbols::resolve('some-base');

        $this->assertCount(1, $changed);
        $resolvable = $changed[0]->resolvableMembers();
        $this->assertCount(1, $resolvable);
        $this->assertSame('show', $resolvable[0]->name);
        $this->assertSame(MemberChange::CHANGE_MODIFIED, $resolvable[0]->change);
    }

    #[Test]
    public function head_mode_keeps_a_committed_and_an_uncommitted_edit_in_the_same_file_mapped_to_the_right_members(): void
    {
        // Desync guard: `store()` carries only an uncommitted edit (invisible to the old three-dot
        // diff, which also shifts every line after it), `show()` carries an already-committed one.
        // Both must surface, each mapped to itself — not merged, not dropped.
        $file = 'app/Http/Controllers/PostController.php';
        $mergeBaseSrc = "<?php\nclass PostController\n{\n    public function store(): int\n    {\n        return 0;\n    }\n\n    public function show(): int\n    {\n        return 1;\n    }\n}\n";
        $workingTreeSrc = "<?php\nclass PostController\n{\n    public function store(): int\n    {\n        \$x = 1;\n        return 0;\n    }\n\n    public function show(): int\n    {\n        return 2;\n    }\n}\n";

        $storeOldLine = $this->lineOf($mergeBaseSrc, '        return 0;');
        $showOldLine = $this->lineOf($mergeBaseSrc, '        return 1;');
        $storeNewLine = $this->lineOf($workingTreeSrc, '        $x = 1;');
        $showNewLine = $this->lineOf($workingTreeSrc, '        return 2;');

        $tempDir = $this->useTempWorkingTree();
        file_put_contents("{$tempDir}/{$file}", $workingTreeSrc);

        // The fixed single-ref diff (working tree vs merge-base): both hunks, working-tree line numbers.
        $fullDiff = $this->diffHeader($file)
            . $this->unifiedHunk($storeOldLine, ['        return 0;'], $storeNewLine, ['        $x = 1;', '        return 0;'])
            . $this->unifiedHunk($showOldLine, ['        return 1;'], $showNewLine, ['        return 2;']);

        // The old three-dot committed-only diff: `store()` was never committed-changed, so it carries
        // no hunk at all — the silent false negative this plan closes.
        $committedOnlyDiff = $this->diffHeader($file)
            . $this->unifiedHunk($showOldLine, ['        return 1;'], $showOldLine, ['        return 2;']);

        Process::fake([
            '*merge-base*' => Process::result("mergebasesha\n"),
            '*show*' => Process::result($mergeBaseSrc),
            '*diff*...*' => Process::result($committedOnlyDiff),
            '*diff*' => Process::result($fullDiff),
        ]);

        $changed = ChangedSymbols::resolve('some-base');

        $this->assertCount(1, $changed);
        $resolvable = $changed[0]->resolvableMembers();
        $names = array_map(static fn (MemberChange $member): string => $member->name, $resolvable);
        sort($names);

        $this->assertSame(['show', 'store'], $names);

        foreach ($resolvable as $member) {
            $this->assertSame(MemberChange::CHANGE_MODIFIED, $member->change);
        }
    }

    #[Test]
    public function a_changed_file_with_a_broken_eager_load_string_carries_a_finding(): void
    {
        $head = "<?php\nnamespace App\Exports;\nuse App\Models\Post;\nclass Foo\n{\n    public function bar(): void\n    {\n        \$this->post->load([Post::COMMENTS . Post::REVIEWS]);\n    }\n}\n";
        $base = "<?php\nnamespace App\Exports;\nuse App\Models\Post;\nclass Foo\n{\n    public function bar(): void\n    {\n    }\n}\n";
        $hunk = $this->hunk([[8, '        $this->post->load([Post::COMMENTS . Post::REVIEWS]);']], []);

        $checker = new EagerLoadStringChecker(self::fixtureProjectPath() . '/app/Models');
        $result = ChangedSymbols::classifyFile('app/Exports/Foo.php', $head, $base, $hunk, $checker);

        $this->assertCount(1, $result->findings);
        $this->assertStringContainsString('commentsreviews', $result->findings[0]);
    }

    #[Test]
    public function a_cosmetic_only_change_is_not_checked_for_findings(): void
    {
        // Same broken eager-load string on both sides — the change itself is whitespace-only, so the
        // checker must not fire on pre-existing code the diff didn't touch behaviourally.
        $head = "<?php\nnamespace App\Exports;\nuse App\Models\Post;\nclass Foo\n{\n    public function bar(): void\n    {\n        \$this->post->load([Post::COMMENTS . Post::REVIEWS]);\n    }\n}\n";
        $hunk = $this->hunk([[8, '        $this->post->load([Post::COMMENTS . Post::REVIEWS]);']], [[8, '  $this->post->load([Post::COMMENTS . Post::REVIEWS]);']]);

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

    /**
     * Creates a disposable directory and points `base_path()` at it, so `headSource()`'s HEAD-mode
     * disk read (`file_get_contents(base_path($file))`) exercises a real file the test controls,
     * independent of this package's own working tree. {@see tearDown()} deletes it afterward.
     */
    private function useTempWorkingTree(): string
    {
        $this->tempWorkingTree = sys_get_temp_dir() . '/richter-working-tree-diff-' . bin2hex(random_bytes(8));
        mkdir("{$this->tempWorkingTree}/app/Http/Controllers", recursive: true);

        $app = $this->app;
        $this->assertInstanceOf(Application::class, $app);
        $app->setBasePath($this->tempWorkingTree);

        return $this->tempWorkingTree;
    }

    private function diffHeader(string $file): string
    {
        return "diff --git a/{$file} b/{$file}\n--- a/{$file}\n+++ b/{$file}\n";
    }

    /**
     * One `-U0` hunk: a header plus its removed lines then added lines, matching real git's ordering
     * for a single contiguous replacement (also the convention the benchmark-replay fixture uses).
     *
     * @param  list<string>  $removed
     * @param  list<string>  $added
     */
    private function unifiedHunk(int $oldStart, array $removed, int $newStart, array $added): string
    {
        $body = [
            ...array_map(static fn (string $line): string => "-{$line}", $removed),
            ...array_map(static fn (string $line): string => "+{$line}", $added),
        ];

        return "@@ -{$oldStart}," . count($removed) . " +{$newStart}," . count($added) . " @@\n" . implode("\n", $body) . "\n";
    }

    /** The 1-indexed line number of the (unique) line matching `$needle`, via array_search like the benchmark-replay fixture — avoids hand-counted lines drifting from the source string. */
    private function lineOf(string $source, string $needle): int
    {
        $index = array_search($needle, explode("\n", $source), true);
        $this->assertIsInt($index);

        return $index + 1;
    }

    #[Test]
    public function an_existing_model_carries_its_field_set_and_added_names(): void
    {
        $base = "<?php\nclass Post\n{\n    protected \$fillable = ['title'];\n}\n";
        $head = "<?php\nclass Post\n{\n    protected \$fillable = ['title', 'layout'];\n}\n";
        $hunk = $this->hunk([[4, "    protected \$fillable = ['title', 'layout'];"]], [[4, "    protected \$fillable = ['title'];"]]);

        $result = ChangedSymbols::classifyFile('app/Models/Post.php', $head, $base, $hunk);

        $this->assertSame(['title', 'layout'], $result->modelFieldSet);
        $this->assertSame(['layout'], $result->addedModelFields);
    }

    #[Test]
    public function a_mixed_rename_and_add_still_reports_the_added_field(): void
    {
        // slug -> heading is a rename (a real change, not additive); layout is a genuine add. The
        // added field must still surface regardless of how the edit as a whole classifies.
        $base = "<?php\nclass Post\n{\n    protected \$fillable = ['slug'];\n}\n";
        $head = "<?php\nclass Post\n{\n    protected \$fillable = ['heading', 'layout'];\n}\n";
        $hunk = $this->hunk([[4, "    protected \$fillable = ['heading', 'layout'];"]], [[4, "    protected \$fillable = ['slug'];"]]);

        $result = ChangedSymbols::classifyFile('app/Models/Post.php', $head, $base, $hunk);

        $this->assertTrue($result->needsCoarseSeed());
        $this->assertSame(['heading', 'layout'], $result->addedModelFields);
    }

    #[Test]
    public function a_brand_new_model_file_carries_no_field_data(): void
    {
        $head = "<?php\nclass Post\n{\n    protected \$fillable = ['title'];\n}\n";
        $hunk = ['added' => [[4, "    protected \$fillable = ['title'];"]], 'removed' => []];
        $hunk = $this->hunk($hunk['added'], $hunk['removed']);

        $result = ChangedSymbols::classifyFile('app/Models/Post.php', $head, baseSrc: null, hunk: $hunk, isNew: true);

        $this->assertSame([], $result->modelFieldSet);
        $this->assertSame([], $result->addedModelFields);
    }

    #[Test]
    public function an_unreadable_base_on_an_existing_model_carries_no_field_data(): void
    {
        $head = "<?php\nclass Post\n{\n    protected \$fillable = ['title'];\n}\n";
        $hunk = $this->hunk([[4, "    protected \$fillable = ['title'];"]], [[4, "    protected \$fillable = ['x'];"]]);

        $result = ChangedSymbols::classifyFile('app/Models/Post.php', $head, baseSrc: null, hunk: $hunk);

        // The unreadable-base guard already coarse-seeds before field capture ever runs.
        $this->assertTrue($result->needsCoarseSeed());
        $this->assertSame([], $result->modelFieldSet);
        $this->assertSame([], $result->addedModelFields);
    }

    #[Test]
    public function a_non_model_file_carries_no_field_data(): void
    {
        $base = "<?php\nclass Foo\n{\n    protected \$fillable = ['title'];\n}\n";
        $head = "<?php\nclass Foo\n{\n    protected \$fillable = ['title', 'layout'];\n}\n";
        $hunk = $this->hunk([[4, "    protected \$fillable = ['title', 'layout'];"]], [[4, "    protected \$fillable = ['title'];"]]);

        $result = ChangedSymbols::classifyFile('app/Foo.php', $head, $base, $hunk);

        $this->assertSame([], $result->modelFieldSet);
        $this->assertSame([], $result->addedModelFields);
    }
}
