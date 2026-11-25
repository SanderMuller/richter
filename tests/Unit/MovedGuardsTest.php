<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Analysis\Hazard;
use SanderMuller\Richter\Analysis\Hazards\GuardCalls;
use SanderMuller\Richter\Analysis\Hazards\HazardLanes;
use SanderMuller\Richter\Analysis\ImpactAnalyzer;
use SanderMuller\Richter\Analysis\TestReferenceIndex;
use SanderMuller\Richter\Changes\ChangedFileSymbols;
use SanderMuller\Richter\Graph\CodeGraph;
use SanderMuller\Richter\Tests\TestCase;

final class MovedGuardsTest extends TestCase
{
    private const string POSTS = 'App\Http\Controllers\PostController';

    private const string COMMENTS = 'App\Http\Controllers\CommentController';

    private const string REQUEST = 'App\Http\Requests\UpdatePostRequest';

    private const string CHECK = "Gate::authorize('update', \$post);";

    private const string REQUEST_CHECK = "public function authorize(): bool { return Gate::allows('update', \$this->route('post')); }";

    private const string GONE = 'the authorization check `ability:update` is gone from the body';

    /** A controller with two actions, one with one, a request the first one takes, and a service. */
    private const array EDGES = [
        ['source' => 'route::PUT::/posts/{post}', 'target' => self::POSTS, 'type' => 'route-to-controller'],
        ['source' => self::POSTS, 'target' => self::POSTS . '::update', 'type' => 'controller-to-action'],
        ['source' => self::POSTS, 'target' => self::POSTS . '::show', 'type' => 'controller-to-action'],
        ['source' => self::POSTS . '::update', 'target' => self::REQUEST, 'type' => 'references'],
        ['source' => 'route::POST::/comments', 'target' => self::COMMENTS, 'type' => 'route-to-controller'],
        ['source' => self::COMMENTS, 'target' => self::COMMENTS . '::__invoke', 'type' => 'controller-to-action'],
        ['source' => self::COMMENTS . '::__invoke', 'target' => 'App\Services\PostEditor::edit', 'type' => 'instance-call'],
        ['source' => self::POSTS . '::update', 'target' => 'App\Services\PostEditor::edit', 'type' => 'instance-call'],
        ['source' => 'App\Services\PostArchiver', 'target' => 'App\Services\PostArchiver::archive', 'type' => 'declares'],
        ['source' => 'App\Http\Middleware\EnsureEditor', 'target' => 'App\Http\Middleware\EnsureEditor::handle', 'type' => 'declares'],
    ];

    /** @return array{0: string, 1: string|null, 2: string} */
    private function changed(string $fqcn, ?string $base, string $head): array
    {
        $namespace = substr($fqcn, 0, (int) strrpos($fqcn, '\\'));
        $class = substr($fqcn, strlen($namespace) + 1);
        $parent = str_contains($fqcn, '\Requests\\') ? ' extends \Illuminate\Foundation\Http\FormRequest' : '';
        $source = static fn (string $members): string => "<?php\nnamespace {$namespace};\nuse Illuminate\\Support\\Facades\\Gate;\nclass {$class}{$parent}\n{\n{$members}\n}\n";

        return ['app/' . str_replace('\\', '/', substr($fqcn, 4)) . '.php', $base === null ? null : $source($base), $source($head)];
    }

    /** @return array{0: string, 1: string|null, 2: string} */
    private function method(string $member, string $baseBody, string $headBody): array
    {
        [$fqcn, $name] = explode('::', $member);

        return $this->changed($fqcn, "public function {$name}(\$post) { {$baseBody} }", "public function {$name}(\$post) { {$headBody} }");
    }

    /**
     * @param  list<array{0: string, 1: string|null, 2: string}>  $files
     * @return array{0: list<string>, 1: list<string>} the hazards' evidence and the findings
     */
    private function report(array $files, ?bool $hazards = null): array
    {
        $changed = array_map(static function (array $file): ChangedFileSymbols {
            [$path, $base, $head] = $file;
            [$found, $added] = HazardLanes::for($path, $base === null, $head, $base);

            return new ChangedFileSymbols($path, '', [], cosmeticOnly: false, hazards: $found, addedHazardTokens: $added, guardArrivals: GuardCalls::arrivals($path, $head, $base, $base === null));
        }, $files);

        $result = new ImpactAnalyzer(new CodeGraph(self::EDGES, hasUnparseableFiles: false))
            ->detectChanges($changed, tests: new TestReferenceIndex(), hazardsEnabled: $hazards);

        return [array_map(static fn (Hazard $hazard): string => $hazard->evidence, $result['hazards']), $result['findings']];
    }

