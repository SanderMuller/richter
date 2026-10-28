<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Analysis\ValidationRules;
use SanderMuller\Richter\Tests\TestCase;

/**
 * The rules-hook forms `RulesDeclaration` reads, through the shared reader the lanes use.
 */
final class RulesDeclarationTest extends TestCase
{
    private const string IMPORTS = "use Illuminate\\Foundation\\Http\\FormRequest;\nuse SanderMuller\\FluentValidation\\FluentRule;\nuse SanderMuller\\FluentValidation\\FluentSchema;\nuse SanderMuller\\FluentValidation\\HasFluentRules;\nuse SanderMuller\\FluentValidation\\RuleSet;\n";

    /** A request class with the given body, extending FormRequest and using HasFluentRules unless told otherwise. */
    private function request(string $body, string $head = "final class StorePostRequest extends FormRequest\n{\n    use HasFluentRules;\n"): string
    {
        return "<?php\nnamespace App\\Http\\Requests;\n" . self::IMPORTS . $head . $body . "\n}\n";
    }

    private function rulesMethod(string $return, string $returnType = 'RuleSet'): string
    {
        return "    public function rules(): {$returnType}\n    {\n        return {$return};\n    }\n";
    }

    private function schemaMethod(string $return, string $parameter = 'FluentSchema $rules'): string
    {
        return "    public function schema({$parameter}): RuleSet\n    {\n        return {$return};\n    }\n";
    }

    /** @return array<string, list<string>|null>|null */
    private function fields(string $source, string $method = 'rules'): ?array
    {
        return ValidationRules::rulesByMember($source)["App\\Http\\Requests\\StorePostRequest::{$method}"] ?? null;
    }

    /** @return iterable<string, array{string, array<string, list<string>|null>|null}> */
    public static function ruleSetReturns(): iterable
    {
        yield 'from with a chain' => ["RuleSet::from(['title' => FluentRule::string()->max(255)])", ['title' => ['string', 'max']]];
        yield 'from with string rules' => ["RuleSet::from(['title' => 'required|max:255'])", ['title' => ['required', 'max']]];
        yield 'from with a variable' => ['RuleSet::from($rules)', null];
        yield 'from then only' => ["RuleSet::from(['title' => 'required'])->only('title')", null];
        yield 'from then merge' => ["RuleSet::from(['title' => 'required'])->merge(['body' => 'required'])", null];
        yield 'from with a spread' => ["RuleSet::from([...parent::rules(), 'title' => 'required'])", null];
        yield 'from with an unresolved key' => ["RuleSet::from([Other::TITLE => 'required'])", null];
        yield 'from with a named argument' => ["RuleSet::from(rules: ['title' => 'required'])", null];
        yield 'make with field' => ["RuleSet::make()->field('title', FluentRule::string()->required())", ['title' => ['string', 'required']]];
        yield 'make with put' => ["RuleSet::make()->put('title', 'required')", ['title' => ['required']]];
        yield 'make then stopOnFirstFailure' => ["RuleSet::make()->field('title', 'required')->stopOnFirstFailure()", null];
        yield 'make with named put' => ["RuleSet::make()->put(rule: 'required', field: 'title')", null];
        yield 'make with an unpacked argument' => ['RuleSet::make()->field(...$pair)', null];
        yield 'make with a repeated name' => ["RuleSet::make()->field('title', 'required')->field('title', 'nullable')", ['title' => ['nullable']]];
        yield 'define with an arrow function' => ["RuleSet::define(fn (FluentSchema \$r) => ['title' => \$r->string()->required()])", ['title' => ['string', 'required']]];
        yield 'define with a closure' => ["RuleSet::define(function (FluentSchema \$r) { return ['title' => \$r->string()]; })", ['title' => ['string']]];
        yield 'define with an untyped parameter' => ["RuleSet::define(fn (\$r) => ['title' => 'required'])", null];
        yield 'a RuleSet from another namespace' => ["\\App\\Support\\RuleSet::from(['title' => 'required'])", null];
    }

    /** @param  array<string, list<string>|null>|null  $expected */
    #[Test]
    #[DataProvider('ruleSetReturns')]
    public function it_reads_a_rule_set_return_whole_or_not_at_all(string $return, ?array $expected): void
    {
        $this->assertSame($expected, $this->fields($this->request($this->rulesMethod($return))));
    }

    #[Test]
    public function the_array_literal_rules_keep_their_lenient_reading(): void
    {
        // An array-literal rules() is lenient: an unresolved key is skipped, the rest still read.
        $source = $this->request($this->rulesMethod("['title' => 'required', Other::BODY => 'nullable']", 'array'));

        $this->assertSame(['title' => ['required']], $this->fields($source));
    }

