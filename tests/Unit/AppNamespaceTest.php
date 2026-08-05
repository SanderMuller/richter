<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use Illuminate\Foundation\Application;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Analysis\TestReferenceIndex;
use SanderMuller\Richter\Graph\CodeGraphBuilder;
use SanderMuller\Richter\Support\AppNamespace;
use SanderMuller\Richter\Support\Fqcn;
use SanderMuller\Richter\Support\RichterConfig;
use SanderMuller\Richter\Tests\TestCase;
use SanderMuller\Richter\Tracers\PolicyEdgeTracer;
use SanderMuller\Richter\Tracers\ReferenceEdgeTracer;

/**
 * `App\` is Laravel's skeleton default, not a requirement: an app may map any PSR-4 root to `app/`.
 * These cover the derivation and every gate that used to hardcode the literal — on such an app the
 * old behaviour was a silent under-report, which is the one failure mode this package must not have.
 */
final class AppNamespaceTest extends TestCase
{
    private string $tempRoot = '';

    protected function tearDown(): void
    {
        if ($this->tempRoot !== '' && is_dir($this->tempRoot)) {
            $this->deleteTree($this->tempRoot);
        }

        AppNamespace::flush();

        parent::tearDown();
    }

    #[Test]
    public function it_defaults_to_the_laravel_skeleton_root(): void
    {
        $this->assertSame('App\\', AppNamespace::root());
    }

    #[Test]
    public function it_falls_back_to_the_default_root_without_a_composer_json(): void
    {
        $this->useProjectRoot(composerJson: null);

        $this->assertSame('App\\', AppNamespace::root());
    }

    #[Test]
    public function it_derives_a_non_default_root_from_the_psr4_map(): void
    {
        $this->useProjectRoot(['autoload' => ['psr-4' => ['Acme\\' => 'app/']]]);

        $this->assertSame('Acme\\', AppNamespace::root());
    }

    #[Test]
    public function it_reads_a_psr4_target_given_as_a_list_or_without_a_trailing_slash(): void
    {
        $this->useProjectRoot(['autoload' => ['psr-4' => ['Acme\\' => ['app', 'src/legacy']]]]);

        $this->assertSame('Acme\\', AppNamespace::root());
    }

    #[Test]
    public function it_keeps_the_default_root_when_the_app_maps_both(): void
    {
        // A partially-migrated codebase: `App\` is what richter traced before, and switching roots
        // here would move the traced half rather than widen it.
        $this->useProjectRoot(['autoload' => ['psr-4' => ['App\\' => 'app/', 'Acme\\' => 'app/']]]);

        $this->assertSame('App\\', AppNamespace::root());
    }

    #[Test]
    public function it_falls_back_when_two_non_default_roots_both_map_to_app(): void
    {
        $this->useProjectRoot(['autoload' => ['psr-4' => ['Acme\\' => 'app/', 'Other\\' => 'app/']]]);

        $this->assertSame('App\\', AppNamespace::root());
    }

    #[Test]
    public function it_ignores_psr4_roots_that_do_not_map_to_app(): void
    {
        $this->useProjectRoot(['autoload' => ['psr-4' => ['Acme\\Support\\' => 'src/', 'Database\\Factories\\' => 'database/factories/']]]);

        $this->assertSame('App\\', AppNamespace::root());
    }

    #[Test]
    public function it_survives_an_unreadable_composer_json(): void
    {
        $root = $this->useProjectRoot(composerJson: null);
        file_put_contents("{$root}/composer.json", '{ not json');

        $this->assertSame('App\\', AppNamespace::root());
    }

    #[Test]
    public function it_survives_a_composer_json_whose_autoload_keys_are_not_maps(): void
    {
        // A scalar where a map belongs must read as "nothing to derive": reaching blind through
        // `['autoload']['psr-4']` on a string offsets the string instead, and a host app's
        // composer.json is not ours to trust the shape of.
        $this->useProjectRoot(['autoload' => 'nope']);

        $this->assertSame('App\\', AppNamespace::root());
    }

    #[Test]
    public function it_survives_a_composer_json_whose_psr4_entry_is_not_a_map(): void
    {
        $this->useProjectRoot(['autoload' => ['psr-4' => 'nope']]);

        $this->assertSame('App\\', AppNamespace::root());
    }

