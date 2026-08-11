<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Analysis\ImpactAnalyzer;
use SanderMuller\Richter\Changes\ChangedFileSymbols;
use SanderMuller\Richter\Changes\MemberChange;
use SanderMuller\Richter\Graph\CodeGraph;
use SanderMuller\Richter\Support\AppNamespace;
use SanderMuller\Richter\Tests\TestCase;

/**
 * The note reaching a real report. Without the wiring the lane can be correct and never consulted,
 * and the failure it exists to prevent — a middleware change that reads as reaching one entry point
 * when it runs on every route in its group — would look exactly the same.
 */
final class MiddlewareGroupReportTest extends TestCase
{
    private const string TENANT = 'App\Http\Middleware\EnsureTenant';

    private string $projectRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->projectRoot = sys_get_temp_dir() . '/richter-mw-report-' . bin2hex(random_bytes(8));
        mkdir("{$this->projectRoot}/app/Http", recursive: true);
        file_put_contents("{$this->projectRoot}/app/Http/Kernel.php", <<<'PHP'
            <?php declare(strict_types=1);

            namespace App\Http;

            final class Kernel
            {
                protected $middlewareGroups = [
                    'api' => [\App\Http\Middleware\EnsureTenant::class],
                ];
            }
            PHP);

        $app = $this->app;
        $this->assertInstanceOf(Application::class, $app);
        $app->setBasePath($this->projectRoot);
        AppNamespace::flush();

        // The size comes from the registered route table, not from graph edges: a provider that
        // loops over route files and groups them there draws no route → middleware edge at all.
        Route::middleware('api')->group(function (): void {
            Route::get('/api/a', fn (): string => 'a');
            Route::get('/api/b', fn (): string => 'b');
        });
    }

    protected function tearDown(): void
    {
        new Filesystem()->deleteDirectory($this->projectRoot);
        parent::tearDown();
    }

    #[Test]
    public function a_changed_group_middleware_is_sized_in_the_report(): void
    {
        $result = new ImpactAnalyzer($this->graph())->detectChanges([$this->changedMiddleware()]);

        $findings = $result['findings'];
        $this->assertIsArray($findings);
        $this->assertContains(
            "App\\Http\\Middleware\\EnsureTenant runs in middleware group 'api', which guards 2 routes; group membership is not drawn as edges, so those routes are not in the reach above",
            $findings,
        );
    }

    #[Test]
    public function the_note_never_moves_the_risk_level(): void
    {
        // Letting a group's routes count would raise the level of every middleware edit in every
        // consuming app at once. The note is advisory; the risk must match the graph, which still
        // draws no edge from the group to its members.
        $withKernel = new ImpactAnalyzer($this->graph())->detectChanges([$this->changedMiddleware()]);

        unlink("{$this->projectRoot}/app/Http/Kernel.php");
        $withoutKernel = new ImpactAnalyzer($this->graph())->detectChanges([$this->changedMiddleware()]);

        $this->assertNotSame($withKernel['findings'], $withoutKernel['findings']);
        $this->assertSame($withoutKernel['risk'], $withKernel['risk']);
        $this->assertSame($withoutKernel['entryPoints'], $withKernel['entryPoints']);
    }

    private function changedMiddleware(): ChangedFileSymbols
    {
        return new ChangedFileSymbols(
            'app/Http/Middleware/EnsureTenant.php',
            self::TENANT,
            [new MemberChange('handle', MemberChange::KIND_METHOD, MemberChange::CHANGE_MODIFIED, true)],
            cosmeticOnly: false,
        );
    }

    private function graph(): CodeGraph
    {
        // Deliberately no route → middleware edge: the graph cannot see a group applied from a
        // provider, and the note must still be sized correctly.
        return new CodeGraph([
            ['source' => self::TENANT, 'target' => self::TENANT . '::handle', 'type' => 'declares'],
        ], hasUnparseableFiles: false);
    }
}
