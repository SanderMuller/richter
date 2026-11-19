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
        return $this->buildWith($relativePath, $source)['unresolvedDispatchSites'];
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

    /**
     * The build reads the hierarchy from the project it scans; reflection reads the enum from the
     * autoloaded fixture, so only the jobs are copied.
     */
    private function withExportJobs(): void
    {
        mkdir("{$this->projectRoot}/app/Jobs/Exports", recursive: true);

        foreach (['ExportJob', 'CsvExportJob', 'PdfExportJob'] as $job) {
            copy(__DIR__ . "/../Fixtures/project/app/Jobs/Exports/{$job}.php", "{$this->projectRoot}/app/Jobs/Exports/{$job}.php");
        }
    }

    /**
     * @return array{edges: list<array{source: string, target: string, type: string}>, unresolvedDispatchSites: list<array{file: string, line: int, dispatcher: string}>, ...}
     */
    private function buildWith(string $relativePath, string $source): array
    {
        file_put_contents("{$this->projectRoot}/{$relativePath}", $source);

        return new CodeGraphBuilder()->buildTracerBranch($this->projectRoot);
    }

    /**
     * @param  list<array{source: string, target: string, type: string}>  $edges
     * @return list<string>
     */
    private function jobTargetsOf(array $edges, string $source): array
    {
        $targets = array_values(array_column(
            array_filter($edges, static fn (array $edge): bool => $edge['source'] === $source && $edge['type'] === 'action-to-job'),
            'target',
        ));
        sort($targets);

        return $targets;
    }

    #[Test]
    public function a_factory_typed_as_an_abstract_job_fans_out_to_every_concrete_subtype(): void
    {
        $this->withExportJobs();

        $build = $this->buildWith('app/Services/Importer.php', <<<'PHP'
            <?php declare(strict_types=1);

            namespace App\Services;

            use App\Enums\ExportFormat;
            use App\Jobs\Exports\ExportJob;

            final class Importer
            {
                public function run(): void
                {
                    try {
                        $job = $this->resolve();
                    } catch (\RuntimeException) {
                        return;
                    }

                    dispatch($job);
                }

                public function export(): void
                {
                    dispatch(ExportFormat::Csv->job([]));
                }

                private function resolve(): ExportJob
                {
                    return ExportFormat::Pdf->job([]);
                }
            }
            PHP);

        $this->assertSame([], $build['unresolvedDispatchSites']);
        $expected = ['App\Jobs\Exports\CsvExportJob::handle', 'App\Jobs\Exports\PdfExportJob::handle'];
        $this->assertSame($expected, $this->jobTargetsOf($build['edges'], 'App\Services\Importer::run'));
        $this->assertSame($expected, $this->jobTargetsOf($build['edges'], 'App\Services\Importer::export'));
    }

    #[Test]
    public function a_factory_typed_as_a_type_the_build_never_scanned_keeps_its_site(): void
    {
        // No export jobs in this project: the declared type is real, but its subtypes are unknown.
        $build = $this->buildWith('app/Services/Importer.php', <<<'PHP'
            <?php declare(strict_types=1);

            namespace App\Services;

            use App\Enums\ExportFormat;

            final class Importer
            {
                public function export(): void
                {
                    dispatch(ExportFormat::Csv->job([]));
                }
            }
            PHP);

        $this->assertSame([['file' => 'app/Services/Importer.php', 'line' => 11, 'dispatcher' => 'App\Services\Importer::export']], $build['unresolvedDispatchSites']);
    }

    #[Test]
    public function a_factory_type_with_no_dispatch_target_under_it_keeps_its_site(): void
    {
        // Bound in the container to a class outside app/, so no scanned class implements it.
        mkdir("{$this->projectRoot}/app/Contracts", recursive: true);
        file_put_contents("{$this->projectRoot}/app/Contracts/Exportable.php", "<?php\nnamespace App\\Contracts;\ninterface Exportable\n{\n    public function handle(): void;\n}\n");

        $build = $this->buildWith('app/Services/Importer.php', <<<'PHP'
            <?php declare(strict_types=1);

            namespace App\Services;

            use App\Contracts\Exportable;

            final class Importer
            {
                public function run(): void
                {
                    dispatch_sync($this->make());
                }

                private function make(): Exportable
                {
                    return app(Exportable::class);
                }
            }
            PHP);

        $this->assertSame([['file' => 'app/Services/Importer.php', 'line' => 11, 'dispatcher' => 'App\Services\Importer::run']], $build['unresolvedDispatchSites']);
    }

    #[Test]
    public function a_factory_type_an_anonymous_class_extends_keeps_its_site(): void
    {
        $this->withExportJobs();

        $build = $this->buildWith('app/Services/Importer.php', <<<'PHP'
            <?php declare(strict_types=1);

            namespace App\Services;

            use App\Jobs\Exports\ExportJob;

            final class Importer
            {
                public function run(): void
                {
                    dispatch($this->make());
                }

                private function make(): ExportJob
                {
                    return new class extends ExportJob {
                        public function handle(): void {}
                    };
                }
            }
            PHP);

        $this->assertSame([['file' => 'app/Services/Importer.php', 'line' => 11, 'dispatcher' => 'App\Services\Importer::run']], $build['unresolvedDispatchSites']);
        $this->assertSame([], $this->jobTargetsOf($build['edges'], 'App\Services\Importer::run'));
    }

    #[Test]
    public function an_enum_implementing_the_factory_type_is_a_target(): void
    {
        mkdir("{$this->projectRoot}/app/Contracts", recursive: true);
        mkdir("{$this->projectRoot}/app/Enums", recursive: true);
        file_put_contents("{$this->projectRoot}/app/Contracts/Exportable.php", "<?php\nnamespace App\\Contracts;\ninterface Exportable\n{\n    public function handle(): void;\n}\n");
        file_put_contents("{$this->projectRoot}/app/Enums/Cleanup.php", "<?php\nnamespace App\\Enums;\nuse App\\Contracts\\Exportable;\nenum Cleanup implements Exportable\n{\n    case Daily;\n\n    public function handle(): void {}\n}\n");

        $build = $this->buildWith('app/Services/Importer.php', <<<'PHP'
            <?php declare(strict_types=1);

            namespace App\Services;

            use App\Contracts\Exportable;

            final class Importer
            {
                public function run(): void
                {
                    dispatch_sync($this->make());
                }

                private function make(): Exportable
                {
                    return \App\Enums\Cleanup::Daily;
                }
            }
            PHP);

        $this->assertSame([], $build['unresolvedDispatchSites']);
        $this->assertSame(['App\Enums\Cleanup::handle'], $this->jobTargetsOf($build['edges'], 'App\Services\Importer::run'));
    }

    #[Test]
    public function a_concrete_factory_type_draws_its_own_edge(): void
    {
        $this->withExportJobs();

        $build = $this->buildWith('app/Services/Importer.php', <<<'PHP'
            <?php declare(strict_types=1);

            namespace App\Services;

            use App\Jobs\Exports\CsvExportJob;

            final class Importer
            {
                public function run(): void
                {
                    dispatch(CsvExportJob::forRows([]));
                }
            }
            PHP);

        $this->assertSame([], $build['unresolvedDispatchSites']);
        $this->assertSame(['App\Jobs\Exports\CsvExportJob::handle'], $this->jobTargetsOf($build['edges'], 'App\Services\Importer::run'));
    }

    #[Test]
    public function one_statement_with_two_unfollowable_items_is_one_site(): void
    {
        $build = $this->buildWith('app/Services/Importer.php', <<<'PHP'
            <?php declare(strict_types=1);

            namespace App\Services;

            use App\Enums\ExportFormat;
            use Illuminate\Support\Facades\Bus;

            final class Importer
            {
                public function run($other): void
                {
                    Bus::chain([$other, ExportFormat::Csv->job([])])->dispatch();
                }
            }
            PHP);

        $this->assertSame([['file' => 'app/Services/Importer.php', 'line' => 12, 'dispatcher' => 'App\Services\Importer::run']], $build['unresolvedDispatchSites']);
    }

    #[Test]
    public function a_factory_type_with_a_subtype_that_is_no_target_keeps_its_site_and_its_edges(): void
    {
        // `App\Models\Post` loads from the shared fixture and is no dispatch target; the temp copy only
        // tells the hierarchy that it implements the factory type.
        mkdir("{$this->projectRoot}/app/Contracts", recursive: true);
        mkdir("{$this->projectRoot}/app/Jobs", recursive: true);
        mkdir("{$this->projectRoot}/app/Models", recursive: true);
        file_put_contents("{$this->projectRoot}/app/Contracts/Exportable.php", "<?php\nnamespace App\\Contracts;\ninterface Exportable {}\n");
        file_put_contents("{$this->projectRoot}/app/Jobs/SyncJob.php", "<?php\nnamespace App\\Jobs;\nfinal class SyncJob implements \\App\\Contracts\\Exportable\n{\n    public function handle(): void {}\n}\n");
        file_put_contents("{$this->projectRoot}/app/Models/Post.php", "<?php\nnamespace App\\Models;\nfinal class Post implements \\App\\Contracts\\Exportable {}\n");

        $build = $this->buildWith('app/Services/Importer.php', <<<'PHP'
            <?php declare(strict_types=1);

            namespace App\Services;

            use App\Contracts\Exportable;

            final class Importer
            {
                public function run(): void
                {
                    dispatch($this->make());
                }

                private function make(): Exportable
                {
                    return app(Exportable::class);
                }
            }
            PHP);

        $this->assertSame([['file' => 'app/Services/Importer.php', 'line' => 11, 'dispatcher' => 'App\Services\Importer::run']], $build['unresolvedDispatchSites']);
        $this->assertSame(['App\Jobs\SyncJob::handle'], $this->jobTargetsOf($build['edges'], 'App\Services\Importer::run'));
    }

    #[Test]
    public function a_named_constructor_whose_family_is_restored_keeps_its_edge(): void
    {
        $this->withExportJobs();
        file_put_contents("{$this->projectRoot}/app/Services/Copier.php", "<?php\nnamespace App\\Services;\nfinal class Copier\n{\n    public function make(): object { return new class extends \\App\\Jobs\\Exports\\CsvExportJob {}; }\n}\n");

        $build = $this->buildWith('app/Services/Importer.php', <<<'PHP'
            <?php declare(strict_types=1);

            namespace App\Services;

            use App\Jobs\Exports\CsvExportJob;

            final class Importer
            {
                public function run(): void
                {
                    dispatch(CsvExportJob::forRows([]));
                }
            }
            PHP);

        $this->assertSame([['file' => 'app/Services/Importer.php', 'line' => 11, 'dispatcher' => 'App\Services\Importer::run']], $build['unresolvedDispatchSites']);
        $this->assertSame(['App\Jobs\Exports\CsvExportJob::handle'], $this->jobTargetsOf($build['edges'], 'App\Services\Importer::run'));
    }
}
