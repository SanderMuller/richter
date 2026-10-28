<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Analysis\ValidationRules;
use SanderMuller\Richter\Tests\TestCase;

/**
 * Migration parity: the rector rewrites string rules into chains, often a whole request in one diff.
 * Each pair below is a conversion its tables produce (`ConvertsValidationRuleStrings` in
 * `laravel-fluent-validation-rector`), copied here so richter takes no dependency on it. The chain must
 * keep every guarding rule of its string form and the same required verdict, or the boundary lane
 * raises a false hazard on the migration diff.
 */
final class FluentRuleParityTest extends TestCase
{
    /** The boundary lane's guarding rules (`BoundaryHazardLane::GUARDING_RULES`). */
    private const array GUARDING = ['required', 'confirmed', 'max', 'min', 'size', 'in', 'exists', 'unique', 'mimes', 'image', 'url', 'email', 'uuid'];

    /** @return iterable<string, array{string, string}> */
    public static function conversions(): iterable
    {
        // TYPE_MAP, each behind `required` so the verdict is a real comparison.
        foreach ([
            'email' => 'email', 'string' => 'string', 'numeric' => 'numeric', 'integer' => 'integer',
            'boolean' => 'boolean', 'bool' => 'boolean', 'int' => 'integer', 'array' => 'array', 'date' => 'date',
            'file' => 'file', 'image' => 'image', 'url' => 'url', 'uuid' => 'uuid', 'ulid' => 'ulid', 'ip' => 'ip',
            'ipv4' => 'ipv4', 'ipv6' => 'ipv6', 'mac_address' => 'macAddress', 'json' => 'json',
            'timezone' => 'timezone', 'hex_color' => 'hexColor', 'active_url' => 'activeUrl', 'list' => 'list',
        ] as $rule => $factory) {
            yield "type {$rule}" => ["required|{$rule}", "FluentRule::{$factory}()->required()"];
        }

        yield 'type declined' => ['required|declined', 'FluentRule::declined()->required()'];
        yield 'type accepted' => ['required|accepted', 'FluentRule::accepted()->required()'];

        // SIMPLE_MODIFIERS
        foreach ([
            'nullable' => 'nullable', 'sometimes' => 'sometimes', 'filled' => 'filled', 'present' => 'present',
            'missing' => 'missing', 'prohibited' => 'prohibited', 'confirmed' => 'confirmed', 'bail' => 'bail',
            'distinct' => 'distinct', 'lowercase' => 'lowercase', 'uppercase' => 'uppercase', 'json' => 'json',
            'timezone' => 'timezone', 'exclude' => 'exclude', 'alpha' => 'alpha', 'alpha_dash' => 'alphaDash',
            'alpha_num' => 'alphaNumeric', 'ascii' => 'ascii', 'active_url' => 'activeUrl', 'ipv4' => 'ipv4',
            'ipv6' => 'ipv6', 'mac_address' => 'macAddress', 'hex_color' => 'hexColor',
            'current_password' => 'currentPassword',
        ] as $rule => $method) {
            yield "modifier {$rule}" => ["required|string|{$rule}", "FluentRule::string()->required()->{$method}()"];
        }

        yield 'modifier list' => ['required|array|list', 'FluentRule::array()->required()->list()'];

        // NUMERIC_ARG_RULES and TWO_NUMERIC_ARG_RULES
        yield 'min' => ['required|string|min:2', 'FluentRule::string()->required()->min(2)'];
        yield 'max' => ['required|string|max:255', 'FluentRule::string()->required()->max(255)'];
        yield 'size' => ['required|string|size:5', 'FluentRule::string()->required()->exactly(5)'];
        yield 'numeric size' => ['required|integer|size:5', 'FluentRule::integer()->required()->exactly(5)'];
        yield 'digits' => ['required|numeric|digits:4', 'FluentRule::numeric()->required()->digits(4)'];
        yield 'multiple_of' => ['required|numeric|multiple_of:5', 'FluentRule::numeric()->required()->multipleOf(5)'];
        yield 'between' => ['required|string|between:2,10', 'FluentRule::string()->required()->between(2, 10)'];
        yield 'digits_between' => ['required|numeric|digits_between:2,4', 'FluentRule::numeric()->required()->digitsBetween(2, 4)'];
        yield 'file max' => ['required|file|max:2048|mimes:pdf', "FluentRule::file()->required()->max(2048)->mimes('pdf')"];

        // STRING_ARG_RULES
        foreach (['same' => 'same', 'different' => 'different', 'in_array' => 'inArray'] as $rule => $method) {
            yield "string arg {$rule}" => ["required|string|{$rule}:other", "FluentRule::string()->required()->{$method}('other')"];
        }

        foreach (['after' => 'after', 'before' => 'before', 'after_or_equal' => 'afterOrEqual', 'before_or_equal' => 'beforeOrEqual', 'date_equals' => 'dateEquals'] as $rule => $method) {
            yield "date arg {$rule}" => ["required|date|{$rule}:today", "FluentRule::date()->required()->{$method}('today')"];
        }

        foreach (['gt' => 'greaterThan', 'lt' => 'lessThan', 'gte' => 'greaterThanOrEqualTo', 'lte' => 'lessThanOrEqualTo'] as $rule => $method) {
            yield "numeric arg {$rule}" => ["required|numeric|{$rule}:other", "FluentRule::numeric()->required()->{$method}('other')"];
        }

        // COMMA_SEPARATED_ARGS_RULES
        foreach ([
            'required_if' => 'requiredIf', 'required_unless' => 'requiredUnless', 'required_with' => 'requiredWith',
            'required_with_all' => 'requiredWithAll', 'required_without' => 'requiredWithout',
            'required_without_all' => 'requiredWithoutAll', 'exclude_if' => 'excludeIf', 'exclude_unless' => 'excludeUnless',
            'prohibited_if' => 'prohibitedIf', 'prohibited_unless' => 'prohibitedUnless', 'prohibits' => 'prohibits',
            'missing_if' => 'missingIf', 'missing_unless' => 'missingUnless', 'missing_with' => 'missingWith',
            'missing_with_all' => 'missingWithAll', 'present_if' => 'presentIf', 'present_unless' => 'presentUnless',
            'present_with' => 'presentWith', 'present_with_all' => 'presentWithAll',
        ] as $rule => $method) {
            yield "conditional {$rule}" => ["string|{$rule}:status,draft", "FluentRule::string()->{$method}('status', 'draft')"];
        }

        foreach (['required_if_accepted' => 'requiredIfAccepted', 'required_if_declined' => 'requiredIfDeclined', 'exclude_with' => 'excludeWith', 'exclude_without' => 'excludeWithout', 'prohibited_if_accepted' => 'prohibitedIfAccepted', 'prohibited_if_declined' => 'prohibitedIfDeclined'] as $rule => $method) {
            yield "conditional {$rule}" => ["string|{$rule}:terms", "FluentRule::string()->{$method}('terms')"];
        }

        // TYPE_PROMOTING_MODIFIERS
        yield 'promoted ipv4' => ['required|string|ipv4', 'FluentRule::ipv4()->required()'];
        yield 'promoted list' => ['required|array|list', 'FluentRule::list()->required()'];

        // SINGLE_STRING_ARG_RULES
        foreach (['regex' => 'regex', 'not_regex' => 'notRegex', 'starts_with' => 'startsWith', 'ends_with' => 'endsWith', 'doesnt_start_with' => 'doesntStartWith', 'doesnt_end_with' => 'doesntEndWith'] as $rule => $method) {
            yield "single string arg {$rule}" => ["required|string|{$rule}:x", "FluentRule::string()->required()->{$method}('x')"];
        }

        yield 'date_format' => ['required|date|date_format:Y-m-d', "FluentRule::date()->required()->format('Y-m-d')"];

        // The dedicated in / not_in / exists / unique branches
        yield 'in' => ['required|string|in:a,b', "FluentRule::string()->required()->in(['a', 'b'])"];
        yield 'not_in' => ['required|string|not_in:a,b', "FluentRule::string()->required()->notIn(['a', 'b'])"];
        yield 'exists' => ['required|integer|exists:users,id', "FluentRule::integer()->required()->exists('users', 'id')"];
        yield 'unique' => ['required|string|unique:posts,slug', "FluentRule::string()->required()->unique('posts', 'slug')"];
    }

