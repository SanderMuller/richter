<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Analysis\DroppedRequestFieldChecker;
use SanderMuller\Richter\Analysis\FrontendConsumerLane;
use SanderMuller\Richter\Changes\ChangedFileSymbols;
use SanderMuller\Richter\Graph\CodeGraph;
use SanderMuller\Richter\Tests\TestCase;

/**
 * Temp-dir validation sources and a synthetic graph — never the shared fixture project. The live
 * router names each route's own action; the graph carries Brain's action → form-request edge and
 * places each node in its file.
 */
final class DroppedRequestFieldCheckerTest extends TestCase
{
    private const string STORE = 'App\Http\Controllers\PostController::store';

    private const string UPDATE = 'App\Http\Controllers\PostController::update';

    private const string ARCHIVE = 'App\Http\Controllers\PostController::archive';

    private const string CHILD_UPDATE = 'App\Http\Controllers\DraftController::update';

    private const string REQUEST = 'App\Http\Requests\StorePostRequest';

    private const string ARCHIVE_REQUEST = 'App\Http\Requests\ArchivePostRequest';

    private string $projectRoot;

    protected function setUp(): void
    {
        parent::setUp();
        Route::post('/posts', ['App\Http\Controllers\PostController', 'store']);
        Route::put('/posts/{post}', ['App\Http\Controllers\PostController', 'update']);
        Route::post('/posts/{post}/archive', ['App\Http\Controllers\PostController', 'archive']);
        Route::put('/drafts/{draft}', ['App\Http\Controllers\DraftController', 'update']);
        $this->projectRoot = sys_get_temp_dir() . '/richter-dropped-field-' . bin2hex(random_bytes(8));
        mkdir("{$this->projectRoot}/app", recursive: true);

        $this->writeRequest('StorePostRequest', "'title' => 'required|string', 'subtitle' => 'present|nullable', 'body' => 'nullable|string'");
        $this->writeRequest('ArchivePostRequest', "'reason' => 'required'");
        $this->write('app/PostController.php', "<?php\nnamespace App\\Http\\Controllers;\nclass PostController\n{\n    public function store(\$request) {}\n\n    public function update(\$request)\n    {\n        \$request->validate(['title' => 'required', 'excerpt' => 'sometimes|required']);\n    }\n\n    public function archive(\$request) {}\n}\n");
        // An inherited action: the router names the subclass, the graph places the action in the
        // parent's file, where the method is keyed on the parent class.
        $this->write('app/BasePostController.php', "<?php\nnamespace App\\Http\\Controllers;\nabstract class BasePostController\n{\n    public function update(\$request)\n    {\n        \$request->validate(['slug' => 'required']);\n    }\n}\n");
    }

    protected function tearDown(): void
    {
        new Filesystem()->deleteDirectory($this->projectRoot);
        parent::tearDown();
    }

    #[Test]
    public function a_field_the_form_request_still_requires_is_reported(): void
    {
        $findings = $this->checker()->findingsFor($this->changed('route::POST::/posts', ['title', 'subtitle']));

        $this->assertSame([
            "no longer sends 'title' to POST /posts, which App\\Http\\Requests\\StorePostRequest::rules() still requires",
            "no longer sends 'subtitle' to POST /posts, which App\\Http\\Requests\\StorePostRequest::rules() still requires",
        ], $findings);
    }

    #[Test]
    public function a_field_a_fluent_chain_requires_is_reported(): void
    {
        $this->write('app/StorePostRequest.php', "<?php\nnamespace App\\Http\\Requests;\nuse SanderMuller\\FluentValidation\\FluentRule;\nclass StorePostRequest\n{\n    public function rules(): array\n    {\n        return ['subtitle' => FluentRule::string()->present()->nullable(), 'body' => FluentRule::string()->nullable()];\n    }\n}\n");

        $this->assertSame(
            ["no longer sends 'subtitle' to POST /posts, which App\\Http\\Requests\\StorePostRequest::rules() still requires"],
            $this->checker()->findingsFor($this->changed('route::POST::/posts', ['subtitle', 'body'])),
        );
    }

