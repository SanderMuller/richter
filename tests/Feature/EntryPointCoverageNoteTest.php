<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Feature;

use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Tests\TestCase;

/**
 * The entry-point coverage note as the commands emit it. Two properties matter and they pull
 * against each other: it has to appear when a whole subsystem is missing from the graph, and it has
 * to stay off a conventionally-shaped app — a note that fires on every project is one its reader
 * learns to skip past.
 */
final class EntryPointCoverageNoteTest extends TestCase
{
    private string $root = '';

    protected function tearDown(): void
    {
        if ($this->root !== '' && is_dir($this->root)) {
            $this->deleteTree($this->root);
        }

        parent::tearDown();
    }

    #[Test]
    public function the_note_stays_silent_on_the_fixture_project(): void
    {
        // The regression that matters most. The fixture app has 19 directories under app/ and only
        // eight are configured roots, so a config-diff check would fire on eleven of them.
        $app = $this->app;
        $this->assertInstanceOf(Application::class, $app);
        $app->setBasePath(self::fixtureProjectPath());

        $this->withoutMockingConsoleOutput();
        Artisan::call('richter:impact', ['symbol' => 'App\Models\Post', '--no-cache' => true]);

        $this->assertStringNotContainsString('entry_point_roots', Artisan::output());
    }

    #[Test]
    public function an_uncovered_directory_is_named_on_stderr_without_touching_the_json_document(): void
    {
        $this->projectWithUncoveredDirectory();

        $this->withoutMockingConsoleOutput();
        $exitCode = Artisan::call('richter:impact', ['symbol' => 'Nothing', '--json' => true, '--no-cache' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('app/Registry/ holds 5 classes', $output);
        $this->assertStringContainsString('richter.entry_point_roots', $output);

        // stderr and stdout land in one buffer with the note first, so the document starts at the
        // first '{' — the same contract --profile and the untracked-files note already hold to.
        $decoded = json_decode(substr($output, (int) strpos($output, '{')), associative: true);

        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('target', $decoded);
    }

    /**
     * A throwaway app whose only populated directory is one no tracer reaches. Classes are named
     * after nothing in particular — the check reads paths, never file contents.
     */
    private function projectWithUncoveredDirectory(): void
    {
        $this->root = (string) tempnam(sys_get_temp_dir(), 'richter-coverage-note-');
        unlink($this->root);
        mkdir($this->root . '/app/Registry', 0o777, true);

        for ($i = 1; $i <= 5; ++$i) {
            file_put_contents(
                $this->root . '/app/Registry/Handler' . $i . '.php',
                "<?php declare(strict_types=1);\n\nnamespace App\\Registry;\n\nfinal class Handler{$i} {}\n",
            );
        }

        $app = $this->app;
        $this->assertInstanceOf(Application::class, $app);
        $app->setBasePath($this->root);
    }
}
