<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Graph\CodeGraphBuilder;
use SanderMuller\Richter\Tests\TestCase;

/**
 * The dispatch-classification rules through the path production uses.
 *
 * The tracer's own tests drive `edgesForSource()`, which finds the file's class-likes itself. A real
 * build does not: `CodeGraphBuilder` collects them once per file and hands them over, so the constant
 * resolution depends on wiring those tests never touch. This exercises that wiring end to end, over a
 * temp project rather than the shared fixture, so the shapes stay next to the assertions about them.
 */
final class DispatchSiteBuildTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->projectRoot = sys_get_temp_dir() . '/richter-dispatch-sites-' . bin2hex(random_bytes(8));
        mkdir("{$this->projectRoot}/app/Livewire", recursive: true);
        mkdir("{$this->projectRoot}/app/Services", recursive: true);
    }

    protected function tearDown(): void
    {
        new Filesystem()->deleteDirectory($this->projectRoot);
        parent::tearDown();
    }

    /** @return list<array{file: string, line: int, dispatcher: string}> */
    private function sitesFor(string $relativePath, string $source): array
    {
        file_put_contents("{$this->projectRoot}/{$relativePath}", $source);

        return new CodeGraphBuilder()->buildTracerBranch($this->projectRoot)['unresolvedDispatchSites'];
    }

    #[Test]
    public function a_component_event_named_by_a_constant_records_no_site(): void
    {
        $sites = $this->sitesFor('app/Livewire/Panel.php', <<<'PHP'
            <?php declare(strict_types=1);

            namespace App\Livewire;

            final class Panel
            {
                private const string SAVED = 'saved-settings';

                public function save(): void
                {
                    $this->dispatch(self::SAVED);
                    $this->dispatch(self::SAVED, id: 1);
                    $this->dispatch('inline-too');
                }
            }
            PHP);

        $this->assertSame([], $sites);
    }

    #[Test]
    public function a_genuinely_opaque_dispatch_still_records_its_site(): void
    {
        // The other direction, and the one that matters more: narrowing the rules must not swallow a
        // dispatch whose target really cannot be seen. Without this the test above would pass just as
        // happily if the whole lane had stopped recording anything.
        $sites = $this->sitesFor('app/Services/Importer.php', <<<'PHP'
            <?php declare(strict_types=1);

            namespace App\Services;

            final class Importer
            {
                private const int RETRIES = 3;

                public function run($job): void
                {
                    dispatch($job);
                    $this->dispatch(self::RETRIES);
                }
            }
            PHP);

        $this->assertSame([
            ['file' => 'app/Services/Importer.php', 'line' => 11, 'dispatcher' => 'App\Services\Importer::run'],
            ['file' => 'app/Services/Importer.php', 'line' => 12, 'dispatcher' => 'App\Services\Importer::run'],
        ], $sites);
    }
}