    #[Test]
    public function a_check_that_moved_to_the_request_of_its_action_is_a_finding_that_names_both_places(): void
    {
        $expected = [[], [
            'App\Http\Controllers\PostController::update — `Gate::authorize(\'update\', $post)` is gone; the check is now in '
            . 'App\Http\Requests\UpdatePostRequest::authorize (`Gate::allows(\'update\', $this->route(\'post\'))`), and a path leads there from '
            . 'App\Http\Controllers\PostController::update. Check that it always runs and refuses, and that it decides about the same object',
        ]];

        // Whether the request existed before makes no difference once the path and the refusal prove the move.
        foreach ([null, ''] as $base) {
            $this->assertSame($expected, $this->report([
                $this->method(self::POSTS . '::update', self::CHECK, ''),
                $this->changed(self::REQUEST, $base, self::REQUEST_CHECK),
            ]));
        }
    }

    #[Test]
    public function a_check_that_moved_to_where_its_action_does_not_lead_stays_a_hazard(): void
    {
        foreach ([null, ''] as $base) {
            [$hazards, $findings] = $this->report([
                $this->method(self::POSTS . '::show', self::CHECK, ''),
                $this->changed(self::REQUEST, $base, self::REQUEST_CHECK),
            ]);

            $this->assertSame([self::GONE . '; the check is now in App\Http\Requests\UpdatePostRequest::authorize (`Gate::allows(\'update\', $this->route(\'post\'))`), and richter found no path there from App\Http\Controllers\PostController::show'], $hazards);
            $this->assertSame([], $findings);
        }
    }

    #[Test]
    public function a_check_that_left_one_of_the_actions_a_service_runs_for_names_the_one_it_left(): void
    {
        [$hazards] = $this->report([
            $this->method('App\Services\PostEditor::edit', self::CHECK, ''),
            $this->changed(self::REQUEST, '', self::REQUEST_CHECK),
        ]);

        // Its controller has one action, so the route is that action's.
        $this->assertCount(1, $hazards);
        $this->assertStringEndsWith('richter found no path there from App\Http\Controllers\CommentController::__invoke [route::POST::/comments]', $hazards[0]);
    }

    #[Test]
    public function a_check_that_moved_from_a_request_to_the_action_that_takes_it_is_a_move_and_a_member_that_is_gone(): void
    {
        [$hazards, $findings] = $this->report([
            $this->changed(self::REQUEST, self::REQUEST_CHECK, ''),
            $this->method(self::POSTS . '::update', '', self::CHECK),
        ]);

        $this->assertSame(['the public method is gone'], $hazards);
        $this->assertCount(1, $findings);
        $this->assertStringContainsString('a path leads there from App\Http\Controllers\PostController::update', $findings[0]);
    }

    #[Test]
    public function a_check_that_moved_from_a_request_to_an_action_that_does_not_take_it_stays_a_hazard(): void
    {
        [$hazards, $findings] = $this->report([
            $this->changed(self::REQUEST, self::REQUEST_CHECK, ''),
            $this->method(self::COMMENTS . '::__invoke', '', self::CHECK),
        ]);

        $this->assertCount(1, $hazards);
        $this->assertStringEndsWith('richter found no path there from App\Http\Controllers\PostController::update', $hazards[0]);
        $this->assertSame([], $findings);
    }

    #[Test]
    public function only_the_places_that_lead_from_the_action_are_named(): void
    {
        [, $findings] = $this->report([
            $this->method(self::POSTS . '::update', self::CHECK, ''),
            $this->changed(self::REQUEST, '', self::REQUEST_CHECK),
            $this->changed('App\Http\Requests\UpdateCommentRequest', '', self::REQUEST_CHECK),
        ]);

        $this->assertCount(1, $findings);
        $this->assertStringNotContainsString('UpdateCommentRequest', $findings[0]);
    }

    #[Test]
    public function a_check_that_moved_to_where_richter_cannot_follow_is_not_compared(): void
    {
        // The framework runs a middleware, so a path that is missing says nothing about it.
        [$hazards, $findings] = $this->report([
            $this->method(self::POSTS . '::update', self::CHECK, ''),
            $this->method('App\Http\Middleware\EnsureEditor::handle', '', self::CHECK),
        ]);

        $this->assertSame([], $hazards);
        $this->assertCount(1, $findings);
        $this->assertStringEndsWith('App\Http\Middleware\EnsureEditor::handle (`Gate::authorize(\'update\', $post)`), and where it runs was not compared', $findings[0]);

        // A new file must prove the move.
        [$hazards, $findings] = $this->report([
            $this->method(self::POSTS . '::update', self::CHECK, ''),
            $this->changed('App\Http\Middleware\EnsureOwner', null, 'public function handle($post) { ' . self::CHECK . ' }'),
        ]);

        $this->assertCount(1, $hazards);
        $this->assertStringEndsWith('and richter did not prove that it runs and refuses there', $hazards[0]);
        $this->assertSame([], $findings);
    }

