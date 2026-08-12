<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Analysis\RequestFieldParser;
use SanderMuller\Richter\Tests\TestCase;

/**
 * The request-parity lane's trigger. Every "yields nothing" case here is a deliberate silence: a
 * fabricated removal would name a field the reviewer then goes looking for in the wrong place.
 */
final class RequestFieldParserTest extends TestCase
{
    private const string PATH = 'app/Http/Requests/StorePostRequest.php';

    #[Test]
    public function it_diffs_the_literal_field_names_of_a_plain_rules_method(): void
    {
        $base = $this->request("'title' => ['required'],\n'subtitle' => ['nullable'],");
        $head = $this->request("'title' => ['required'],\n'sub_title' => ['nullable'],");

        $this->assertSame(
            [['subtitle'], ['sub_title']],
            RequestFieldParser::diffFor(self::PATH, isNew: false, headSrc: $head, baseSrc: $base),
        );
    }

    #[Test]
    public function a_rules_method_that_builds_its_array_up_yields_nothing(): void
    {
        // `$rules = [...]; if (…) …; return $rules;` cannot be enumerated. A reach limit, never a
        // wrong finding.
        $head = <<<'PHP'
            <?php
            namespace App\Http\Requests;
            final class StorePostRequest
            {
                public function rules(): array
                {
                    $rules = ['title' => ['required']];

                    return $rules;
                }
            }
            PHP;

        $this->assertSame(
            [[], []],
            RequestFieldParser::diffFor(self::PATH, isNew: false, headSrc: $head, baseSrc: $this->request("'title' => ['required'],\n'subtitle' => [],")),
        );
    }

    #[Test]
    public function a_spread_in_the_rules_array_yields_nothing(): void
    {
        $head = $this->request("...\$this->shared(),\n'title' => ['required'],");

        $this->assertSame(
            [[], []],
            RequestFieldParser::diffFor(self::PATH, isNew: false, headSrc: $head, baseSrc: $this->request("'title' => [],\n'subtitle' => [],")),
        );
    }

    #[Test]
    public function a_class_constant_key_yields_nothing_because_the_base_side_would_resolve_against_head(): void
    {
        $head = $this->request('self::FIELD => [],');

        $this->assertSame(
            [[], []],
            RequestFieldParser::diffFor(self::PATH, isNew: false, headSrc: $head, baseSrc: $this->request("'subtitle' => [],")),
        );
    }

    #[Test]
    public function a_new_file_yields_nothing_because_no_consumer_sends_a_field_that_never_existed(): void
    {
        $this->assertSame(
            [[], []],
            RequestFieldParser::diffFor(self::PATH, isNew: true, headSrc: $this->request("'title' => [],"), baseSrc: null),
        );

        // Asserted with a readable base too, so it is the new-file flag stopping this and not the
        // missing base source that always accompanies it in practice.
        $this->assertSame(
            [[], []],
            RequestFieldParser::diffFor(self::PATH, isNew: true, headSrc: $this->request("'title' => [],"), baseSrc: $this->request("'title' => [],\n'subtitle' => [],")),
        );
    }

    #[Test]
    public function a_rules_method_outside_the_requests_directory_yields_nothing(): void
    {
        // The path is the whole convention: a `rules()` elsewhere is not addressed by a payload.
        $this->assertSame(
            [[], []],
            RequestFieldParser::diffFor(
                'app/Services/RuleProvider.php',
                isNew: false,
                headSrc: $this->request("'title' => [],"),
                baseSrc: $this->request("'title' => [],\n'subtitle' => [],"),
            ),
        );
    }

    #[Test]
    public function a_dotted_field_name_survives_the_diff_verbatim(): void
    {
        // The checker is what decides a dotted name matches no consumer; the parser must not
        // silently reshape it into something that would.
        $this->assertSame(
            [['items.*.name'], []],
            RequestFieldParser::diffFor(
                self::PATH,
                isNew: false,
                headSrc: $this->request("'items' => ['array'],"),
                baseSrc: $this->request("'items' => ['array'],\n'items.*.name' => ['string'],"),
            ),
        );
    }

    #[Test]
    public function two_rules_methods_in_one_file_yield_nothing(): void
    {
        $head = <<<'PHP'
            <?php
            namespace App\Http\Requests;
            final class StorePostRequest
            {
                public function rules(): array
                {
                    return ['title' => []];
                }
            }
            final class Other
            {
                public function rules(): array
                {
                    return ['other' => []];
                }
            }
            PHP;

        $this->assertSame(
            [[], []],
            RequestFieldParser::diffFor(self::PATH, isNew: false, headSrc: $head, baseSrc: $this->request("'title' => [],\n'subtitle' => [],")),
        );
    }

    private function request(string $rules): string
    {
        return <<<PHP
            <?php
            namespace App\\Http\\Requests;
            final class StorePostRequest
            {
                const string FIELD = 'title';

                public function rules(): array
                {
                    return [
                        {$rules}
                    ];
                }
            }
            PHP;
    }