    #[Test]
    #[DataProvider('conversions')]
    public function a_converted_chain_keeps_the_guarding_rules_and_the_verdict(string $string, string $chain): void
    {
        $source = $this->request("'field' => '{$string}'", "'field' => {$chain}");
        $base = ValidationRules::rulesByMember($source[0])['App\Http\Requests\StorePostRequest::rules']['field'] ?? null;
        $head = ValidationRules::rulesByMember($source[1])['App\Http\Requests\StorePostRequest::rules']['field'] ?? null;

        $this->assertIsArray($base);
        $this->assertIsArray($head, "the chain reads null: {$chain}");
        $this->assertSame([], array_values(array_diff(array_intersect($base, self::GUARDING), $head)), "guarding rule lost: {$chain}");
        $this->assertSame(
            ValidationRules::requiredFields($source[0], 'rules', requestDataOnly: true),
            ValidationRules::requiredFields($source[1], 'rules', requestDataOnly: true),
            "required verdict changed: {$chain}",
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function deliberateNulls(): iterable
    {
        // An object builder, and the accepted/declined shapes whose required-ness differs per mode.
        yield 'password' => ['required|string|min:8|confirmed', 'FluentRule::password(8)->required()->confirmed()'];
        yield 'bare declined' => ['declined', 'FluentRule::declined()'];
        yield 'bare accepted' => ['accepted', 'FluentRule::accepted()'];
        yield 'accepted through the rule escape hatch' => ['accepted', "FluentRule::field()->rule('accepted')"];
        yield 'boolean accepted' => ['boolean|accepted', 'FluentRule::boolean()->accepted()'];
    }

    #[Test]
    #[DataProvider('deliberateNulls')]
    public function a_conversion_the_reader_cannot_vouch_for_reads_null(string $string, string $chain): void
    {
        $source = $this->request("'field' => '{$string}'", "'field' => {$chain}");

        $this->assertIsArray(ValidationRules::rulesByMember($source[0])['App\Http\Requests\StorePostRequest::rules']['field'] ?? null);
        $fields = ValidationRules::rulesByMember($source[1])['App\Http\Requests\StorePostRequest::rules'] ?? [];

        $this->assertArrayHasKey('field', $fields);
        $this->assertNull($fields['field']);
    }

    /** @return array{string, string} the string-rule request and its converted twin */
    private function request(string $before, string $after): array
    {
        $wrap = static fn (string $rules): string => "<?php\nnamespace App\\Http\\Requests;\nuse SanderMuller\\FluentValidation\\FluentRule;\nclass StorePostRequest\n{\n    public function rules(): array\n    {\n        return [{$rules}];\n    }\n}\n";

        return [$wrap($before), $wrap($after)];
    }
}
