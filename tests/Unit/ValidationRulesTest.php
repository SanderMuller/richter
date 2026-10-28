<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Analysis\ValidationRules;
use SanderMuller\Richter\Tests\TestCase;

final class ValidationRulesTest extends TestCase
{
    private function request(string $rules): string
    {
        return "<?php\nnamespace App\\Http\\Requests;\nuse Illuminate\\Foundation\\Http\\FormRequest;\nclass StorePostRequest extends FormRequest\n{\n    public function rules(): array\n    {\n        return [{$rules}];\n    }\n}\n";
    }

    /** @return iterable<string, array{string, bool|null}> */
    public static function classifications(): iterable
    {
        // A rule fails a missing field only when Laravel runs it on one: the implicit rules.
        yield 'required' => ["'required|string'", true];
        yield 'present' => ["'present'", true];
        yield 'accepted' => ["'accepted'", true];
        yield 'declined' => ["'declined'", true];
        // `nullable` exempts a null value, never an implicit rule on a missing field.
        yield 'required and nullable' => ["'required|nullable'", true];
        yield 'array form' => ["['required', 'max:255']", true];
        yield 'nullable only' => ["'nullable|string'", false];
        // `filled` is implicit but passes when the field is absent.
        yield 'filled' => ["'filled'", false];
        yield 'sometimes beside required' => ["'sometimes|required'", false];
        yield 'required_if' => ["'required_if:status,draft'", null];
        yield 'required_if_accepted' => ["'required_if_accepted:terms'", null];
        yield 'present_with' => ["'present_with:body'", null];
        yield 'exclude_if beside required' => ["'required|exclude_if:status,draft'", null];
        yield 'prohibited' => ["'prohibited'", null];
        yield 'rule object' => ["['required', Rule::in(['a', 'b'])]", null];
        yield 'fluent chain' => ['FluentRule::string()->required()', null];
    }

    #[Test]
    #[DataProvider('classifications')]
    public function it_classifies_whether_a_missing_field_fails(string $rules, ?bool $expected): void
    {
        $required = ValidationRules::requiredFields($this->request("'title' => {$rules}"), 'rules', requestDataOnly: true);

        $this->assertSame(['title' => $expected], $required);
    }

    #[Test]
    public function a_dotted_key_is_skipped(): void
    {
        $required = ValidationRules::requiredFields($this->request("'items' => 'required|array', 'items.*.name' => 'required'"), 'rules', requestDataOnly: true);

        $this->assertSame(['items' => true], $required);
    }

    #[Test]
    public function a_spread_item_is_skipped_and_the_literal_keys_still_read(): void
    {
        $required = ValidationRules::requiredFields($this->request("...parent::rules(), 'title' => 'required'"), 'rules', requestDataOnly: true);

        $this->assertSame(['title' => true], $required);
    }

    #[Test]
    public function inline_validation_is_read_from_the_named_method(): void
    {
        $source = "<?php\nnamespace App\\Http\\Controllers;\nclass PostController\n{\n    public function store(\$request)\n    {\n        \$request->validate(['title' => 'required']);\n    }\n\n    public function update(\$request)\n    {\n        \$request->validate(['body' => 'required']);\n    }\n}\n";

        $this->assertSame(['title' => true], ValidationRules::requiredFields($source, 'store', requestDataOnly: true));
    }

    #[Test]
    public function the_method_is_matched_by_name_in_a_parent_class_file(): void
    {
        $source = "<?php\nnamespace App\\Http\\Controllers;\nabstract class BasePostController\n{\n    public function store(\$request)\n    {\n        \$request->validate(['title' => 'required']);\n    }\n}\n";

        $this->assertSame(['title' => true], ValidationRules::requiredFields($source, 'store', requestDataOnly: true));
    }

    #[Test]
    public function a_method_name_two_classes_declare_reads_as_nothing(): void
    {
        $source = "<?php\nnamespace App\\Http\\Controllers;\nclass PostController\n{\n    public function store(\$request)\n    {\n        \$request->validate(['title' => 'required']);\n    }\n}\nclass CommentController\n{\n    public function store(\$request)\n    {\n        \$request->validate(['body' => 'required']);\n    }\n}\n";

        $this->assertSame([], ValidationRules::requiredFields($source, 'store', requestDataOnly: true));
    }

    #[Test]
    public function validator_make_is_left_out_when_only_request_data_counts(): void
    {
        $source = "<?php\nnamespace App\\Http\\Controllers;\nuse Illuminate\\Support\\Facades\\Validator;\nclass PostController\n{\n    public function store(\$request)\n    {\n        Validator::make(\$payload, ['title' => 'required'])->validate();\n    }\n}\n";

        $this->assertSame([], ValidationRules::requiredFields($source, 'store', requestDataOnly: true));
        $this->assertSame(['title' => true], ValidationRules::requiredFields($source, 'store', requestDataOnly: false));
    }
}