    #[Test]
    public function it_diffs_inline_validation_per_method(): void
    {
        // A form request is the documented convention and not necessarily where the fields are: on
        // one application inline call sites outnumbered the form requests. Per method, because a
        // controller's other actions validate something else and a file-level diff would report a
        // field removed from one as removed from all of them.
        $head = $this->controller("['title' => 'required']", "['slug' => 'required']");
        $base = $this->controller("['title' => 'required', 'subtitle' => 'nullable']", "['slug' => 'required']");

        $this->assertSame(
            ['App\\Http\\Controllers\\PostController::store' => [['subtitle'], []]],
            RequestFieldParser::inlineDiffFor('app/Http/Controllers/PostController.php', false, $head, $base),
        );
    }

    #[Test]
    public function it_recognises_the_validates_requests_trait_form(): void
    {
        // `$this->validate($request, [...])` puts the request first, so the rules are the SECOND
        // argument. Reading the first would enumerate nothing and silently report no change.
        $head = $this->validatesRequestsController("\$this->validate(\$request, ['title' => 'required']);");
        $base = $this->validatesRequestsController("\$this->validate(\$request, ['title' => 'required', 'subtitle' => 'nullable']);");

        $this->assertSame(
            ['App\\Http\\Controllers\\PostController::store' => [['subtitle'], []]],
            RequestFieldParser::inlineDiffFor('app/Http/Controllers/PostController.php', false, $head, $base),
        );
    }

    #[Test]
    public function a_method_whose_rules_it_cannot_read_is_skipped_rather_than_reported_empty(): void
    {
        // Rules built elsewhere and passed in cannot be enumerated. Absent means "cannot vouch";
        // an empty list would read as "every field was removed" and invent a finding per field.
        $head = "<?php\nnamespace App\\Http\\Controllers;\nclass PostController { public function store(\$request): void { \$request->validate(\$rules); } }\n";
        $base = "<?php\nnamespace App\\Http\\Controllers;\nclass PostController { public function store(\$request): void { \$request->validate(['title' => 'required']); } }\n";

        $this->assertSame([], RequestFieldParser::inlineDiffFor('app/Http/Controllers/PostController.php', false, $head, $base));
    }

    #[Test]
    public function a_form_request_path_is_left_to_the_rules_lane(): void
    {
        // Both lanes firing on one file would report the same removal twice.
        $head = "<?php\nnamespace App\\Http\\Requests;\nclass StorePostRequest { public function after(\$r): void { \$r->validate(['title' => 'required']); } }\n";
        $base = "<?php\nnamespace App\\Http\\Requests;\nclass StorePostRequest { public function after(\$r): void { \$r->validate(['title' => 'required', 'subtitle' => 'nullable']); } }\n";

        $this->assertSame([], RequestFieldParser::inlineDiffFor(self::PATH, false, $head, $base));
    }

    #[Test]
    public function emptying_the_rules_array_removes_every_field(): void
    {
        // `validate([])` enumerates successfully to zero fields. Treating that as "nothing to say"
        // rather than "everything went" missed the largest removal the lane can see.
        $head = "<?php\nnamespace App\\Http\\Controllers;\nclass PostController { public function store(\$request): void { \$request->validate([]); } }\n";
        $base = "<?php\nnamespace App\\Http\\Controllers;\nclass PostController { public function store(\$request): void { \$request->validate(['title' => 'required']); } }\n";

        $this->assertSame(
            ['App\\Http\\Controllers\\PostController::store' => [['title'], []]],
            RequestFieldParser::inlineDiffFor('app/Http/Controllers/PostController.php', false, $head, $base),
        );
    }

    #[Test]
    public function deleting_the_validate_call_outright_removes_every_field(): void
    {
        // The same removal written differently — the method survives, its validation does not.
        $head = "<?php\nnamespace App\\Http\\Controllers;\nclass PostController { public function store(\$request): void { \$request->save(); } }\n";
        $base = "<?php\nnamespace App\\Http\\Controllers;\nclass PostController { public function store(\$request): void { \$request->validate(['title' => 'required']); } }\n";

        $this->assertSame(
            ['App\\Http\\Controllers\\PostController::store' => [['title'], []]],
            RequestFieldParser::inlineDiffFor('app/Http/Controllers/PostController.php', false, $head, $base),
        );
    }

    #[Test]
    public function an_unrelated_method_named_validate_is_not_request_validation(): void
    {
        // `validate` is a common method name. Reading `$service->validate(['option' => …])` as request
        // fields would invent a frontend-parity finding out of somebody else's API.
        $head = "<?php\nnamespace App\\Http\\Controllers;\nclass PostController { public function store(\$service): void { \$service->validate(['option' => 'a']); } }\n";
        $base = "<?php\nnamespace App\\Http\\Controllers;\nclass PostController { public function store(\$service): void { \$service->validate(['option' => 'a', 'other' => 'b']); } }\n";

        $this->assertSame([], RequestFieldParser::inlineDiffFor('app/Http/Controllers/PostController.php', false, $head, $base));
    }