    #[Test]
    public function a_new_file_that_may_not_refuse_does_not_answer_for_a_removed_check(): void
    {
        // A path leads to each of these. None of them certainly refuses.
        $request = fn (string $body): array => $this->changed(self::REQUEST, null, "public function authorize(): bool { {$body} }");

        foreach ([
            $this->changed('App\Services\PostEditor', null, 'public function edit($post) { ' . self::CHECK . ' }'),
            $request("return Gate::denies('update');"),
            $request("return \$this->user()->cannot('update');"),
            $request("return ! Gate::allows('update');"),
            $request("if (Gate::allows('update')) { return false; } return true;"),
            $request("if (! Gate::inspect('update')) { return false; } return true;"),
            $this->changed(self::REQUEST, null, self::REQUEST_CHECK . ' protected function passesAuthorization(): bool { return true; }'),
            $this->changed(self::REQUEST, null, self::REQUEST_CHECK . ' protected function failedAuthorization(): void {}'),
            $request("if (config('app.debug')) { Gate::authorize('update'); } return true;"),
            $request("if (Gate::denies('update')) { logger('denied'); } return true;"),
            $request("if (\$this->isDraft()) { return true; } return Gate::allows('update');"),
            $request("return Gate::allows('update') || \$this->isDraft();"),
            $request("try { Gate::authorize('update'); } catch (\\Throwable) {} return true;"),
            $request("dispatch(function () { Gate::authorize('update'); }); return true;"),
        ] as $file) {
            [$hazards, $findings] = $this->report([$this->method(self::POSTS . '::update', self::CHECK, ''), $file]);

            $this->assertCount(1, $hazards, (string) $file[2]);
            $this->assertStringContainsString('the check is now in ', $hazards[0]);
            $this->assertSame([], $findings, (string) $file[2]);
        }
    }

    #[Test]
    public function a_new_request_that_certainly_refuses_answers_for_a_removed_check(): void
    {
        foreach ([
            "Gate::authorize('update'); return true;",
            "return \$this->isDraft() && Gate::allows('update');",
            "return ! \$this->user()->cannot('update');",
            "return Gate::inspect('update');",
            "if (Gate::inspect('update')->denied()) { return false; } return true;",
            "if (! Gate::allows('update')) { return false; } return true;",
            "if (Gate::denies('update') || \$this->isLocked()) { return false; } return \$this->isDraft();",
        ] as $body) {
            [$hazards, $findings] = $this->report([
                $this->method(self::POSTS . '::update', self::CHECK, ''),
                $this->changed(self::REQUEST, null, "public function authorize(): bool { {$body} }"),
            ]);

            $this->assertSame([], $hazards, $body);
            $this->assertCount(1, $findings, $body);
        }
    }

    #[Test]
    public function a_check_on_something_that_may_be_no_user_does_not_answer_for_a_removed_one(): void
    {
        foreach ([null, 'public function edit($encoder) {}'] as $base) {
            [$hazards, $findings] = $this->report([
                $this->method(self::POSTS . '::update', self::CHECK, ''),
                $this->changed('App\Services\PostEditor', $base, "public function edit(\$encoder) { if (\$encoder->can('update')) { echo 1; } }"),
            ]);

            $this->assertSame([self::GONE], $hazards);
            $this->assertSame([], $findings);
        }
    }

    #[Test]
    public function a_method_nothing_calls_that_gains_the_check_leaves_a_proven_loss_a_hazard(): void
    {
        // The graph holds a node for the first method, and for the second only its class.
        foreach (['App\Services\PostArchiver::archive', 'App\Services\PostArchiver::restore'] as $uncalled) {
            [$hazards, $findings] = $this->report([
                $this->method(self::POSTS . '::show', self::CHECK, ''),
                $this->method(self::POSTS . '::update', '', self::CHECK),
                $this->method($uncalled, '', self::CHECK),
            ]);

            $this->assertCount(1, $hazards);
            $this->assertStringEndsWith('richter found no path there from App\Http\Controllers\PostController::show', $hazards[0]);
            $this->assertSame([], $findings);
        }
    }

    #[Test]
    public function a_check_whose_ability_is_computed_is_not_matched_in_a_new_file(): void
    {
        [$hazards] = $this->report([
            $this->method(self::POSTS . '::update', 'Gate::authorize($ability, $post);', ''),
            $this->changed(self::REQUEST, null, 'public function authorize() { return Gate::authorize($this->ability); }'),
        ]);

        $this->assertSame(['the authorization check `call:authorize` is gone from the body'], $hazards);
    }

    #[Test]
    public function a_moved_middleware_is_not_compared(): void
    {
        $this->assertSame([[], []], $this->report([
            $this->changed(self::POSTS, "public function __construct() { \$this->middleware('auth'); }", 'public function __construct() {}'),
            $this->changed(self::COMMENTS, 'public function __construct() {}', "public function __construct() { \$this->middleware('auth'); }"),
        ]));
    }

    #[Test]
    public function an_ignored_member_is_silent_as_a_hazard_and_as_a_finding(): void
    {
        Config::set('richter.hazards.ignore', [self::POSTS . '::update']);

        $this->assertSame([[], []], $this->report([
            $this->method(self::POSTS . '::update', self::CHECK, ''),
            $this->changed(self::REQUEST, '', self::REQUEST_CHECK),
        ]));
    }

    #[Test]
    public function disabled_hazards_leave_no_finding(): void
    {
        $this->assertSame([[], []], $this->report([
            $this->method(self::POSTS . '::update', self::CHECK, ''),
            $this->changed(self::REQUEST, '', self::REQUEST_CHECK),
        ], hazards: false));
    }
}