    #[Test]
    public function the_configured_root_overrides_the_derived_one(): void
    {
        $this->useProjectRoot(['autoload' => ['psr-4' => ['Acme\\' => 'app/']]]);
        config()->set('richter.root_namespace', 'Other\\Root');

        $this->assertSame('Other\\Root\\', AppNamespace::root());
    }

    #[Test]
    public function it_normalises_the_configured_root(): void
    {
        config()->set('richter.root_namespace', '\\Acme\\');

        $this->assertSame('Acme\\', RichterConfig::rootNamespace());
        $this->assertSame('Acme\\', AppNamespace::root());
    }

    #[Test]
    public function an_unusable_configured_root_throws_rather_than_reverting_to_the_default(): void
    {
        config()->set('richter.root_namespace', '9Acme');

        $this->expectException(InvalidArgumentException::class);

        AppNamespace::root();
    }

    #[Test]
    public function a_non_string_configured_root_throws(): void
    {
        config()->set('richter.root_namespace', ['Acme\\']);

        $this->expectException(InvalidArgumentException::class);

        RichterConfig::rootNamespace();
    }

    #[Test]
    public function it_notes_a_root_composer_json_does_not_corroborate(): void
    {
        $this->useProjectRoot(['autoload' => ['psr-4' => ['Acme\\' => 'app/']]]);
        config()->set('richter.root_namespace', 'Wrong\\');

        $note = AppNamespace::unmatchedRootNote();

        $this->assertIsString($note);
        $this->assertStringContainsString('Wrong\\', $note);
        $this->assertStringContainsString('"Acme\\"', $note);
    }

    #[Test]
    public function it_notes_nothing_when_the_root_is_corroborated(): void
    {
        $this->useProjectRoot(['autoload' => ['psr-4' => ['Acme\\' => 'app/']]]);

        $this->assertNull(AppNamespace::unmatchedRootNote());
    }

    #[Test]
    public function it_notes_that_only_one_of_several_app_roots_is_traced(): void
    {
        $this->useProjectRoot(['autoload' => ['psr-4' => ['App\\' => 'app/', 'Acme\\' => 'app/']]]);

        $note = AppNamespace::unmatchedRootNote();

        $this->assertIsString($note);
        $this->assertStringContainsString('2 root namespaces', $note);
        $this->assertStringContainsString('traced "App\\"', $note);
    }

    #[Test]
    public function it_notes_nothing_when_composer_json_maps_no_app_root(): void
    {
        $this->useProjectRoot(['autoload' => ['psr-4' => ['Acme\\Support\\' => 'src/']]]);

        $this->assertNull(AppNamespace::unmatchedRootNote());
    }

    #[Test]
    public function a_changed_file_path_resolves_to_the_derived_root(): void
    {
        $this->useProjectRoot(['autoload' => ['psr-4' => ['Acme\\' => 'app/']]]);

        $this->assertSame('Acme\\Console\\Commands\\Import\\RunImport', Fqcn::fromPath('app/Console/Commands/Import/RunImport.php'));
        $this->assertSame('Acme\\Models\\Post', Fqcn::fromPath('./app/Models/Post.php'));
        // Outside app/ nothing is root-namespaced, exactly as before.
        $this->assertSame('helpers', Fqcn::fromPath('bootstrap/helpers.php'));
    }

    #[Test]
    public function the_app_class_gate_follows_the_derived_root(): void
    {
        $this->useProjectRoot(['autoload' => ['psr-4' => ['Acme\\' => 'app/']]]);

        $this->assertTrue(AppNamespace::isAppClass('Acme\\Models\\Post'));
        $this->assertFalse(AppNamespace::isAppClass('App\\Models\\Post'));
        $this->assertFalse(AppNamespace::isAppClass('Acme\\Models\\Post::save'));
        $this->assertFalse(AppNamespace::isAppClass('route::posts.index'));
        $this->assertSame('Acme\\Models\\Post', AppNamespace::declaringClassOf('Acme\\Models\\Post::save'));
        $this->assertNull(AppNamespace::declaringClassOf('App\\Models\\Post::save'));
        $this->assertSame('Models/Post', AppNamespace::relativePath('Acme\\Models\\Post'));
    }

