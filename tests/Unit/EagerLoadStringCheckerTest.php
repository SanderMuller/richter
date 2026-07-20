<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Tests\TestCase;
use SanderMuller\Richter\Tracers\EagerLoadStringChecker;

final class EagerLoadStringCheckerTest extends TestCase
{
    /** @return list<string> */
    private function findings(string $body): array
    {
        $source = "<?php\nnamespace App\Exports;\nuse App\Models\Post;\nuse App\Models\Review;\nclass PostExport\n{\n    public function __construct(private Post \$post)\n    {\n        {$body}\n    }\n}\n";

        return new EagerLoadStringChecker(self::fixtureProjectPath() . '/app/Models')->findingsFor($source);
    }

    #[Test]
    public function a_valid_relation_constant_produces_no_finding(): void
    {
        $this->assertSame([], $this->findings('$this->post->load([Post::COMMENTS, Post::REVIEWS . \'.\' . Review::ANSWERS]);'));
    }

    #[Test]
    public function a_broken_constant_concatenation_is_flagged(): void
    {
        // A missing comma concatenates two relation constants into one invalid name.
        $findings = $this->findings('$this->post->load([Post::COMMENTS . Post::REVIEWS . \'.\' . Review::ANSWERS]);');

        $this->assertCount(1, $findings);
        $this->assertStringContainsString("'commentsreviews'", $findings[0]);
    }

    #[Test]
    public function a_plain_string_without_a_model_constant_is_not_checked(): void
    {
        $this->assertSame([], $this->findings('$this->post->load([\'definitelyNotARelation\']);'));
    }

    #[Test]
    public function a_typo_in_a_string_concatenated_with_a_model_constant_is_flagged(): void
    {
        $findings = $this->findings('$this->post->load(Post::REVIEWS . \'.answerz\');');

        $this->assertCount(1, $findings);
        $this->assertStringContainsString("'answerz'", $findings[0]);
    }

    #[Test]
    public function an_array_key_relation_with_a_closure_constraint_is_checked(): void
    {
        $findings = $this->findings('$this->post->load([Post::REVIEWS . \'x\' => fn ($q) => $q]);');

        $this->assertCount(1, $findings);
        $this->assertStringContainsString("'reviewsx'", $findings[0]);
    }

    #[Test]
    public function a_dynamic_argument_is_skipped(): void
    {
        $this->assertSame([], $this->findings('$this->post->load($this->relations());'));
    }

    #[Test]
    public function a_column_selection_suffix_is_ignored(): void
    {
        $this->assertSame([], $this->findings('$this->post->load(Post::REVIEWS . \':id,title\');'));
    }

    #[Test]
    public function a_non_load_call_is_not_checked(): void
    {
        $this->assertSame([], $this->findings('$this->post->update([Post::REVIEWS . \'zz\' => 1]);'));
    }

    #[Test]
    public function a_bare_has_call_is_not_checked(): void
    {
        // `has` is overloaded (Request, Session, Collection) — a model column constant passed to it
        // must not fire; the tracer still follows it for reach.
        $this->assertSame([], $this->findings('$request->has(Post::REVIEWS . \'zz\');'));
    }

    #[Test]
    public function a_with_only_call_is_checked(): void
    {
        $findings = $this->findings('$this->post->withOnly(Post::REVIEWS . \'zz\');');

        $this->assertCount(1, $findings);
        $this->assertStringContainsString("'reviewszz'", $findings[0]);
    }

    #[Test]
    public function an_incomplete_model_set_reports_a_single_visible_skip_note_instead_of_findings(): void
    {
        // A models directory whose classes cannot be autoloaded degrades the checker: it must
        // suppress validation visibly (one note per file), never silently — and never fire false
        // alarms from the shrunken set.
        $checker = new EagerLoadStringChecker(dirname(__DIR__) . '/Fixtures/broken-models');

        $source = "<?php\nnamespace App\Exports;\nuse App\Models\Post;\nclass PostExport\n{\n    public function a(): void { \$x->load(Post::REVIEWS . 'zz'); }\n    public function b(): void { \$x->load(Post::COMMENTS . 'yy'); }\n}\n";

        $findings = $checker->findingsFor($source);

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('eager-load check skipped', $findings[0]);
    }