    #[Test]
    public function a_field_a_schema_method_requires_is_reported_against_it(): void
    {
        $this->write('app/StorePostRequest.php', "<?php\nnamespace App\\Http\\Requests;\nuse Illuminate\\Foundation\\Http\\FormRequest;\nuse SanderMuller\\FluentValidation\\FluentSchema;\nuse SanderMuller\\FluentValidation\\HasFluentRules;\nuse SanderMuller\\FluentValidation\\RuleSet;\nfinal class StorePostRequest extends FormRequest\n{\n    use HasFluentRules;\n\n    final public const string SUBTITLE = 'subtitle';\n\n    public function schema(FluentSchema \$rules): RuleSet\n    {\n        return RuleSet::from([self::SUBTITLE => \$rules->string()->present()->nullable()]);\n    }\n}\n");

        $this->assertSame(
            ["no longer sends 'subtitle' to POST /posts, which App\\Http\\Requests\\StorePostRequest::schema() still requires"],
            $this->checker()->findingsFor($this->changed('route::POST::/posts', ['subtitle'])),
        );
        $this->assertSame([], $this->checker([self::REQUEST . '::subtitle'])->findingsFor($this->changed('route::POST::/posts', ['subtitle'])));
    }

    #[Test]
    public function a_field_both_rules_and_schema_declare_is_silent(): void
    {
        $this->write('app/StorePostRequest.php', "<?php\nnamespace App\\Http\\Requests;\nuse Illuminate\\Foundation\\Http\\FormRequest;\nuse SanderMuller\\FluentValidation\\FluentSchema;\nuse SanderMuller\\FluentValidation\\HasFluentRules;\nfinal class StorePostRequest extends FormRequest\n{\n    use HasFluentRules;\n\n    public function rules(): array\n    {\n        return ['title' => 'required'];\n    }\n\n    public function schema(FluentSchema \$rules): array\n    {\n        return ['title' => \$rules->string()->required()];\n    }\n}\n");

        $this->assertSame([], $this->checker()->findingsFor($this->changed('route::POST::/posts', ['title'])));
    }

    #[Test]
    public function a_field_the_request_does_not_require_is_silent(): void
    {
        $this->assertSame([], $this->checker()->findingsFor($this->changed('route::POST::/posts', ['body', 'unknown'])));
    }

    #[Test]
    public function inline_validation_in_the_action_is_read(): void
    {
        $findings = $this->checker()->findingsFor($this->changed('route::PUT::/posts/{post}', ['title', 'excerpt']));

        // `excerpt` is `sometimes|required`: optional when it is missing.
        $this->assertSame(["no longer sends 'title' to PUT /posts/{post}, which " . self::UPDATE . ' still requires'], $findings);
    }

    #[Test]
    public function only_the_routes_own_action_is_read(): void
    {
        // `reason` is required by the archive action's request, on another route of the same
        // controller. The graph alone would reach it through the controller class.
        $this->assertSame([], $this->checker()->findingsFor($this->changed('route::POST::/posts', ['reason'])));
        $this->assertCount(1, $this->checker()->findingsFor($this->changed('route::POST::/posts/{post}/archive', ['reason'])));
    }

    #[Test]
    public function inline_validation_in_an_inherited_action_is_read_from_the_declaring_file(): void
    {
        $findings = $this->checker()->findingsFor($this->changed('route::PUT::/drafts/{draft}', ['slug']));

        // Named on the declaring member, the key the backend request lane uses for the same rule.
        $this->assertSame(["no longer sends 'slug' to PUT /drafts/{draft}, which App\\Http\\Controllers\\BasePostController::update still requires"], $findings);
    }

    #[Test]
    public function a_form_request_and_inline_validation_on_one_action_both_report(): void
    {
        $findings = $this->checker(extraEdges: [
            ['source' => self::UPDATE, 'target' => self::REQUEST . '::validated', 'type' => 'action-to-form-request'],
        ])->findingsFor($this->changed('route::PUT::/posts/{post}', ['title']));

        $this->assertSame([
            "no longer sends 'title' to PUT /posts/{post}, which App\\Http\\Requests\\StorePostRequest::rules() still requires",
            "no longer sends 'title' to PUT /posts/{post}, which " . self::UPDATE . ' still requires',
        ], $findings);
    }

    #[Test]
    public function a_file_calling_two_routes_reports_against_each_that_requires_the_field(): void
    {
        // File-level: the lane cannot tell which call the field was dropped from. The accepted
        // false-positive class the docs name.
        $changed = new ChangedFileSymbols('resources/js/post.ts', '', [], cosmeticOnly: false, directSeeds: ['route::POST::/posts', 'route::PUT::/posts/{post}'], droppedSentFields: ['route::POST::/posts' => ['title'], 'route::PUT::/posts/{post}' => ['title']]);

        $this->assertCount(2, $this->checker()->findingsFor($changed));
    }