    #[Test]
    public function a_typed_schema_method_is_read_with_its_variable_as_a_root(): void
    {
        $source = $this->request($this->schemaMethod("RuleSet::from(['q' => \$rules->string()->nullable()->max(255), 'page' => FluentRule::string()])"));

        $this->assertSame(['q' => ['string', 'nullable', 'max'], 'page' => ['string']], $this->fields($source, 'schema'));
    }

    #[Test]
    public function a_schema_array_literal_is_read_strictly(): void
    {
        $this->assertSame(['q' => ['string']], $this->fields($this->request($this->schemaMethod("['q' => \$rules->string()]")), 'schema'));
        $this->assertNull($this->fields($this->request($this->schemaMethod("['q' => \$rules->string(), Other::X => 'required']")), 'schema'));
    }

    #[Test]
    public function a_chain_on_another_variable_or_a_forwarded_macro_is_unknown(): void
    {
        $source = $this->request($this->schemaMethod("['a' => \$other->string()->required(), 'b' => \$rules->slug()]"));

        $this->assertSame(['a' => null, 'b' => null], $this->fields($source, 'schema'));
    }

    /** @return iterable<string, array{string, string, bool}> */
    public static function schemaHooks(): iterable
    {
        $uses = "    use HasFluentRules;\n";
        yield 'uses the trait' => ["final class StorePostRequest extends FormRequest\n{\n{$uses}", 'FluentSchema $rules', true];
        yield 'extends an application base request' => ["final class StorePostRequest extends BaseRequest\n{\n", 'FluentSchema $rules', true];
        yield 'uses an application trait' => ["final class StorePostRequest extends FormRequest\n{\n    use ValidatesFluently;\n", 'FluentSchema $rules', true];
        yield 'a plain FormRequest' => ["final class StorePostRequest extends FormRequest\n{\n", 'FluentSchema $rules', false];
        yield 'another parameter type' => ["final class StorePostRequest extends FormRequest\n{\n{$uses}", 'array $rules', false];
        yield 'no parameter' => ["final class StorePostRequest extends FormRequest\n{\n{$uses}", '', false];
    }

    #[Test]
    #[DataProvider('schemaHooks')]
    public function a_schema_method_is_read_only_when_the_package_can_be_calling_it(string $head, string $parameter, bool $read): void
    {
        $source = $this->request($this->schemaMethod("['title' => 'required']", $parameter), $head);

        $this->assertSame($read ? ['title' => ['required']] : null, $this->fields($source, 'schema'));
    }

    #[Test]
    public function self_and_final_static_constant_keys_resolve(): void
    {
        $self = $this->request("    public const string TITLE = 'title';\n" . $this->rulesMethod("RuleSet::from([self::TITLE => 'required'])"));
        $finalStatic = $this->request("    public const string TITLE = 'title';\n" . $this->rulesMethod("RuleSet::from([static::TITLE => 'required'])"));
        $finalConstant = $this->request("    final public const string TITLE = 'title';\n" . $this->rulesMethod("RuleSet::from([static::TITLE => 'required'])"), "class StorePostRequest extends FormRequest\n{\n    use HasFluentRules;\n");
        $field = $this->request("    public const string TITLE = 'title';\n" . $this->rulesMethod("RuleSet::make()->field(self::TITLE, 'required')"));

        foreach ([$self, $finalStatic, $finalConstant, $field] as $source) {
            $this->assertSame(['title' => ['required']], $this->fields($source));
        }
    }

    #[Test]
    public function an_overridable_or_unreadable_constant_key_does_not_resolve(): void
    {
        $open = "class StorePostRequest extends FormRequest\n{\n    use HasFluentRules;\n";

        $this->assertNull($this->fields($this->request("    public const string TITLE = 'title';\n" . $this->rulesMethod("RuleSet::from([static::TITLE => 'required'])"), $open)));
        $this->assertNull($this->fields($this->request("    public const string TITLE = 'ti' . 'tle';\n" . $this->rulesMethod("RuleSet::from([self::TITLE => 'required'])"))));
        $this->assertNull($this->fields($this->request($this->rulesMethod("RuleSet::from([parent::TITLE => 'required'])"))));
        $this->assertNull($this->fields($this->request($this->rulesMethod("RuleSet::from([self::MISSING => 'required'])"))));
    }

    #[Test]
    public function a_constant_key_resolves_in_an_array_literal_rules_too(): void
    {
        $source = $this->request("    public const string TITLE = 'title';\n" . $this->rulesMethod("[self::TITLE => 'required|max:255']", 'array'));

        $this->assertSame(['title' => ['required', 'max']], $this->fields($source));
    }

    #[Test]
    public function a_field_both_hooks_declare_goes_to_schema_when_the_body_uses_the_trait(): void
    {
        // Both hooks sit in a body that uses HasFluentRules: the package keeps schema().
        $source = $this->request($this->rulesMethod("['title' => 'required|max:255', 'body' => 'required']", 'array') . $this->schemaMethod("['title' => \$rules->string()->nullable(), 'slug' => \$rules->string()->required()]"));

        $this->assertSame(['title' => null, 'body' => ['required']], $this->fields($source));
        $this->assertSame(['title' => ['string', 'nullable'], 'slug' => ['string', 'required']], $this->fields($source, 'schema'));
    }

