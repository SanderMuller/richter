<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Support\ScopedRebuild;
use SanderMuller\Richter\Tests\TestCase;

/**
 * One case per row of the spec's comparison table. Every "null" case below is a full build — the
 * behaviour richter has always had — while the one positive case is the only door to a scoped one, so
 * these tests are the whole soundness argument for the feature.
 */
final class ScopedRebuildTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = (string) tempnam(sys_get_temp_dir(), 'richter-scope-');
        unlink($this->root);
        mkdir($this->root . '/app', recursive: true);
        file_put_contents($this->root . '/app/A.php', '<?php');
        file_put_contents($this->root . '/app/B.php', '<?php');
        mkdir($this->root . '/routes', recursive: true);
        file_put_contents($this->root . '/routes/web.php', '<?php');
    }

    protected function tearDown(): void
    {
        foreach (['app/A.php', 'app/B.php', 'routes/web.php'] as $file) {
            @unlink("{$this->root}/{$file}");
        }

        @rmdir($this->root . '/app');
        @rmdir($this->root . '/routes');
        @rmdir($this->root);

        parent::tearDown();
    }

    /**
     * @param  array<string, string>  $files
     * @return array{nonFile: array<string, mixed>, files: array<string, string>}
     */
    private function record(array $files, string $brain = '2.4.0'): array
    {
        return ['nonFile' => ['format' => 16, 'php' => '8.4.0', 'richter' => '0.31.0', 'brain' => $brain, 'config' => '{}'], 'files' => $files];
    }

    /**
     * The provenance set for the fixture, in the un-realpath'd form Brain's `data['file']` carries.
     *
     * @return array<string, true>
     */
    private function provenance(string ...$relative): array
    {
        $files = [];

        foreach ($relative === [] ? ['app/A.php', 'app/B.php', 'routes/web.php'] : $relative as $path) {
            $files["{$this->root}/{$path}"] = true;
        }

        return $files;
    }

    #[Test]
    public function one_changed_app_file_scopes_to_that_file(): void
    {
        $files = ScopedRebuild::filesFor(
            $this->record(['app/A.php' => 'h1', 'app/B.php' => 'h2', 'routes/web.php' => 'h3']),
            $this->record(['app/A.php' => 'CHANGED', 'app/B.php' => 'h2', 'routes/web.php' => 'h3']),
            $this->root,
            $this->provenance(),
        );

        // Not realpath()'d: Brain's soundness check matches these against node provenance verbatim,
        // and a resolved path misses it on any system where the project sits behind a symlink.
        $this->assertSame(["{$this->root}/app/A.php"], $files);
    }

    #[Test]
    public function a_path_the_previous_graph_knows_nothing_about_refuses_the_whole_scope(): void
    {
        // The most dangerous failure this class can have, and it is not hypothetical — it was
        // observed. Brain's soundness check compares the given paths verbatim against node
        // provenance, so a path it cannot match yields an EMPTY owned-edge set on both sides. Empty
        // equals empty, the check passes, nothing is substituted, and the previous graph is handed
        // back as though it were current: a green run against a stale graph.
        $files = ScopedRebuild::filesFor(
            $this->record(['app/A.php' => 'h1', 'app/B.php' => 'h2']),
            $this->record(['app/A.php' => 'CHANGED', 'app/B.php' => 'ALSO_CHANGED']),
            $this->root,
            $this->provenance('app/A.php'),
        );

        $this->assertNull($files, 'app/B.php is absent from the provenance, so the whole scope is refused');
    }

    #[Test]
    public function no_previous_record_is_a_full_build(): void
    {
        $this->assertNull(ScopedRebuild::filesFor(null, $this->record(['app/A.php' => 'h1']), $this->root, $this->provenance()));
    }

    #[Test]
    public function a_package_version_change_is_a_full_build(): void
    {
        $this->assertNull(ScopedRebuild::filesFor(
            $this->record(['app/A.php' => 'h1'], brain: '2.4.0'),
            $this->record(['app/A.php' => 'CHANGED'], brain: '2.5.0'),
            $this->root,
            $this->provenance(),
        ));
    }

    #[Test]
    public function a_config_change_is_a_full_build(): void
    {
        $previous = $this->record(['app/A.php' => 'h1']);
        $current = $this->record(['app/A.php' => 'CHANGED']);
        $current['nonFile']['config'] = '{"second_hop":false}';

        $this->assertNull(ScopedRebuild::filesFor($previous, $current, $this->root, $this->provenance()));
    }

    #[Test]
    public function an_added_file_is_a_full_build(): void
    {
        $this->assertNull(ScopedRebuild::filesFor(
            $this->record(['app/A.php' => 'h1']),
            $this->record(['app/A.php' => 'h1', 'app/B.php' => 'h2']),
            $this->root,
            $this->provenance(),
        ));
    }

    #[Test]
    public function a_deleted_file_is_a_full_build(): void
    {
        $this->assertNull(ScopedRebuild::filesFor(
            $this->record(['app/A.php' => 'h1', 'app/B.php' => 'h2']),
            $this->record(['app/A.php' => 'h1']),
            $this->root,
            $this->provenance(),
        ));
    }

    #[Test]
    public function a_change_outside_app_is_a_full_build(): void
    {
        $this->assertNull(ScopedRebuild::filesFor(
            $this->record(['app/A.php' => 'h1', 'routes/web.php' => 'h3']),
            $this->record(['app/A.php' => 'h1', 'routes/web.php' => 'CHANGED']),
            $this->root,
            $this->provenance(),
        ));
    }

    #[Test]
    public function an_app_change_alongside_a_routes_change_is_a_full_build(): void
    {
        // The dangerous near-miss: something IS scopeable here, and taking only the app file would
        // merge a scoped pass onto a graph whose routes no longer match.
        $this->assertNull(ScopedRebuild::filesFor(
            $this->record(['app/A.php' => 'h1', 'routes/web.php' => 'h3']),
            $this->record(['app/A.php' => 'CHANGED', 'routes/web.php' => 'CHANGED']),
            $this->root,
            $this->provenance(),
        ));
    }

    #[Test]
    public function a_reordered_file_list_is_not_an_added_file(): void
    {
        // Both records come from one sorted list, so this cannot happen today — but comparing key
        // ORDER rather than the key SET would report `file-set-changed` for a project where nothing
        // was added or deleted, and that reason names the one thing Brain actually refuses.
        $decision = ScopedRebuild::decide(
            $this->record(['app/B.php' => 'h2', 'app/A.php' => 'h1']),
            $this->record(['app/A.php' => 'CHANGED', 'app/B.php' => 'h2']),
            $this->root,
            $this->provenance(),
        );

        $this->assertSame(["{$this->root}/app/A.php"], $decision->files);
    }

    #[Test]
    public function each_refusal_names_the_precondition_that_said_no(): void
    {
        // Six ways to refuse all reported as the same `full` label, which is how "the scoped path
        // never engages" arrived as a report that could not be diagnosed from outside the process.
        // The slug is the diagnosis, so it is what these assert.
        $previous = $this->record(['app/A.php' => 'h1', 'app/B.php' => 'h2', 'routes/web.php' => 'h3']);
        $withNewerBrain = $this->record(['app/A.php' => 'CHANGED', 'app/B.php' => 'h2', 'routes/web.php' => 'h3'], brain: '2.5.0');

        $this->assertSame('no-merge-base', ScopedRebuild::decide(null, $previous, $this->root, $this->provenance())->reason);
        $this->assertSame('inputs-changed', ScopedRebuild::decide($previous, $withNewerBrain, $this->root, $this->provenance())->reason);
        $this->assertSame('file-set-changed', ScopedRebuild::decide(
            $previous,
            $this->record(['app/A.php' => 'h1', 'app/B.php' => 'h2', 'routes/web.php' => 'h3', 'app/C.php' => 'h4']),
            $this->root,
            $this->provenance(),
        )->reason);
        $this->assertSame('non-app-change', ScopedRebuild::decide(
            $previous,
            $this->record(['app/A.php' => 'h1', 'app/B.php' => 'h2', 'routes/web.php' => 'CHANGED']),
            $this->root,
            $this->provenance(),
        )->reason);
        $this->assertSame('no-change', ScopedRebuild::decide($previous, $previous, $this->root, $this->provenance())->reason);
        $this->assertSame('not-in-provenance', ScopedRebuild::decide(
            $previous,
            $this->record(['app/A.php' => 'CHANGED', 'app/B.php' => 'h2', 'routes/web.php' => 'h3']),
            $this->root,
            $this->provenance('app/B.php'),
        )->reason);

        // And the positive case reports no reason at all, so a caller can branch on either field.
        $accepted = ScopedRebuild::decide(
            $previous,
            $this->record(['app/A.php' => 'CHANGED', 'app/B.php' => 'h2', 'routes/web.php' => 'h3']),
            $this->root,
            $this->provenance(),
        );
        $this->assertNull($accepted->reason);
        $this->assertNull($accepted->detail);
    }

    #[Test]
    public function the_inputs_changed_detail_names_the_input_and_both_versions(): void
    {
        $decision = ScopedRebuild::decide(
            $this->record(['app/A.php' => 'h1'], brain: '2.4.0'),
            $this->record(['app/A.php' => 'CHANGED'], brain: '2.5.0'),
            $this->root,
            $this->provenance(),
        );

        $this->assertSame('differing non-file inputs: brain (2.4.0 → 2.5.0)', $decision->detail);
    }

    #[Test]
    public function a_non_file_input_the_cached_record_carries_and_this_one_does_not_is_named(): void
    {
        // A record written by a version that hashed an input this one dropped. Reading only the
        // current record's keys would find nothing differing and print "key order only" — a sentence
        // saying nothing differs, on the path taken because something did.
        $previous = $this->record(['app/A.php' => 'h1']);
        $previous['nonFile']['retired_input'] = 'yes';

        $decision = ScopedRebuild::decide($previous, $this->record(['app/A.php' => 'CHANGED']), $this->root, $this->provenance());

        $this->assertSame('differing non-file inputs: retired_input', $decision->detail);
    }

    #[Test]
    public function the_non_app_detail_names_the_offending_path(): void
    {
        $decision = ScopedRebuild::decide(
            $this->record(['app/A.php' => 'h1', 'routes/web.php' => 'h3']),
            $this->record(['app/A.php' => 'CHANGED', 'routes/web.php' => 'CHANGED']),
            $this->root,
            $this->provenance(),
        );

        $this->assertSame('routes/web.php differs from the cached graph and sits outside app/', $decision->detail);
    }

    #[Test]
    public function the_provenance_detail_shows_the_form_the_previous_graph_knows(): void
    {
        // The reason this detail exists. A provenance path differing from the refused one only by
        // prefix — a resolved `/private/var` against an unresolved `/var`, a symlinked project root —
        // is indistinguishable from the file being genuinely absent unless both forms are printed
        // side by side, and the two have nothing in common as problems.
        $decision = ScopedRebuild::decide(
            $this->record(['app/A.php' => 'h1']),
            $this->record(['app/A.php' => 'CHANGED']),
            $this->root,
            ["/elsewhere{$this->root}/app/A.php" => true],
        );

        $this->assertSame(
            "{$this->root}/app/A.php is absent from the previous graph's provenance, which knows /elsewhere{$this->root}/app/A.php",
            $decision->detail,
        );
    }

    #[Test]
    public function a_provenance_without_that_basename_says_so_instead_of_naming_an_unrelated_path(): void
    {
        $decision = ScopedRebuild::decide(
            $this->record(['app/A.php' => 'h1']),
            $this->record(['app/A.php' => 'CHANGED']),
            $this->root,
            $this->provenance('app/B.php'),
        );

        $this->assertSame(
            "{$this->root}/app/A.php is absent from the previous graph's provenance, which has no path of that name at all",
            $decision->detail,
        );
    }

    #[Test]
    public function an_identical_record_is_a_full_build_rather_than_an_empty_scope(): void
    {
        // Unreachable through `graph()` (identical inputs hash equal and serve the entry whole), but
        // a zero-file scope re-emits the previous graph unchanged, so it must never be reachable at
        // all — this pins the guard rather than the caller's discipline.
        $this->assertNull(ScopedRebuild::filesFor(
            $this->record(['app/A.php' => 'h1']),
            $this->record(['app/A.php' => 'h1']),
            $this->root,
            $this->provenance(),
        ));
    }
}
