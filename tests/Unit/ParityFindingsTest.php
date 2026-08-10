<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Analysis\FrontendConsumerIndex;
use SanderMuller\Richter\Analysis\FrontendConsumerLane;
use SanderMuller\Richter\Analysis\ParityFindings;
use SanderMuller\Richter\Analysis\RequestFieldParityChecker;
use SanderMuller\Richter\Changes\ChangedFileSymbols;
use SanderMuller\Richter\Graph\CodeGraph;
use SanderMuller\Richter\Tests\TestCase;

/**
 * The family's dispatch: which changed-file trigger reaches which lane. The lanes' own matching is
 * covered by their tests — what this pins is that a form-request change is handed to the request
 * lane at all, and that the shared `payload_parity` gate switches it off with the rest.
 */
final class ParityFindingsTest extends TestCase
{
    private const string REQUEST = 'App\Http\Requests\StorePostRequest';

    private string $projectRoot;

    protected function setUp(): void
    {
        parent::setUp();
        Route::post('/posts', ['App\Http\Controllers\PostController', 'store'])->name('posts.store');
        $this->projectRoot = sys_get_temp_dir() . '/richter-parity-dispatch-' . bin2hex(random_bytes(8));
        mkdir("{$this->projectRoot}/resources/js", recursive: true);
        file_put_contents("{$this->projectRoot}/resources/js/create.ts", "fetch('/posts', { method: 'POST', body: JSON.stringify({ subtitle }) });");
        config()->set('richter.frontend.roots', ['resources/js']);
    }

    protected function tearDown(): void
    {
        new Filesystem()->deleteDirectory($this->projectRoot);
        parent::tearDown();
    }

    #[Test]
    public function a_removed_rules_field_reaches_the_request_lane(): void
    {
        $findings = ParityFindings::for($this->changedRequest(['subtitle'], []), null, null, $this->requestLane());

        $this->assertCount(1, $findings);
        $this->assertStringContainsString("sends 'subtitle'", $findings[0]);
    }

    #[Test]
    public function a_request_change_with_no_removed_field_never_reaches_the_lane(): void
    {
        $this->assertSame([], ParityFindings::for($this->changedRequest([], ['subtitle']), null, null, $this->requestLane()));
    }

    #[Test]
    public function the_shared_gate_switches_the_request_lane_off_with_the_rest(): void
    {
        config()->set('richter.payload_parity.enabled', false);

        $this->assertSame([null, null, null], ParityFindings::checkers(new CodeGraph([], hasUnparseableFiles: false), null));
    }

    #[Test]
    public function the_gate_left_on_constructs_all_three_lanes(): void
    {
        config()->set('richter.payload_parity.enabled', true);

        $checkers = ParityFindings::checkers(new CodeGraph([], hasUnparseableFiles: false), null);

        $this->assertNotContains(null, $checkers);
        $this->assertCount(3, $checkers);
    }

    /**
     * @param  list<string>  $removed
     * @param  list<string>  $added
     */
    private function changedRequest(array $removed, array $added): ChangedFileSymbols
    {
        return new ChangedFileSymbols(
            'app/Http/Requests/StorePostRequest.php',
            self::REQUEST,
            members: [],
            cosmeticOnly: false,
            removedRequestFields: $removed,
            addedRequestFields: $added,
        );
    }

    private function requestLane(): RequestFieldParityChecker
    {
        $graph = new CodeGraph([
            ['source' => 'route::POST::/posts', 'target' => 'App\Http\Controllers\PostController::store', 'type' => 'action'],
            ['source' => 'App\Http\Controllers\PostController::store', 'target' => self::REQUEST . '::validated', 'type' => 'action-to-form-request'],
        ], hasUnparseableFiles: false);

        return new RequestFieldParityChecker(new FrontendConsumerLane($graph, [], $this->projectRoot, FrontendConsumerIndex::fromProject($this->projectRoot)));
    }
}
