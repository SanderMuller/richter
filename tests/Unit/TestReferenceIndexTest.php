<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use App\Console\Commands\CodeDetectChangesCommand;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Artisan;
use Override;
use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Analysis\TestReferenceIndex;
use SanderMuller\Richter\Tests\TestCase;

final class TestReferenceIndexTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // The index resolves a command node's class through the console kernel — register the
        // fixture command it looks up.
        Artisan::registerCommand(new CodeDetectChangesCommand());
    }

    /** @param  Router  $router */
    #[Override]
    protected function defineRoutes($router): void
    {
        $router->get('/login', static fn (): string => 'login')->name('login');
    }

    private function index(string $testSource): TestReferenceIndex
    {
        $index = new TestReferenceIndex();
        $index->addSource($testSource);

        return $index;
    }

    #[Test]
    public function a_route_hit_by_literal_uri_is_referenced(): void
    {
        $index = $this->index('<?php $this->get("/errors/log");');

        $this->assertTrue($index->hasReference('route::GET::/errors/log'));
    }

    #[Test]
    public function a_parameterised_route_matches_a_substituted_test_uri(): void
    {
        $index = $this->index("<?php \$this->get('/videos/123/questions/456');");

        $this->assertTrue($index->hasReference('route::GET::/videos/{video}/questions/{question}'));
    }

    #[Test]
    public function a_query_string_in_the_test_uri_is_ignored(): void
    {
        $index = $this->index('<?php $this->get("/errors/log?verbose=1");');

        $this->assertTrue($index->hasReference('route::GET::/errors/log'));
    }

    #[Test]
    public function an_unreferenced_route_reports_false(): void
    {
        $this->assertFalse($this->index('<?php $x = 1;')->hasReference('route::GET::/errors/log'));
    }

    #[Test]
    public function a_command_referenced_by_artisan_name_is_referenced(): void
    {
        $index = $this->index("<?php \$this->artisan('video:seed-views');");

        $this->assertTrue($index->hasReference('command::video:seed-views {--without-relations : Seed views without relations}'));
    }

    #[Test]
    public function an_unreferenced_command_reports_false(): void
    {
        $this->assertFalse($this->index('<?php $x = 1;')->hasReference('command::definitely:not-referenced'));
    }

    #[Test]
    public function a_command_referenced_via_artisan_facade_call_is_referenced(): void
    {
        $index = $this->index("<?php Artisan::call('video:seed-views');");

        $this->assertTrue($index->hasReference('command::video:seed-views {--without-relations : x}'));
    }

    #[Test]
    public function a_command_referenced_only_by_its_class_import_is_referenced(): void
    {
        $index = $this->index("<?php\nuse App\Console\Commands\CodeDetectChangesCommand;\n");

        $this->assertTrue($index->hasReference('command::code:detect-changes {--base=origin/develop : x}'));
    }

    #[Test]
    public function a_grouped_class_import_is_expanded_per_member(): void
    {
        $index = $this->index("<?php\nuse App\Console\Commands\{SomeOtherCommand, CodeDetectChangesCommand};\n");

        $this->assertTrue($index->hasReference('command::code:detect-changes {--base=origin/develop : x}'));
    }

    #[Test]
    public function an_aliased_import_keys_on_the_fqcn_not_the_alias(): void
    {
        $index = $this->index("<?php\nuse App\Console\Commands\CodeDetectChangesCommand as DetectCmd;\n");

        $this->assertTrue($index->hasReference('command::code:detect-changes {--base=origin/develop : x}'));
    }

    #[Test]
    public function a_grouped_aliased_import_keys_on_the_fqcn(): void
    {
        $index = $this->index("<?php\nuse App\Console\Commands\{CodeDetectChangesCommand as DetectCmd};\n");

        $this->assertTrue($index->hasReference('command::code:detect-changes {--base=origin/develop : x}'));
        $this->assertFalse($index->hasReference('App\Console\Commands\DetectCmd'));
    }

    #[Test]
    public function a_command_referenced_via_artisan_queue_is_referenced(): void
    {
        $index = $this->index("<?php Artisan::queue('video:seed-views');");

        $this->assertTrue($index->hasReference('command::video:seed-views {--without-relations : x}'));
    }

    #[Test]
    public function a_schedule_node_cannot_be_checked(): void
    {
        $this->assertNull($this->index('<?php')->hasReference('schedule::something'));
    }

    #[Test]
    public function a_self_listed_entry_point_class_matches_a_test_class_import(): void
    {
        $index = $this->index("<?php\nuse App\Listeners\Saml\SamlLoginListener;\n");

        $this->assertTrue($index->hasReference('App\Listeners\Saml\SamlLoginListener'));
        $this->assertFalse($index->hasReference('App\Listeners\Saml\OtherListener'));
    }

    #[Test]
    public function a_route_referenced_by_route_name_is_referenced(): void
    {
        // The `login` route is a stable named app route.
        $uri = '/' . ltrim((string) parse_url(route('login'), PHP_URL_PATH), '/');

        $index = $this->index("<?php \$this->get(route('login'));");

        $this->assertTrue($index->hasReference("route::GET::{$uri}"));
    }

    #[Test]
    public function tests_referencing_returns_the_files_a_route_reference_came_from(): void
    {
        $index = new TestReferenceIndex();
        $index->addSource('<?php $this->get("/errors/log");', 'tests/Feature/ErrorLogTest.php');
        $index->addSource('<?php $this->get("/errors/log?verbose=1");', 'tests/Feature/VerboseTest.php');
        $index->addSource('<?php $x = 1;', 'tests/Feature/UnrelatedTest.php');

        $this->assertSame(
            ['tests/Feature/ErrorLogTest.php', 'tests/Feature/VerboseTest.php'],
            $index->testsReferencing('route::GET::/errors/log'),
        );
    }

    #[Test]
    public function tests_referencing_shares_has_references_tri_state(): void
    {
        $index = new TestReferenceIndex();
        $index->addSource('<?php $x = 1;', 'tests/Feature/UnrelatedTest.php');

        $this->assertSame([], $index->testsReferencing('route::GET::/errors/log'));
        $this->assertNull($index->testsReferencing('schedule::something'));
    }

    #[Test]
    public function a_source_without_a_file_counts_for_the_boolean_but_contributes_no_path(): void
    {
        $index = $this->index('<?php $this->get("/errors/log");');

        $this->assertTrue($index->hasReference('route::GET::/errors/log'));
        $this->assertSame([], $index->testsReferencing('route::GET::/errors/log'));
    }

    #[Test]
    public function tests_importing_lists_the_files_importing_a_class(): void
    {
        $index = new TestReferenceIndex();
        $index->addSource("<?php\nuse App\Models\Video;\n", 'tests/Unit/VideoTest.php');
        $index->addSource("<?php\nuse App\Models\Video;\nuse App\Models\Question;\n", 'tests/Feature/VideoFlowTest.php');

        $this->assertSame(['tests/Unit/VideoTest.php', 'tests/Feature/VideoFlowTest.php'], $index->testsImporting('App\Models\Video'));
        $this->assertSame(['tests/Feature/VideoFlowTest.php'], $index->testsImporting('\App\Models\Question'));
        $this->assertSame([], $index->testsImporting('App\Models\Unknown'));
    }

    #[Test]
    public function a_qualified_in_body_reference_counts_without_an_import(): void
    {
        $index = new TestReferenceIndex();
        $index->addSource('<?php $x = \App\Services\X::class; new \App\Jobs\ImportJob();', 'tests/Unit/DirectRefTest.php');

        $this->assertSame(['tests/Unit/DirectRefTest.php'], $index->testsImporting('App\Services\X'));
        $this->assertSame(['tests/Unit/DirectRefTest.php'], $index->testsImporting('App\Jobs\ImportJob'));
    }

    #[Test]
    public function a_livewire_component_test_references_the_component_entry_point(): void
    {
        // `Livewire::test(Settings::class)` needs no bespoke pattern: the component class reference
        // (import or qualified) is what the index keys on, exactly like any entry-point class.
        $index = new TestReferenceIndex();
        $index->addSource("<?php\nuse App\Livewire\Settings;\nLivewire::test(Settings::class)->call('save');", 'tests/Feature/SettingsTest.php');

        $this->assertTrue($index->hasReference('App\Livewire\Settings'));
        $this->assertSame(['tests/Feature/SettingsTest.php'], $index->testsReferencing('App\Livewire\Settings'));
    }

    #[Test]
    public function a_filament_helper_test_references_the_resource_entry_point(): void
    {
        $index = new TestReferenceIndex();
        $index->addSource("<?php livewire(\App\Filament\Resources\VideoResource::class)->callTableAction('delete');", 'tests/Feature/VideoResourceTest.php');

        $this->assertTrue($index->hasReference('App\Filament\Resources\VideoResource'));
        $this->assertSame(['tests/Feature/VideoResourceTest.php'], $index->testsReferencing('App\Filament\Resources\VideoResource'));
    }

    #[Test]
    public function a_relatively_qualified_page_class_pins_its_current_recording(): void
    {
        // Filament pages are often referenced relative to an imported resource class:
        // `VideoResource\Pages\ListVideos::class` resolves through the import at runtime, but the
        // in-body class-reference regex anchors on a literal `App\` prefix, so this relative form
        // is NOT recorded — only the imported resource class itself is.
        $index = new TestReferenceIndex();
        $index->addSource(
            "<?php\nuse App\Filament\Resources\VideoResource;\nlivewire(VideoResource\Pages\ListVideos::class)->assertSuccessful();",
            'tests/Feature/ListVideosTest.php',
        );

        $this->assertSame(['tests/Feature/ListVideosTest.php'], $index->testsImporting('App\Filament\Resources\VideoResource'));
        $this->assertSame([], $index->testsImporting('App\Filament\Resources\VideoResource\Pages\ListVideos'));
    }

    #[Test]
    public function a_string_named_livewire_component_maps_to_its_conventional_class(): void
    {
        $index = new TestReferenceIndex();
        $index->addSource("<?php Livewire::test('admin.dashboard')->assertSuccessful();", 'tests/Feature/AdminDashboardTest.php');

        $this->assertSame(['tests/Feature/AdminDashboardTest.php'], $index->testsImporting('App\Livewire\Admin\Dashboard'));
    }

    #[Test]
    public function a_kebab_case_string_named_component_maps_to_its_conventional_class(): void
    {
        $index = new TestReferenceIndex();
        $index->addSource("<?php livewire('show-posts')->assertSuccessful();", 'tests/Feature/ShowPostsTest.php');

        $this->assertSame(['tests/Feature/ShowPostsTest.php'], $index->testsImporting('App\Livewire\ShowPosts'));
    }

    #[Test]
    public function a_variable_named_livewire_component_records_nothing_new(): void
    {
        $index = new TestReferenceIndex();
        $index->addSource('<?php Livewire::test($component)->assertSuccessful();', 'tests/Feature/DynamicComponentTest.php');

        $this->assertSame([], $index->testsImporting('App\Livewire\Component'));
    }

    #[Test]
    public function the_same_file_is_recorded_once_per_reference(): void
    {
        $index = new TestReferenceIndex();
        $index->addSource('<?php $this->get("/errors/log"); $this->get("/errors/log");', 'tests/Feature/ErrorLogTest.php');

        $this->assertSame(['tests/Feature/ErrorLogTest.php'], $index->testsReferencing('route::GET::/errors/log'));
    }

    #[Test]
    public function a_file_whose_only_assertion_is_a_shallow_status_check_is_assertion_weak(): void
    {
        $index = new TestReferenceIndex();
        $index->addSource('<?php $this->get("/errors/log"); $response->assertOk();', 'tests/Feature/ErrorLogTest.php');

        $this->assertTrue($index->referencedWithoutBehaviouralAssertion('route::GET::/errors/log'));
    }

    #[Test]
    public function a_file_with_zero_assertions_referencing_a_class_is_assertion_weak(): void
    {
        $index = new TestReferenceIndex();
        $index->addSource("<?php\nuse App\Listeners\Saml\SamlLoginListener;\n", 'tests/Unit/SamlLoginListenerTest.php');

        $this->assertTrue($index->referencedWithoutBehaviouralAssertion('App\Listeners\Saml\SamlLoginListener'));
    }

    #[Test]
    public function a_behavioural_assertion_anywhere_in_the_file_disqualifies_it(): void
    {
        $index = new TestReferenceIndex();
        $index->addSource(
            '<?php $this->get("/errors/log"); $response->assertOk(); $this->assertDatabaseHas("logs", ["level" => "error"]);',
            'tests/Feature/ErrorLogTest.php',
        );

        $this->assertFalse($index->referencedWithoutBehaviouralAssertion('route::GET::/errors/log'));
    }

    #[Test]
    public function a_custom_assert_ish_helper_is_unknown_and_not_assertion_weak(): void
    {
        $index = new TestReferenceIndex();
        $index->addSource('<?php $this->get("/videos/1"); $this->assertVideoPublished($video);', 'tests/Feature/VideoTest.php');

        $this->assertFalse($index->referencedWithoutBehaviouralAssertion('route::GET::/videos/{video}'));
    }

    #[Test]
    public function assert_true_is_weak_only_with_a_literal_argument(): void
    {
        $nonLiteral = new TestReferenceIndex();
        $nonLiteral->addSource('<?php $this->get("/videos/1"); $this->assertTrue($video->published);', 'tests/Feature/VideoTest.php');

        $this->assertFalse($nonLiteral->referencedWithoutBehaviouralAssertion('route::GET::/videos/{video}'));

        $literal = new TestReferenceIndex();
        $literal->addSource('<?php $this->get("/videos/1"); $this->assertTrue(true);', 'tests/Feature/VideoTest.php');

        $this->assertTrue($literal->referencedWithoutBehaviouralAssertion('route::GET::/videos/{video}'));
    }

    #[Test]
    public function a_pest_style_bare_expect_call_is_not_assertion_weak(): void
    {
        $index = new TestReferenceIndex();
        $index->addSource('<?php $this->get("/videos/1"); expect($video->status)->toBe("published");', 'tests/Feature/VideoTest.php');

        $this->assertFalse($index->referencedWithoutBehaviouralAssertion('route::GET::/videos/{video}'));
    }

    #[Test]
    public function an_arrow_function_pest_expect_is_not_assertion_weak(): void
    {
        // No space between `=>` and `expect(` — the method-call exclusion must not swallow it.
        $index = new TestReferenceIndex();
        $index->addSource('<?php $this->get("/videos/1"); $check = fn () =>expect($video->status)->toBe("published");', 'tests/Feature/VideoTest.php');

        $this->assertFalse($index->referencedWithoutBehaviouralAssertion('route::GET::/videos/{video}'));
    }

    #[Test]
    public function an_authorization_status_assertion_is_not_assertion_weak(): void
    {
        // For an access-control test the status check IS the behavioural claim — tagging it
        // "proves nothing" would be the false reassurance the certainty rule forbids.
        $index = new TestReferenceIndex();
        $index->addSource('<?php $this->get("/videos/1"); $response->assertForbidden();', 'tests/Feature/VideoTest.php');

        $this->assertFalse($index->referencedWithoutBehaviouralAssertion('route::GET::/videos/{video}'));
    }

    #[Test]
    public function assert_status_is_weak_only_in_its_literal_smoke_form(): void
    {
        $smoke = new TestReferenceIndex();
        $smoke->addSource('<?php $this->get("/videos/1"); $response->assertStatus(200);', 'tests/Feature/VideoTest.php');

        $this->assertTrue($smoke->referencedWithoutBehaviouralAssertion('route::GET::/videos/{video}'));

        $authz = new TestReferenceIndex();
        $authz->addSource('<?php $this->get("/videos/1"); $response->assertStatus(403);', 'tests/Feature/VideoTest.php');

        $this->assertFalse($authz->referencedWithoutBehaviouralAssertion('route::GET::/videos/{video}'));
    }

    #[Test]
    public function all_referencing_files_must_grade_weak_for_the_sub_tag_to_apply(): void
    {
        $index = new TestReferenceIndex();
        $index->addSource('<?php $this->get("/videos/1"); $response->assertOk();', 'tests/Feature/ShallowTest.php');
        $index->addSource('<?php $this->get("/videos/1"); $this->assertDatabaseHas("videos", ["id" => 1]);', 'tests/Feature/RichTest.php');

        $this->assertFalse($index->referencedWithoutBehaviouralAssertion('route::GET::/videos/{video}'));
    }

    #[Test]
    public function a_fileless_reference_is_not_assertion_weak(): void
    {
        $index = $this->index('<?php $this->get("/errors/log"); $response->assertOk();');

        $this->assertTrue($index->hasReference('route::GET::/errors/log'));
        $this->assertFalse($index->referencedWithoutBehaviouralAssertion('route::GET::/errors/log'));
    }
}