    #[Test]
    public function a_class_with_its_own_validate_method_is_not_read_as_request_validation(): void
    {
        // Without the trait, `$this->validate($value, [...])` may well be the class's own two-argument
        // method. Reading its options array as request rules would report a frontend-parity finding
        // about an unrelated API — a confident claim, and wrong.
        $head = "<?php\nnamespace App\\Services;\nclass Importer { public function validate(\$v, array \$o): void {} public function run(): void { \$this->validate(1, ['option' => 'a']); } }\n";
        $base = "<?php\nnamespace App\\Services;\nclass Importer { public function validate(\$v, array \$o): void {} public function run(): void { \$this->validate(1, ['option' => 'a', 'other' => 'b']); } }\n";

        $this->assertSame([], RequestFieldParser::inlineDiffFor('app/Services/Importer.php', false, $head, $base));
    }

    #[Test]
    public function named_arguments_resolve_the_rules_by_name_not_by_position(): void
    {
        // Reordered named arguments put the request where the rules would be. Reading by position
        // marks the method unenumerable and the removal goes unreported.
        $head = $this->validatesRequestsController("\$this->validate(rules: ['title' => 'required'], request: \$request);");
        $base = $this->validatesRequestsController("\$this->validate(rules: ['title' => 'required', 'subtitle' => 'nullable'], request: \$request);");

        $this->assertSame(
            ['App\\Http\\Controllers\\PostController::store' => [['subtitle'], []]],
            RequestFieldParser::inlineDiffFor('app/Http/Controllers/PostController.php', false, $head, $base),
        );
    }

    #[Test]
    public function an_anonymous_class_does_not_get_its_methods_keyed_under_the_file_class(): void
    {
        // Mapping every class-like in the file flat would key the anonymous `store()` under the
        // controller, anchoring a finding on `PostController::store` when that method validates
        // nothing — and colliding outright when both classes name a method the same.
        $head = "<?php\nnamespace App\\Http\\Controllers;\nclass PostController { public function build(): object { return new class { public function store(\$request): void { \$request->validate(['title' => 'required']); } }; } }\n";
        $base = "<?php\nnamespace App\\Http\\Controllers;\nclass PostController { public function build(): object { return new class { public function store(\$request): void { \$request->validate(['title' => 'required', 'subtitle' => 'nullable']); } }; } }\n";

        // The removal belongs to the method that builds the class, never to a `store` on the file's own.
        $this->assertSame(
            ['App\\Http\\Controllers\\PostController::build' => [['subtitle'], []]],
            RequestFieldParser::inlineDiffFor('app/Http/Controllers/PostController.php', false, $head, $base),
        );
    }

    #[Test]
    public function rules_that_become_unreadable_report_no_removal(): void
    {
        // The base is known and the head is not. Reporting the base's fields as removed would assert
        // something unknowable: `$rules` may well still contain every one of them.
        $head = "<?php\nnamespace App\\Http\\Controllers;\nclass PostController { public function store(\$request, \$rules): void { \$request->validate(\$rules); } }\n";
        $base = "<?php\nnamespace App\\Http\\Controllers;\nclass PostController { public function store(\$request, \$rules): void { \$request->validate(['title' => 'required']); } }\n";

        $this->assertSame([], RequestFieldParser::inlineDiffFor('app/Http/Controllers/PostController.php', false, $head, $base));
    }

    #[Test]
    public function two_classes_in_one_file_anchor_on_their_own(): void
    {
        // A bare method-name key would both anchor the secondary class's removal on the primary one
        // and let two same-named methods overwrite each other.
        $file = static fn (string $second): string => "<?php\nnamespace App\\Http\\Controllers;\n"
            . "class PostController { public function store(\$request): void { \$request->validate(['title' => 'required']); } }\n"
            . "class DraftController { public function store(\$request): void { \$request->validate({$second}); } }\n";

        $this->assertSame(
            ['App\\Http\\Controllers\\DraftController::store' => [['note'], []]],
            RequestFieldParser::inlineDiffFor(
                'app/Http/Controllers/PostController.php',
                false,
                $file("['slug' => 'required']"),
                $file("['slug' => 'required', 'note' => 'nullable']"),
            ),
        );
    }

    private function validatesRequestsController(string $body): string
    {
        return "<?php\nnamespace App\\Http\\Controllers;\nuse Illuminate\\Foundation\\Validation\\ValidatesRequests;\n"
            . "class PostController { use ValidatesRequests;\n    public function store(\$request): void { {$body} }\n}\n";
    }

    private function controller(string $storeRules, string $updateRules): string
    {
        return "<?php\nnamespace App\\Http\\Controllers;\nclass PostController {\n"
            . "    public function store(\$request): void { \$request->validate({$storeRules}); }\n"
            . "    public function update(\$request): void { \$request->validate({$updateRules}); }\n}\n";
    }
}
