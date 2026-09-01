<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Testing\PendingCommand;
use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Tests\TestCase;

/**
 * `richter:warm` against a disposable project tree.
 *
 * Two contracts no other command has: both modes exit non-zero when the answer is no, so a deploy
 * step can gate on them, and `--check` must never build or write — a check that warms the cache it
 * reports on proves nothing about the entry the deploy produced.
 */
final class WarmCommandTest extends TestCase
{
    private string $base;

    private string $cacheDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = sys_get_temp_dir() . '/richter-warm-' . bin2hex(random_bytes(8));
        $projectRoot = "{$this->base}/project";
        $this->cacheDirectory = "{$this->base}/cache";
        mkdir("{$projectRoot}/app/Services", recursive: true);
        mkdir("{$projectRoot}/routes", recursive: true);
        file_put_contents("{$projectRoot}/app/Services/Alpha.php", "<?php\n\nnamespace App\\Services;\n\nclass Alpha\n{\n    public function run(): void {}\n}\n");
        file_put_contents("{$projectRoot}/routes/web.php", "<?php\n");

        $app = $this->app;
        $this->assertInstanceOf(Application::class, $app);
        $app->setBasePath($projectRoot);

        config()->set('richter.cache.enabled', true);
        config()->set('richter.cache.directory', $this->cacheDirectory);
    }

    protected function tearDown(): void
    {
        new Filesystem()->deleteDirectory($this->base);
        parent::tearDown();
    }

    private function cacheFile(): string
    {
        return "{$this->cacheDirectory}/graph.json";
    }

    #[Test]
    public function warm_builds_the_graph_and_reports_the_entry(): void
    {
        $this->runArtisan('richter:warm')
            ->expectsOutputToContain('Built the code graph')
            ->expectsOutputToContain('fingerprint')
            ->assertSuccessful();

        $this->assertFileExists($this->cacheFile());
    }

    #[Test]
    public function warm_says_so_when_the_entry_was_already_current(): void
    {
        // Not a skipped build to apologise for: the fingerprint sweep IS the currency check, so a
        // matching entry needs no rebuild and the honest report says which happened.
        Artisan::call('richter:warm');

        $this->runArtisan('richter:warm')
            ->expectsOutputToContain('already current')
            ->assertSuccessful();
    }

    #[Test]
    public function warm_fails_when_the_entry_cannot_be_written(): void
    {
        mkdir($this->cacheDirectory, recursive: true);
        mkdir($this->cacheFile());

        $this->runArtisan('richter:warm')
            ->expectsOutputToContain('Could not write the cache entry')
            ->assertFailed();

        rmdir($this->cacheFile());
    }

    #[Test]
    public function check_reports_a_current_entry_and_exits_zero(): void
    {
        Artisan::call('richter:warm');

        $this->runArtisan('richter:warm', ['--check' => true])
            ->expectsOutputToContain('matches this tree')
            ->assertSuccessful();
    }

    #[Test]
    public function check_names_the_differing_input_rather_than_reporting_that_one_differs(): void
    {
        // The deploy question the cache could not otherwise be asked. A build container and a
        // runtime container on different PHP patch releases miss forever, silently.
        Artisan::call('richter:warm');
        $entry = json_decode((string) file_get_contents($this->cacheFile()), associative: true, flags: JSON_THROW_ON_ERROR);
        $this->assertIsArray($entry);
        $this->assertIsArray($entry['inputs']);
        $inputs = $entry['inputs'];
        $this->assertIsArray($inputs['nonFile']);
        $inputs['nonFile']['php'] = '0.0.0-not-this-runtime';
        $entry['inputs'] = $inputs;
        $entry['fingerprint'] = 'deliberately-not-the-live-one';
        file_put_contents($this->cacheFile(), json_encode($entry, JSON_THROW_ON_ERROR));

        $this->withoutMockingConsoleOutput();
        $exitCode = Artisan::call('richter:warm', ['--check' => true]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('does NOT match', $output);
        $this->assertStringContainsString('inputs-changed', $output);
        $this->assertStringContainsString('php (0.0.0-not-this-runtime → ', $output);
    }

    #[Test]
    public function check_calls_a_corrupt_entry_unusable_rather_than_stale(): void
    {
        // A reader told "stale" waits for a rebuild. A broken entry needs the file removed, and the
        // two demand opposite responses, so the report must not collapse them.
        Artisan::call('richter:warm');
        file_put_contents($this->cacheFile(), '{ not json at all');

        $this->withoutMockingConsoleOutput();
        $exitCode = Artisan::call('richter:warm', ['--check' => true]);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('UNUSABLE', $output);
        $this->assertStringContainsString('cache-unreadable', $output);
    }

    #[Test]
    public function check_on_a_cold_cache_reports_the_absent_entry_and_warms_nothing(): void
    {
        $this->runArtisan('richter:warm', ['--check' => true])
            ->expectsOutputToContain('no-cache-entry')
            ->assertFailed();

        $this->assertFileDoesNotExist($this->cacheFile());
    }

    #[Test]
    public function the_json_document_carries_the_verdict_a_deploy_step_branches_on(): void
    {
        $this->withoutMockingConsoleOutput();

        Artisan::call('richter:warm', ['--json' => true]);
        $warm = json_decode(Artisan::output(), true);

        $this->assertIsArray($warm);
        $this->assertSame('warm', $warm['mode']);
        $this->assertTrue($warm['ok']);
        $this->assertTrue($warm['built']);
        $this->assertFalse($warm['repaired']);
        $this->assertArrayHasKey('fingerprint', $warm);
        $this->assertArrayHasKey('bytes', $warm);

        Artisan::call('richter:warm', ['--check' => true, '--json' => true]);
        $check = json_decode(Artisan::output(), true);

        $this->assertIsArray($check);
        $this->assertSame('check', $check['mode']);
        $this->assertTrue($check['ok']);
        // Absent rather than null when it does not apply, and absent on a match because it would
        // only repeat `fingerprint`.
        $this->assertArrayNotHasKey('storedFingerprint', $check);
        $this->assertArrayNotHasKey('reason', $check);
        $this->assertArrayNotHasKey('corrupt', $check);
    }

    #[Test]
    public function a_failed_check_carries_ok_false_with_its_reason(): void
    {
        $this->withoutMockingConsoleOutput();

        $exitCode = Artisan::call('richter:warm', ['--check' => true, '--json' => true]);
        $document = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exitCode);
        $this->assertIsArray($document);
        // An answer, not a failure of the command — so it keeps the shape a caller branches on
        // rather than taking the {"error": …} form reserved for an unexpected throw.
        $this->assertFalse($document['ok']);
        $this->assertSame('no-cache-entry', $document['reason']);
        $this->assertArrayNotHasKey('error', $document);
    }

    #[Test]
    public function a_disabled_cache_is_a_usage_error_in_both_modes(): void
    {
        config()->set('richter.cache.enabled', false);

        foreach ([[], ['--check' => true]] as $parameters) {
            $this->runArtisan('richter:warm', $parameters)
                ->expectsOutputToContain('The cache is disabled')
                ->assertFailed();
        }

        $this->assertFileDoesNotExist($this->cacheFile());
    }

    #[Test]
    public function a_failed_warm_write_is_an_ok_false_document_with_exit_one(): void
    {
        mkdir($this->cacheDirectory, recursive: true);
        mkdir($this->cacheFile());
        $this->withoutMockingConsoleOutput();

        $exitCode = Artisan::call('richter:warm', ['--json' => true]);
        $document = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exitCode);
        $this->assertIsArray($document);
        $this->assertFalse($document['ok'], 'The build ran and the entry did not land — the whole reason this command re-reads.');
        $this->assertTrue($document['built']);
        // `bytes` describes an entry that exists; there is none, so the key is absent, not null.
        $this->assertArrayNotHasKey('bytes', $document);

        rmdir($this->cacheFile());
    }

    #[Test]
    public function an_unexpected_failure_takes_the_error_shape_rather_than_ok_false(): void
    {
        // The line a caller depends on: `ok: false` means richter answered and the answer is no;
        // a missing `ok` means richter did not get to answer. A malformed config value is the second
        // kind — and it throws while READING the very setting the first check depends on, so the
        // exception boundary has to start before that read rather than after it.
        config()->set('richter.cache.enabled', 'yes-please-not-a-boolean');
        $this->withoutMockingConsoleOutput();

        $exitCode = Artisan::call('richter:warm', ['--json' => true]);
        $document = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exitCode);
        $this->assertIsArray($document);
        $this->assertArrayHasKey('error', $document);
        $this->assertArrayNotHasKey('ok', $document);
    }

    #[Test]
    public function a_disabled_cache_is_an_ok_false_answer_in_json_mode_not_an_error_document(): void
    {
        // richter understands this state, so it is an answer, not a failure of the command. A deploy
        // step branching on `ok` must not have to guess which of two shapes it received.
        config()->set('richter.cache.enabled', false);
        $this->withoutMockingConsoleOutput();

        foreach (['warm' => [], 'check' => ['--check' => true]] as $mode => $parameters) {
            $exitCode = Artisan::call('richter:warm', [...$parameters, '--json' => true]);
            $document = json_decode(Artisan::output(), true);

            $this->assertSame(1, $exitCode);
            $this->assertIsArray($document);
            $this->assertSame($mode, $document['mode']);
            $this->assertFalse($document['ok']);
            $this->assertSame('cache-disabled', $document['reason']);
            $this->assertArrayNotHasKey('error', $document);
        }
    }

    /** @param  array<string, string|bool>  $parameters */
    private function runArtisan(string $command, array $parameters = []): PendingCommand
    {
        $pending = $this->artisan($command, $parameters);

        $this->assertInstanceOf(PendingCommand::class, $pending);

        return $pending;
    }
}