    #[Test]
    public function a_route_the_router_does_not_know_is_silent(): void
    {
        $this->assertSame([], $this->checker()->findingsFor($this->changed('route::DELETE::/posts/{post}', ['title'])));
    }

    /** @return iterable<string, array{string}> */
    public static function inputRewritingMethods(): iterable
    {
        yield 'prepareForValidation' => ['protected function prepareForValidation(): void {}'];
        yield 'validationData' => ['public function validationData(): array { return []; }'];
    }

    #[Test]
    #[DataProvider('inputRewritingMethods')]
    public function a_request_that_rewrites_its_input_is_silent(string $method): void
    {
        // Either method can fill the missing field on the server.
        $this->write('app/StorePostRequest.php', "<?php\nnamespace App\\Http\\Requests;\nclass StorePostRequest\n{\n    {$method}\n\n    public function rules(): array\n    {\n        return ['title' => 'required'];\n    }\n}\n");

        $this->assertSame([], $this->checker()->findingsFor($this->changed('route::POST::/posts', ['title'])));
    }

    #[Test]
    public function an_ignore_entry_suppresses_the_field_the_request_or_the_action(): void
    {
        $changed = $this->changed('route::POST::/posts', ['title', 'subtitle']);

        $this->assertSame(
            ["no longer sends 'subtitle' to POST /posts, which App\\Http\\Requests\\StorePostRequest::rules() still requires"],
            $this->checker([self::REQUEST . '::title'])->findingsFor($changed),
        );
        $this->assertSame([], $this->checker([self::REQUEST])->findingsFor($changed));
        $this->assertSame([], $this->checker([self::UPDATE . '::title'])->findingsFor($this->changed('route::PUT::/posts/{post}', ['title'])));
        $this->assertSame([], $this->checker([self::UPDATE])->findingsFor($this->changed('route::PUT::/posts/{post}', ['title'])));
    }

    #[Test]
    public function a_file_that_dropped_nothing_is_not_checked(): void
    {
        $this->assertSame([], $this->checker()->findingsFor($this->changed('route::POST::/posts', [])));
    }

    #[Test]
    public function the_lane_follows_the_payload_parity_switch(): void
    {
        $graph = new CodeGraph([], hasUnparseableFiles: false);

        $this->assertInstanceOf(DroppedRequestFieldChecker::class, DroppedRequestFieldChecker::fromConfig($graph));
        $this->assertNull(DroppedRequestFieldChecker::fromConfig($graph, false));

        config()->set('richter.payload_parity.enabled', false);
        $this->assertNull(DroppedRequestFieldChecker::fromConfig($graph));
    }

    /** @param  list<string>  $dropped */
    private function changed(string $route, array $dropped): ChangedFileSymbols
    {
        return new ChangedFileSymbols('resources/js/post.ts', '', [], cosmeticOnly: false, directSeeds: [$route], droppedSentFields: $dropped === [] ? [] : [$route => $dropped]);
    }

    /**
     * @param  list<string>  $ignore
     * @param  list<array{source: string, target: string, type: string}>  $extraEdges
     */
    private function checker(array $ignore = [], array $extraEdges = []): DroppedRequestFieldChecker
    {
        $graph = new CodeGraph([
            ...$extraEdges,
            ['source' => self::STORE, 'target' => self::REQUEST . '::validated', 'type' => 'action-to-form-request'],
            ['source' => self::ARCHIVE, 'target' => self::ARCHIVE_REQUEST . '::validated', 'type' => 'action-to-form-request'],
        ], hasUnparseableFiles: false, nodeMetadata: [
            self::STORE => ['file' => 'app/PostController.php'],
            self::UPDATE => ['file' => 'app/PostController.php'],
            self::ARCHIVE => ['file' => 'app/PostController.php'],
            self::CHILD_UPDATE => ['file' => 'app/BasePostController.php'],
            self::REQUEST . '::validated' => ['file' => 'app/StorePostRequest.php'],
            self::ARCHIVE_REQUEST . '::validated' => ['file' => 'app/ArchivePostRequest.php'],
        ]);

        return new DroppedRequestFieldChecker($graph, new FrontendConsumerLane($graph, $ignore), $this->projectRoot);
    }

    private function writeRequest(string $class, string $rules): void
    {
        $this->write("app/{$class}.php", "<?php\nnamespace App\\Http\\Requests;\nclass {$class}\n{\n    public function rules(): array\n    {\n        return [{$rules}];\n    }\n}\n");
    }

    private function write(string $relative, string $content): void
    {
        file_put_contents("{$this->projectRoot}/{$relative}", $content);
    }
}
