<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Analysis\FrontendConsumerIndex;
use SanderMuller\Richter\Analysis\FrontendConsumerLane;
use SanderMuller\Richter\Analysis\FrontendConsumerParityChecker;
use SanderMuller\Richter\Graph\CodeGraph;
use SanderMuller\Richter\Tests\TestCase;

/**
 * Temp-dir consumer files and a synthetic graph — never the shared fixture project. The
 * graph gives the resource one caller route; the index maps that route to the consumer
 * files written per test.
 */
final class FrontendConsumerParityCheckerTest extends TestCase
{
    private const string RESOURCE = 'App\Http\Resources\PostResource';

    private const string ROUTE_NODE = 'route::GET::/posts/{post}';

    private string $projectRoot;

    protected function setUp(): void
    {
        parent::setUp();
        Route::get('/posts/{post}', ['App\Http\Controllers\PostController', 'show'])->name('posts.show');
        $this->projectRoot = sys_get_temp_dir() . '/richter-consumer-parity-' . bin2hex(random_bytes(8));
        mkdir("{$this->projectRoot}/resources/js", recursive: true);
        mkdir("{$this->projectRoot}/resources/views", recursive: true);
    }

    protected function tearDown(): void
    {
        new Filesystem()->deleteDirectory($this->projectRoot);
        parent::tearDown();
    }

    #[Test]
    public function a_consumer_reading_the_removed_key_produces_a_finding_with_the_rename_hint(): void
    {
        $this->consumerFile('resources/js/post.ts', "const post = await fetch(`/posts/1`).then((r) => r.json());\nreturn post.published_at;");

        $findings = $this->checker()->findingsFor(self::RESOURCE, ['published_at'], ['publishedAt']);

        $this->assertSame(
            ["resources/js/post.ts references GET /posts/{post} and reads 'published_at', which this diff removes from App\\Http\\Resources\\PostResource (renamed to 'publishedAt'?)"],
            $findings,
        );
    }

    #[Test]
    public function multiple_co_added_keys_get_the_generic_note_never_a_similarity_guess(): void
    {
        $this->consumerFile('resources/js/post.ts', "fetch('/posts/1');\npost['published_at'];");

        $findings = $this->checker()->findingsFor(self::RESOURCE, ['published_at'], ['a', 'b']);

        $this->assertCount(1, $findings);
        $this->assertStringContainsString("(this diff also adds 'a', 'b')", $findings[0]);
        $this->assertStringNotContainsString('renamed', $findings[0]);
    }

    #[Test]
    public function a_consumer_without_an_access_shaped_read_stays_silent(): void
    {
        // The key appears as a translation-ish bare token — not an access.
        $this->consumerFile('resources/js/post.ts', "fetch('/posts/1');\nconst label = translate('published_at description');");

        $this->assertSame([], $this->checker()->findingsFor(self::RESOURCE, ['published_at'], []));
    }

    #[Test]
    public function a_blade_consumer_is_scanned_on_script_slices_only(): void
    {
        // Server-side PHP reads the key outside the script block — not a consumer read.
        $this->consumerFile('resources/views/widget.blade.php', "<?php \$item['published_at']; ?>\n<script>fetch('/posts/1');</script>");
        $this->consumerFile('resources/views/reader.blade.php', "<script>fetch('/posts/2').then((r) => r.json()).then((p) => p.published_at);</script>");

        $findings = $this->checker()->findingsFor(self::RESOURCE, ['published_at'], []);

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('reader.blade.php', $findings[0]);
    }

    #[Test]
    public function the_ignore_forms_suppress_the_resource_or_a_single_key(): void
    {
        $this->consumerFile('resources/js/post.ts', 'post.published_at;' . "\nfetch('/posts/1');");

        $wholeResource = $this->checker(ignore: [self::RESOURCE]);
        $singleKey = $this->checker(ignore: [self::RESOURCE . '::published_at']);

        $this->assertSame([], $wholeResource->findingsFor(self::RESOURCE, ['published_at'], []));
        $this->assertSame([], $singleKey->findingsFor(self::RESOURCE, ['published_at'], []));
    }

    #[Test]
    public function the_finding_names_the_specific_affected_route_not_every_route_the_file_calls(): void
    {
        // The consumer file also calls an unrelated registered route — attribution is
        // per affected route, never per-file guesswork.
        Route::get('/dashboard', ['App\Http\Controllers\DashboardController', 'index'])->name('dashboard');
        $this->consumerFile('resources/js/post.ts', "fetch('/dashboard');\nfetch('/posts/1').then((r) => r.json()).then((p) => p.published_at);");

        $findings = $this->checker()->findingsFor(self::RESOURCE, ['published_at'], []);

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('GET /posts/{post}', $findings[0]);
        $this->assertStringNotContainsString('/dashboard', $findings[0]);
    }

    #[Test]
    public function an_empty_removed_key_flags_nothing_rather_than_every_consumer(): void
    {
        // `['' => …]` is a legal array key and the parser reports it faithfully, but as a match
        // pattern it degenerates: `.` followed by a word boundary hits any property read at all.
        $this->consumerFile('resources/js/post.ts', "fetch('/posts/1').then((r) => r.json()).then((p) => p.title);");

        $this->assertSame([], $this->checker()->findingsFor(self::RESOURCE, [''], []));
    }

    #[Test]
    public function a_resource_reaching_no_route_stays_silent(): void
    {
        $this->consumerFile('resources/js/post.ts', "fetch('/posts/1').then((r) => r.json()).then((p) => p.published_at);");

        $checker = new FrontendConsumerParityChecker(
            new FrontendConsumerLane(new CodeGraph([], hasUnparseableFiles: false), projectRoot: $this->projectRoot, index: $this->index()),
        );

        $this->assertSame([], $checker->findingsFor(self::RESOURCE, ['published_at'], []));
    }

    private function consumerFile(string $relative, string $content): void
    {
        file_put_contents("{$this->projectRoot}/{$relative}", $content);
    }

    /** @param  list<string>  $ignore */
    private function checker(array $ignore = []): FrontendConsumerParityChecker
    {
        // One resource edge from the route suffices: callersOf(resource) reaches the route node.
        $graph = new CodeGraph([
            ['source' => self::ROUTE_NODE, 'target' => self::RESOURCE, 'type' => 'resource'],
        ], hasUnparseableFiles: false);

        return new FrontendConsumerParityChecker(new FrontendConsumerLane($graph, $ignore, $this->projectRoot, $this->index()));
    }

    private function index(): FrontendConsumerIndex
    {
        config()->set('richter.frontend.roots', ['resources/js']);

        return FrontendConsumerIndex::fromProject($this->projectRoot);
    }
}