    #[Test]
    public function a_field_both_hooks_declare_reads_unknown_in_both_when_the_trait_is_out_of_view(): void
    {
        // The trait may come from the base request, or not at all, so neither hook is known to win.
        $source = $this->request($this->rulesMethod("['title' => 'required|max:255', 'body' => 'required']", 'array') . $this->schemaMethod("['title' => \$rules->string()->nullable(), 'slug' => \$rules->string()->required()]"), "final class StorePostRequest extends BaseRequest\n{\n");

        $this->assertSame(['title' => null, 'body' => ['required']], $this->fields($source));
        $this->assertSame(['title' => null, 'slug' => ['string', 'required']], $this->fields($source, 'schema'));
    }

    #[Test]
    public function an_unreadable_rules_makes_the_schema_unknown_when_the_trait_is_out_of_view(): void
    {
        $rules = "    public function rules(): array\n    {\n        \$fields = ['title' => 'nullable'];\n\n        return \$fields;\n    }\n";
        $source = $this->request($rules . $this->schemaMethod("['title' => \$rules->string()->required()]"), "final class StorePostRequest extends BaseRequest\n{\n");

        $this->assertSame(['title' => null], $this->fields($source, 'schema'));
    }

    #[Test]
    public function a_nullable_or_differently_cased_schema_is_still_a_hook(): void
    {
        foreach (['public function schema(?FluentSchema $rules): array', 'public function schema(FluentSchema|null $rules): array', 'public function Schema(FluentSchema $rules): array'] as $signature) {
            $schema = "    {$signature}\n    {\n        return ['title' => \$rules->string()];\n    }\n";
            $source = $this->request($this->rulesMethod("['title' => 'required|max:255']", 'array') . $schema, "final class StorePostRequest extends BaseRequest\n{\n");

            $this->assertSame(['title' => null], $this->fields($source), $signature);
        }
    }

    #[Test]
    public function a_function_closure_does_not_see_the_outer_schema_variable(): void
    {
        $source = $this->request($this->schemaMethod("RuleSet::define(function (FluentSchema \$r) { return ['title' => \$rules->string()]; })"));

        $this->assertSame(['title' => null], $this->fields($source, 'schema'));
    }

    #[Test]
    public function the_remaining_forms_and_hook_shapes_read_as_specified(): void
    {
        $this->assertNull($this->fields($this->request($this->rulesMethod('RuleSet::make()'))), 'make() alone is an empty declaration, filtered as no fields');
        $this->assertNull($this->fields($this->request($this->rulesMethod("RuleSet::define(function (FluentSchema \$r) { \$x = 1; return ['title' => 'required']; })"))));
        $this->assertNull($this->fields($this->request($this->schemaMethod("['title' => 'required']"), "final class StorePostRequest\n{\n"), 'schema'), 'no parent, no trait');
        $this->assertSame(['title' => ['required']], $this->fields($this->request($this->schemaMethod("['title' => 'required']"), "final class StorePostRequest extends FluentFormRequest\n{\n"), 'schema'));
        // The lenient array-literal path skips the keys it cannot resolve.
        $this->assertSame(['body' => ['required']], $this->fields($this->request("    public const string TITLE = 'ti' . 'tle';\n" . $this->rulesMethod("[parent::TITLE => 'required', self::MISSING => 'required', self::TITLE => 'required', 'body' => 'required']", 'array'))));
        // A constant key in an inline validate([...]) array resolves too.
        $inline = "    public const string TITLE = 'title';\n\n    public function store(\$request)\n    {\n        \$request->validate([self::TITLE => 'required']);\n    }\n";
        $this->assertSame(['title' => ['required']], $this->fields($this->request($inline), 'store'));
    }

    #[Test]
    public function an_unreadable_hook_makes_the_other_unknown(): void
    {
        // The unread schema() could override any rules() field.
        $schema = "    public function schema(FluentSchema \$rules): array\n    {\n        \$fields = ['title' => 'nullable'];\n\n        return \$fields;\n    }\n";
        $source = $this->request($this->rulesMethod("['title' => 'required', 'body' => 'required']", 'array') . $schema);

        $this->assertSame(['title' => null, 'body' => null], $this->fields($source));
    }

    #[Test]
    public function a_request_with_only_a_schema_requires_from_it(): void
    {
        $source = $this->request($this->schemaMethod("RuleSet::from(['title' => \$rules->string()->required(), 'body' => \$rules->string()->nullable()])"));

        $this->assertSame(['title' => true, 'body' => false], ValidationRules::requiredFields($source, 'schema', requestDataOnly: true));
    }
}
