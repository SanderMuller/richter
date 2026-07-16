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
}
