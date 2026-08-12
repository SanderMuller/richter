<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Analysis\FrontendConsumerIndex;
use SanderMuller\Richter\Analysis\FrontendConsumerLane;
use SanderMuller\Richter\Analysis\RequestFieldParityChecker;
use SanderMuller\Richter\Graph\CodeGraph;
use SanderMuller\Richter\Tests\TestCase;

/**
 * Temp-dir consumer files and a synthetic graph — never the shared fixture project. The graph gives
 * the form request one caller route through the action that type-hints it; the index maps that
 * route to the consumer files written per test.
 */
final class RequestFieldParityCheckerTest extends TestCase
{
    private const string REQUEST = 'App\Http\Requests\StorePostRequest';

    private const string ROUTE_NODE = 'route::POST::/posts';

    private const string ACTION = 'App\Http\Controllers\PostController::store';

    private string $projectRoot;

    protected function setUp(): void
    {
        parent::setUp();
        Route::post('/posts', ['App\Http\Controllers\PostController', 'store'])->name('posts.store');
        // A GET twin on the same URI: a validated field is not always a POST body, and the finding
        // has to name whichever verb the route actually carries.
        Route::get('/posts', ['App\Http\Controllers\PostController', 'index'])->name('posts.index');
        $this->projectRoot = sys_get_temp_dir() . '/richter-request-parity-' . bin2hex(random_bytes(8));
        mkdir("{$this->projectRoot}/resources/js", recursive: true);
        mkdir("{$this->projectRoot}/resources/views", recursive: true);
    }

    protected function tearDown(): void
    {
        new Filesystem()->deleteDirectory($this->projectRoot);
        parent::tearDown();
    }

    #[Test]
    public function an_object_literal_sending_the_removed_field_produces_a_finding_with_the_rename_hint(): void
    {
        $this->consumerFile('resources/js/post.ts', "await fetch('/posts', { method: 'POST', body: JSON.stringify({ subtitle: value }) });");

        $findings = $this->checker()->findingsFor(self::REQUEST, ['subtitle'], ['sub_title']);

        $this->assertSame(
            ["resources/js/post.ts sends 'subtitle' to POST /posts, which this diff removes from App\\Http\\Requests\\StorePostRequest::rules() (renamed to 'sub_title'?)"],
            $findings,
        );
    }

    #[Test]
    public function the_shorthand_property_form_counts_as_sending(): void
    {
        $this->consumerFile('resources/js/post.ts', "fetch('/posts', { method: 'POST', body: JSON.stringify({ title, subtitle }) });");

        $this->assertCount(1, $this->checker()->findingsFor(self::REQUEST, ['subtitle'], []));
    }

    #[Test]
    public function a_form_data_append_counts_as_sending(): void
    {
        $this->consumerFile('resources/js/post.ts', "const body = new FormData();\nbody.append('subtitle', v);\nfetch('/posts', { method: 'POST', body });");

        $this->assertCount(1, $this->checker()->findingsFor(self::REQUEST, ['subtitle'], []));
    }

    #[Test]
    public function an_assignment_onto_the_payload_counts_as_sending(): void
    {
        $this->consumerFile('resources/js/post.ts', "const payload = {};\npayload.subtitle = v;\npayload['legacy'] = w;\nfetch('/posts', { method: 'POST' });");

        $findings = $this->checker()->findingsFor(self::REQUEST, ['subtitle', 'legacy'], []);

        $this->assertCount(2, $findings);
    }

    #[Test]
    public function a_bare_mention_of_the_field_name_stays_silent(): void
    {
        // A label or a comparison is not a send — the same no-bare-token rule the response lane has.
        $this->consumerFile('resources/js/post.ts', "fetch('/posts', { method: 'POST' });\nconst label = translate('subtitle helper text');\nif (mode === 'subtitle') return;");

        $this->assertSame([], $this->checker()->findingsFor(self::REQUEST, ['subtitle'], []));
    }

    #[Test]
    public function a_dotted_rule_key_matches_nothing(): void
    {
        // `items.*.name` names an array-element rule. Its segments appear separately in a payload,
        // and matching the last one would fire on every unrelated `name` in the file.
        $this->consumerFile('resources/js/post.ts', "fetch('/posts', { method: 'POST', body: JSON.stringify({ items: [{ name: v }] }) });");

        $this->assertSame([], $this->checker()->findingsFor(self::REQUEST, ['items.*.name'], []));
    }

    #[Test]
    public function multiple_co_added_fields_get_the_generic_note_never_a_similarity_guess(): void
    {
        $this->consumerFile('resources/js/post.ts', "fetch('/posts', { method: 'POST', body: JSON.stringify({ subtitle }) });");

        $findings = $this->checker()->findingsFor(self::REQUEST, ['subtitle'], ['a', 'b']);

        $this->assertCount(1, $findings);
        $this->assertStringContainsString("(this diff also adds 'a', 'b')", $findings[0]);
        $this->assertStringNotContainsString('renamed', $findings[0]);
    }