    #[Test]
    public function member_nodes_still_link_to_their_declaring_class(): void
    {
        $this->useProjectRoot(['autoload' => ['psr-4' => ['Acme\\' => 'app/']]]);

        $edges = CodeGraphBuilder::declaresEdges([
            ['source' => 'route::posts.store', 'target' => 'Acme\\Http\\Controllers\\PostController::store', 'type' => 'route-to-controller'],
        ]);

        $this->assertSame([[
            'source' => 'Acme\\Http\\Controllers\\PostController',
            'target' => 'Acme\\Http\\Controllers\\PostController::store',
            'type' => 'declares',
        ]], $edges);
    }

    #[Test]
    public function a_policy_reference_under_the_derived_root_still_emits_an_authorizes_edge(): void
    {
        $this->useProjectRoot(['autoload' => ['psr-4' => ['Acme\\' => 'app/']]]);

        $source = "<?php\nnamespace Acme\\Http\\Controllers;\nuse Acme\\Policies\\PostPolicy;\nclass PostController\n{\n    public function __invoke(): void\n    {\n        \$user->can(PostPolicy::UPDATE, \$post);\n    }\n}\n";

        $edges = new PolicyEdgeTracer()->edgesForSource($source, 'Acme\\Http\\Controllers\\PostController');

        $this->assertContains([
            'source' => 'Acme\\Http\\Controllers\\PostController::__invoke',
            'target' => 'Acme\\Policies\\PostPolicy',
            'type' => 'authorizes',
        ], $edges);
    }

    #[Test]
    public function a_blade_policy_reference_under_the_derived_root_is_found(): void
    {
        $this->useProjectRoot(['autoload' => ['psr-4' => ['Acme\\' => 'app/']]]);

        $this->assertSame(
            ['Acme\\Policies\\PostPolicy'],
            new PolicyEdgeTracer()->policiesReferencedInBlade('@can(\Acme\Policies\PostPolicy::UPDATE, $post)'),
        );
    }

    #[Test]
    public function reference_edges_follow_the_derived_root(): void
    {
        $this->useProjectRoot(['autoload' => ['psr-4' => ['Acme\\' => 'app/']]]);

        $source = "<?php\nnamespace Acme\\Http\\Controllers;\nuse Acme\\Http\\Resources\\PostResource;\nclass PostController\n{\n    public function __invoke(): void\n    {\n        PostResource::make(\$post);\n    }\n}\n";

        $edges = new ReferenceEdgeTracer()->edgesForSource($source, 'Acme\\Http\\Controllers\\PostController');

        $this->assertContains([
            'source' => 'Acme\\Http\\Controllers\\PostController::__invoke',
            'target' => 'Acme\\Http\\Resources\\PostResource',
            'type' => 'resource',
        ], $edges);
    }

    #[Test]
    public function a_test_importing_a_class_under_the_derived_root_is_indexed(): void
    {
        $this->useProjectRoot(['autoload' => ['psr-4' => ['Acme\\' => 'app/']]]);

        $index = new TestReferenceIndex();
        $index->addSource("<?php\nuse Acme\\Services\\Inspector;\n\$inspector = new Inspector();\n", 'tests/Unit/InspectorTest.php');

        $this->assertSame(['tests/Unit/InspectorTest.php'], $index->testsImporting('Acme\\Services\\Inspector'));
        $this->assertTrue($index->hasReference('Acme\\Services\\Inspector'));
    }

    /**
     * Points `base_path()` at a disposable directory holding the given `composer.json` (null writes
     * none), so the derivation reads a project layout the test controls rather than this package's.
     *
     * @param  array<string, mixed>|null  $composerJson
     */
    private function useProjectRoot(?array $composerJson): string
    {
        $this->tempRoot = sys_get_temp_dir() . '/richter-root-namespace-' . bin2hex(random_bytes(8));
        mkdir($this->tempRoot, recursive: true);

        if ($composerJson !== null) {
            file_put_contents("{$this->tempRoot}/composer.json", json_encode($composerJson, JSON_THROW_ON_ERROR));
        }

        $app = $this->app;
        $this->assertInstanceOf(Application::class, $app);
        $app->setBasePath($this->tempRoot);

        AppNamespace::flush();

        return $this->tempRoot;
    }

    private function deleteTree(string $dir): void
    {
        $entries = scandir($dir);

        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry === '.') {
                continue;
            }

            if ($entry === '..') {
                continue;
            }

            $path = "{$dir}/{$entry}";

            is_dir($path) ? $this->deleteTree($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