    #[Test]
    public function a_nonexistent_models_directory_degrades_to_the_skip_note(): void
    {
        $checker = new EagerLoadStringChecker(self::fixtureProjectPath() . '/does-not-exist');

        $source = "<?php\nnamespace App\Exports;\nuse App\Models\Post;\nclass PostExport\n{\n    public function a(): void { \$x->load(Post::REVIEWS . 'zz'); }\n}\n";

        $findings = $checker->findingsFor($source);

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('eager-load check skipped', $findings[0]);
    }

    #[Test]
    public function a_relation_added_between_runs_is_seen_by_the_next_run(): void
    {
        // A disposable models tree, unique per run: the classes are declared via require (the
        // composer autoloader cannot see a temp dir), and unique names avoid redeclaration.
        $suffix = bin2hex(random_bytes(8));
        $modelsPath = sys_get_temp_dir() . '/richter-eager-load-stale-' . $suffix;
        mkdir($modelsPath, recursive: true);

        try {
            $alpha = "Alpha{$suffix}";
            file_put_contents("{$modelsPath}/{$alpha}.php", "<?php\nnamespace App\\Models;\nclass {$alpha}\n{\n    public const string ALPHA = 'alpha';\n    public function alpha(): void {}\n}\n");
            require "{$modelsPath}/{$alpha}.php";

            $alphaSource = "<?php\nnamespace App\\Exports;\nuse App\\Models\\{$alpha};\nclass Export\n{\n    public function a(): void { \$x->load({$alpha}::ALPHA); }\n}\n";
            // A complete first scan of the tree — a first "run" in a long-lived process.
            $this->assertSame([], new EagerLoadStringChecker($modelsPath)->findingsFor($alphaSource));

            // A developer adds a relation mid-session, after the first scan.
            $beta = "Beta{$suffix}";
            file_put_contents("{$modelsPath}/{$beta}.php", "<?php\nnamespace App\\Models;\nclass {$beta}\n{\n    public const string BETA = 'beta';\n    public function beta(): void {}\n}\n");
            require "{$modelsPath}/{$beta}.php";

            $betaSource = "<?php\nnamespace App\\Exports;\nuse App\\Models\\{$beta};\nclass Export\n{\n    public function a(): void { \$x->load({$beta}::BETA); }\n}\n";

            // A new checker instance (a new run) rebuilds the set, so the new relation is valid —
            // no process-lifetime cache may serve the first scan's stale set as a false alarm.
            $this->assertSame([], new EagerLoadStringChecker($modelsPath)->findingsFor($betaSource));
        } finally {
            new Filesystem()->deleteDirectory($modelsPath);
        }
    }

    #[Test]
    public function checkers_pointed_at_different_model_trees_never_share_a_method_set(): void
    {
        // Deterministic regardless of test order: a complete set built from the fixture tree must
        // not be served to a checker pointed at the unloadable tree (which must keep degrading).
        $source = "<?php\nnamespace App\Exports;\nuse App\Models\Post;\nclass PostExport\n{\n    public function a(): void { \$x->load(Post::REVIEWS . 'zz'); }\n}\n";

        $fixtureFindings = new EagerLoadStringChecker(self::fixtureProjectPath() . '/app/Models')->findingsFor($source);
        $brokenFindings = new EagerLoadStringChecker(dirname(__DIR__) . '/Fixtures/broken-models')->findingsFor($source);

        $this->assertCount(1, $fixtureFindings);
        $this->assertStringContainsString("'reviewszz'", $fixtureFindings[0]);
        $this->assertCount(1, $brokenFindings);
        $this->assertStringContainsString('eager-load check skipped', $brokenFindings[0]);
    }
}