    #[Test]
    public function the_ignore_forms_suppress_the_request_or_a_single_field(): void
    {
        $this->consumerFile('resources/js/post.ts', "fetch('/posts', { method: 'POST', body: JSON.stringify({ subtitle }) });");

        $wholeRequest = $this->checker(ignore: [self::REQUEST]);
        $singleField = $this->checker(ignore: [self::REQUEST . '::subtitle']);

        $this->assertSame([], $wholeRequest->findingsFor(self::REQUEST, ['subtitle'], []));
        $this->assertSame([], $singleField->findingsFor(self::REQUEST, ['subtitle'], []));
    }

    #[Test]
    public function a_blade_consumer_is_scanned_on_script_slices_only(): void
    {
        // Server-side PHP building the same key outside the script block is not a consumer send.
        $this->consumerFile('resources/views/form.blade.php', "<?php \$payload['subtitle'] = 1; ?>\n<script>fetch('/posts', { method: 'POST' });</script>");
        $this->consumerFile('resources/views/sender.blade.php', "<script>fetch('/posts', { method: 'POST', body: JSON.stringify({ subtitle }) });</script>");

        $findings = $this->checker()->findingsFor(self::REQUEST, ['subtitle'], []);

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('sender.blade.php', $findings[0]);
    }

    #[Test]
    public function a_form_request_reaching_no_route_stays_silent(): void
    {
        $this->consumerFile('resources/js/post.ts', "fetch('/posts', { method: 'POST', body: JSON.stringify({ subtitle }) });");

        $checker = new RequestFieldParityChecker(
            new FrontendConsumerLane(new CodeGraph([], hasUnparseableFiles: false), projectRoot: $this->projectRoot, index: $this->index()),
        );

        $this->assertSame([], $checker->findingsFor(self::REQUEST, ['subtitle'], []));
    }

    private function consumerFile(string $relative, string $content): void
    {
        file_put_contents("{$this->projectRoot}/{$relative}", $content);
    }

    #[Test]
    public function an_inline_anchor_is_named_as_the_member_never_as_a_rules_method(): void
    {
        // Inline validation is anchored on the member holding the call. Appending `::rules()` there
        // names `PostController::store::rules()` — a symbol that does not exist, and the wrong ignore
        // target for a reader to copy out of the report.
        $this->consumerFile('resources/js/post.ts', "fetch('/posts', { method: 'POST', body: JSON.stringify({ subtitle }) });");

        $findings = $this->checker()->findingsFor(self::ACTION, ['subtitle'], []);

        $this->assertCount(1, $findings);
        $this->assertStringContainsString(self::ACTION, $findings[0]);
        $this->assertStringNotContainsString('::rules()', $findings[0]);
    }

    #[Test]
    public function a_query_parameter_on_a_get_route_is_matched_and_named_with_that_route_verb(): void
    {
        // A sent field is not always a POST body. The matcher already handled a `params:` object on a
        // GET route; the sentence did not, and read "posts to GET /posts" — contradicting itself, in
        // a report whose whole value is being trusted. The route's own verb is printed, none assumed.
        $this->consumerFile('resources/js/search.ts', "axios.get('/posts', { params: { subtitle } });");

        $this->assertSame(
            ["resources/js/search.ts sends 'subtitle' to GET /posts, which this diff removes from App\\Http\\Requests\\StorePostRequest::rules()"],
            $this->checker(routeNode: 'route::GET::/posts')->findingsFor(self::REQUEST, ['subtitle'], []),
        );
    }

    #[Test]
    public function the_ignore_forms_work_against_an_inline_member_anchor_too(): void
    {
        // The published config documents `Controller::method::field`. The lane matches on a plain
        // `"{$anchor}::"` prefix, so a member anchor gives a three-segment entry — worth pinning
        // rather than trusting, since the documented form is what a reader will copy.
        $this->consumerFile('resources/js/post.ts', "fetch('/posts', { method: 'POST', body: JSON.stringify({ subtitle }) });");

        $wholeMember = $this->checker(ignore: [self::ACTION]);
        $singleField = $this->checker(ignore: [self::ACTION . '::subtitle']);

        $this->assertSame([], $wholeMember->findingsFor(self::ACTION, ['subtitle'], []));
        $this->assertSame([], $singleField->findingsFor(self::ACTION, ['subtitle'], []));
        $this->assertCount(1, $this->checker()->findingsFor(self::ACTION, ['subtitle'], []));
    }

    /** @param  list<string>  $ignore */
    private function checker(array $ignore = [], string $routeNode = self::ROUTE_NODE): RequestFieldParityChecker
    {
        // The shape Brain draws for a type-hinted form request: route → action → `Fqcn::validated`.
        // `callersOf` walks it back up to the route node.
        $graph = new CodeGraph([
            ['source' => $routeNode, 'target' => self::ACTION, 'type' => 'action'],
            ['source' => self::ACTION, 'target' => self::REQUEST . '::validated', 'type' => 'action-to-form-request'],
        ], hasUnparseableFiles: false);

        return new RequestFieldParityChecker(new FrontendConsumerLane($graph, $ignore, $this->projectRoot, $this->index()));
    }

    private function index(): FrontendConsumerIndex
    {
        config()->set('richter.frontend.roots', ['resources/js']);

        return FrontendConsumerIndex::fromProject($this->projectRoot);
    }
}
