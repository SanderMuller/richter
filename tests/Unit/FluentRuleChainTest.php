<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use PhpParser\Node\Expr;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Namespace_;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClassConstant;
use SanderMuller\Richter\Analysis\FluentRuleChain;
use SanderMuller\Richter\Support\AppFiles;
use SanderMuller\Richter\Tests\TestCase;

final class FluentRuleChainTest extends TestCase
{
    /** The expression, parsed with names resolved, as the rules reader sees it. */
    private function expression(string $code, string $import = 'use SanderMuller\FluentValidation\FluentRule;'): Expr
    {
        $ast = AppFiles::parseResolved("<?php\nnamespace App\\Http\\Requests;\n{$import}\n{$code};\n");
        $this->assertIsArray($ast);
        $namespace = $ast[0] ?? null;
        $this->assertInstanceOf(Namespace_::class, $namespace);
        $statement = end($namespace->stmts);
        $this->assertInstanceOf(Expression::class, $statement);

        return $statement->expr;
    }

    /** @return list<string>|null */
    private function names(string $code): ?array
    {
        $names = FluentRuleChain::ruleNames($this->expression($code));

        if ($names !== null) {
            sort($names);
        }

        return $names;
    }

    /** @return iterable<string, array{string, list<string>|null}> */
    public static function chains(): iterable
    {
        yield 'string with constraints' => ['FluentRule::string()->required()->max(255)', ['max', 'required', 'string']];
        yield 'presence written last' => ['FluentRule::string()->max(255)->required()', ['max', 'required', 'string']];
        yield 'integer' => ['FluentRule::integer()', ['integer', 'numeric']];
        yield 'email' => ['FluentRule::email()->required()', ['email', 'required', 'string']];
        yield 'email modes add nothing' => ['FluentRule::email()->strict()->validateMxRecord()', ['email', 'string']];
        yield 'exactly on a string is size' => ['FluentRule::string()->exactly(5)', ['size', 'string']];
        yield 'exactly on a number adds integer' => ['FluentRule::numeric()->exactly(5)', ['integer', 'numeric', 'size']];
        yield 'alphaNumeric is alpha_num' => ['FluentRule::string()->alphaNumeric()', ['alpha_num', 'string']];
        yield 'positive is gt' => ['FluentRule::numeric()->positive()', ['gt', 'numeric']];
        yield 'between on a date is after and before' => ["FluentRule::date()->between('2020-01-01', '2030-01-01')", ['after', 'before', 'date']];
        yield 'date format replaces date' => ["FluentRule::date()->format('Y-m-d')", ['date_format']];
        yield 'dateTime' => ['FluentRule::dateTime()', ['date_format']];
        yield 'file' => ["FluentRule::file()->max('2mb')->mimes('pdf')", ['file', 'max', 'mimes']];
        yield 'image' => ['FluentRule::image()->max(512)->allowSvg()', ['image', 'max']];
        yield 'array with nested keys' => ['FluentRule::array()->required()->each(FluentRule::string())', ['array', 'required']];
        yield 'field' => ["FluentRule::field()->required()->in(['a', 'b'])", ['in', 'required']];
        yield 'embedded rules' => ["FluentRule::string()->unique('posts')->exists('users', 'id')", ['exists', 'string', 'unique']];
        yield 'labels and messages add nothing' => ["FluentRule::string()->label('Title')->message('No')->required()", ['required', 'string']];
        yield 'password is an object' => ['FluentRule::password()', null];
        yield 'enum is an object' => ['FluentRule::enum(Status::class)', null];
        yield 'an unlisted method' => ['FluentRule::string()->someMacro()', null];
        yield 'a macro factory' => ['FluentRule::slug()', null];
        yield 'when' => ['FluentRule::string()->when($flag, fn ($rule) => $rule->required())', null];
        yield 'whenInput' => ["FluentRule::string()->whenInput(fn () => true, 'required')", null];
        // ->rule()
        yield 'a rule token' => ["FluentRule::file()->rule('mimes:pdf')", ['file', 'mimes']];
        yield 'a rule tuple' => ["FluentRule::file()->rule(['mimes', 'pdf'])", ['file', 'mimes']];
        yield 'a rule object' => ['FluentRule::string()->rule(new Uppercase())', null];
        yield 'a piped rule token' => ["FluentRule::string()->rule('regex:/a|b/')", null];
        yield 'a piped rule token on email' => ["FluentRule::email()->rule('regex:/a|b/')", null];
        yield 'a piped rule tuple on email' => ["FluentRule::email()->rule(['required|max:10'])", null];
        yield 'presence through rule' => ["FluentRule::string()->rule('Required')", null];
        yield 'parameterised presence through rule' => ["FluentRule::string()->rule('required:x')", null];
        yield 'present through rule' => ["FluentRule::string()->rule('present')", null];
        // Presence by argument
        yield 'requiredIf a field' => ["FluentRule::string()->requiredIf('status', 'draft')", ['required_if', 'string']];
        yield 'requiredIf a closure' => ['FluentRule::string()->requiredIf(fn () => true)', null];
        yield 'requiredIf a variable' => ['FluentRule::string()->requiredIf($flag)', null];
        yield 'requiredIf true' => ['FluentRule::string()->requiredIf(true)', ['required', 'string']];
        yield 'requiredIf false' => ['FluentRule::string()->requiredIf(false)', ['string']];
        yield 'requiredUnless false' => ['FluentRule::string()->requiredUnless(false)', ['required', 'string']];
        yield 'excludeIf true' => ['FluentRule::string()->excludeIf(true)', null];
        // accepted / declined across execution modes
        yield 'bare accepted' => ['FluentRule::accepted()', null];
        yield 'accepted nullable' => ['FluentRule::accepted()->nullable()', null];
        yield 'accepted required' => ['FluentRule::accepted()->required()', ['accepted', 'required']];
        yield 'accepted present' => ['FluentRule::accepted()->present()', ['accepted', 'present']];
        yield 'acceptedIf replaces accepted' => ["FluentRule::accepted()->required()->acceptedIf('role', 'admin')", ['accepted_if', 'required']];
        yield 'boolean accepted required' => ['FluentRule::boolean()->accepted()->required()', ['accepted', 'boolean', 'required']];
        yield 'bare declined' => ['FluentRule::declined()', null];
        yield 'declinedIf replaces declined' => ["FluentRule::declined()->required()->declinedIf('role', 'admin')", ['declined_if', 'required']];
        yield 'boolean accepted without presence' => ['FluentRule::boolean()->accepted()', null];
        // Arguments read by position are refused when named or unpacked.
        yield 'a named rule argument' => ["FluentRule::string()->rule(message: 'x', rule: 'max:10')", null];
        yield 'a named requiredIf argument' => ["FluentRule::string()->requiredIf(values: 'x', field: true)", null];
        yield 'an unpacked rule argument' => ['FluentRule::string()->rule(...$rules)', null];
        yield 'a named message elsewhere is fine' => ["FluentRule::string()->max(255, message: 'Too long')", ['max', 'string']];
        // Methods a builder does not have run only as a macro.
        yield 'an embedded rule on a file' => ["FluentRule::file()->unique('posts')", null];
        yield 'a comparison on a boolean' => ["FluentRule::boolean()->same('other')", null];
        // A replacing method removes the seeded rule only.
        yield 'format keeps a rule-added date' => ["FluentRule::date()->rule('date')->format('Y-m-d')", ['date', 'date_format']];
        yield 'a first-class callable root' => ['FluentRule::string(...)', null];
        yield 'requiredUnless true' => ['FluentRule::string()->requiredUnless(true)', ['string']];
        yield 'excludeUnless a literal bool' => ['FluentRule::string()->excludeUnless(false)', null];
        yield 'prohibitedIf a literal bool' => ['FluentRule::string()->prohibitedIf(true)', null];
        yield 'prohibitedUnless a literal bool' => ['FluentRule::string()->prohibitedUnless(true)', null];
        yield 'excludeIf a field' => ["FluentRule::string()->excludeIf('status', 'draft')", ['exclude_if', 'string']];
    }

    /**
     * Every table entry, written out a second time from the package's builders, so a wrong or new
     * entry in `FluentRuleChain` fails here. Family => [factory the row uses, method => rules added].
     *
     * @var array<string, array{string, array<string, list<string>>}>
     */
    private const array EXPECTED_METHODS = [
        '*' => ['string', [
            'label' => [], 'message' => [], 'messageFor' => [], 'fieldMessage' => [], 'bail' => ['bail'],
            'nullable' => ['nullable'], 'required' => ['required'], 'sometimes' => ['sometimes'], 'filled' => ['filled'],
            'present' => ['present'], 'prohibited' => ['prohibited'], 'exclude' => ['exclude'], 'missing' => ['missing'],
            'presentIf' => ['present_if'], 'presentUnless' => ['present_unless'], 'presentWith' => ['present_with'],
            'presentWithAll' => ['present_with_all'], 'missingIf' => ['missing_if'], 'missingUnless' => ['missing_unless'],
            'missingWith' => ['missing_with'], 'missingWithAll' => ['missing_with_all'], 'requiredWith' => ['required_with'],
            'requiredWithAll' => ['required_with_all'], 'requiredWithout' => ['required_without'],
            'requiredWithoutAll' => ['required_without_all'], 'requiredIfAccepted' => ['required_if_accepted'],
            'requiredIfDeclined' => ['required_if_declined'], 'excludeWith' => ['exclude_with'],
            'excludeWithout' => ['exclude_without'], 'prohibits' => ['prohibits'],
            'prohibitedIfAccepted' => ['prohibited_if_accepted'], 'prohibitedIfDeclined' => ['prohibited_if_declined'],
        ]],
        'string' => ['string', [
            'unique' => ['unique'], 'exists' => ['exists'], 'enum' => ['enum'], 'in' => ['in'], 'notIn' => ['not_in'],
            'same' => ['same'], 'different' => ['different'], 'confirmed' => ['confirmed'], 'inArray' => ['in_array'],
            'distinct' => ['distinct'], 'inArrayKeys' => ['in_array_keys'], 'alpha' => ['alpha'], 'alphaDash' => ['alpha_dash'],
            'alphaNumeric' => ['alpha_num'], 'ascii' => ['ascii'], 'encoding' => ['encoding'], 'between' => ['between'],
            'doesntEndWith' => ['doesnt_end_with'], 'doesntStartWith' => ['doesnt_start_with'], 'endsWith' => ['ends_with'],
            'exactly' => ['size'], 'lowercase' => ['lowercase'], 'max' => ['max'], 'min' => ['min'],
            'startsWith' => ['starts_with'], 'uppercase' => ['uppercase'], 'url' => ['url'], 'httpUrl' => ['url'],
            'activeUrl' => ['active_url'], 'uuid' => ['uuid'], 'ulid' => ['ulid'], 'json' => ['json'], 'ip' => ['ip'],
            'ipv4' => ['ipv4'], 'ipv6' => ['ipv6'], 'macAddress' => ['mac_address'], 'regex' => ['regex'],
            'notRegex' => ['not_regex'], 'timezone' => ['timezone'], 'hexColor' => ['hex_color'], 'date' => ['date'],
            'email' => ['email'], 'dateFormat' => ['date_format'], 'currentPassword' => ['current_password'],
        ]],
        'numeric' => ['numeric', [
            'unique' => ['unique'], 'exists' => ['exists'], 'enum' => ['enum'], 'in' => ['in'], 'notIn' => ['not_in'],
            'same' => ['same'], 'different' => ['different'], 'confirmed' => ['confirmed'], 'inArray' => ['in_array'],
            'distinct' => ['distinct'], 'inArrayKeys' => ['in_array_keys'], 'between' => ['between'], 'decimal' => ['decimal'],
            'digits' => ['integer', 'digits'], 'digitsBetween' => ['integer', 'digits_between'], 'greaterThan' => ['gt'],
            'greaterThanOrEqualTo' => ['gte'], 'integer' => ['integer'], 'lessThan' => ['lt'], 'lessThanOrEqualTo' => ['lte'],
            'max' => ['max'], 'maxDigits' => ['max_digits'], 'min' => ['min'], 'minDigits' => ['min_digits'],
            'multipleOf' => ['multiple_of'], 'positive' => ['gt'], 'negative' => ['lt'], 'nonNegative' => ['gte'],
            'nonPositive' => ['lte'], 'exactly' => ['integer', 'size'],
        ]],
        'date' => ['date', [
            'unique' => ['unique'], 'exists' => ['exists'], 'enum' => ['enum'], 'in' => ['in'], 'notIn' => ['not_in'],
            'same' => ['same'], 'different' => ['different'], 'confirmed' => ['confirmed'], 'inArray' => ['in_array'],
            'distinct' => ['distinct'], 'beforeToday' => ['before'], 'afterToday' => ['after'],
            'todayOrBefore' => ['before_or_equal'], 'todayOrAfter' => ['after_or_equal'], 'past' => ['before'],
            'future' => ['after'], 'nowOrPast' => ['before_or_equal'], 'nowOrFuture' => ['after_or_equal'],
            'before' => ['before'], 'after' => ['after'], 'beforeOrEqual' => ['before_or_equal'],
            'afterOrEqual' => ['after_or_equal'], 'between' => ['after', 'before'],
            'betweenOrEqual' => ['after_or_equal', 'before_or_equal'], 'dateEquals' => ['date_equals'],
        ]],
        'boolean' => ['boolean', [
            'accepted' => ['accepted'], 'acceptedIf' => ['accepted_if'], 'declined' => ['declined'], 'declinedIf' => ['declined_if'],
        ]],
        'array' => ['array', [
            'min' => ['min'], 'max' => ['max'], 'between' => ['between'], 'exactly' => ['size'], 'list' => ['list'],
            'requiredArrayKeys' => ['required_array_keys'], 'contains' => ['contains'], 'doesntContain' => ['doesnt_contain'],
            'each' => [], 'children' => [], 'inArrayKeys' => ['in_array_keys'], 'distinct' => ['distinct'],
        ]],
        'file' => ['file', [
            'min' => ['min'], 'max' => ['max'], 'between' => ['between'], 'exactly' => ['size'],
            'extensions' => ['extensions'], 'mimes' => ['mimes'], 'mimetypes' => ['mimetypes'],
        ]],
        'image' => ['image', [
            'min' => ['min'], 'max' => ['max'], 'between' => ['between'], 'exactly' => ['size'],
            'extensions' => ['extensions'], 'mimes' => ['mimes'], 'mimetypes' => ['mimetypes'], 'allowSvg' => [],
        ]],
        'email' => ['email', [
            'unique' => ['unique'], 'exists' => ['exists'], 'enum' => ['enum'], 'in' => ['in'], 'notIn' => ['not_in'],
            'same' => ['same'], 'different' => ['different'], 'confirmed' => ['confirmed'], 'inArray' => ['in_array'],
            'distinct' => ['distinct'], 'rfcCompliant' => [], 'strict' => [], 'validateMxRecord' => [],
            'preventSpoofing' => [], 'withNativeValidation' => [], 'max' => ['max'],
        ]],
        'field' => ['field', [
            'unique' => ['unique'], 'exists' => ['exists'], 'enum' => ['enum'], 'in' => ['in'], 'notIn' => ['not_in'],
            'same' => ['same'], 'different' => ['different'], 'confirmed' => ['confirmed'], 'inArray' => ['in_array'],
            'distinct' => ['distinct'], 'inArrayKeys' => ['in_array_keys'], 'children' => [],
        ]],
    ];

    /** Factory => the rules it seeds, written out a second time from `FluentRule`. */
    private const array EXPECTED_FACTORIES = [
        'string' => ['string'], 'numeric' => ['numeric'], 'integer' => ['numeric', 'integer'], 'date' => ['date'],
        'dateTime' => ['date_format'], 'boolean' => ['boolean'], 'accepted' => ['accepted'], 'declined' => ['declined'],
        'array' => ['array'], 'list' => ['array', 'list'], 'file' => ['file'], 'image' => ['image'],
        'email' => ['string', 'email'], 'url' => ['string', 'url'], 'httpUrl' => ['string', 'url'],
        'uuid' => ['string', 'uuid'], 'ulid' => ['string', 'ulid'], 'ip' => ['string', 'ip'], 'ipv4' => ['string', 'ipv4'],
        'ipv6' => ['string', 'ipv6'], 'macAddress' => ['string', 'mac_address'], 'json' => ['string', 'json'],
        'timezone' => ['string', 'timezone'], 'hexColor' => ['string', 'hex_color'],
        'activeUrl' => ['string', 'active_url'], 'regex' => ['string', 'regex'], 'field' => [],
    ];

    /** @return iterable<string, array{string, list<string>}> */
    public static function methodEntries(): iterable
    {
        foreach (self::EXPECTED_METHODS as $family => [$factory, $methods]) {
            $base = self::EXPECTED_FACTORIES[$factory];

            foreach ($methods as $method => $added) {
                // `required()` keeps presence certain, so the accepted/declined families read too.
                yield "{$family}::{$method}" => ["FluentRule::{$factory}()->required()->{$method}()", [...$base, 'required', ...$added]];
            }
        }
    }

    /** @param  list<string>  $expected */
    #[Test]
    #[DataProvider('methodEntries')]
    public function every_method_entry_adds_the_rules_its_builder_adds(string $code, array $expected): void
    {
        sort($expected);

        $this->assertSame($expected, $this->names($code));
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function factoryEntries(): iterable
    {
        foreach (self::EXPECTED_FACTORIES as $factory => $seeded) {
            yield $factory => ["FluentRule::{$factory}()->required()", [...$seeded, 'required']];
        }
    }

    /** @param  list<string>  $expected */
    #[Test]
    #[DataProvider('factoryEntries')]
    public function every_factory_seeds_the_rules_its_builder_seeds(string $code, array $expected): void
    {
        sort($expected);

        $this->assertSame($expected, $this->names($code));
    }

    #[Test]
    public function every_table_entry_has_a_row(): void
    {
        // A new entry in FluentRuleChain without a row here fails, so no entry ships unchecked.
        $methods = new ReflectionClassConstant(FluentRuleChain::class, 'METHODS')->getValue();
        $factories = new ReflectionClassConstant(FluentRuleChain::class, 'FACTORIES')->getValue();
        $this->assertIsArray($methods);
        $this->assertIsArray($factories);

        $this->assertSame(
            array_map(static fn (array $row): array => array_keys($row[1]), self::EXPECTED_METHODS),
            array_map(static fn (mixed $row): array => is_array($row) ? array_keys($row) : [], $methods),
        );
        $this->assertSame(array_keys(self::EXPECTED_FACTORIES), array_keys($factories));
    }

    /** @param  list<string>|null  $expected */
    #[Test]
    #[DataProvider('chains')]
    public function it_reads_the_rules_a_chain_compiles_to(string $code, ?array $expected): void
    {
        $this->assertSame($expected, $this->names($code));
    }

    #[Test]
    public function an_aliased_import_still_matches(): void
    {
        $names = FluentRuleChain::ruleNames($this->expression('Fluent::string()->required()', 'use SanderMuller\FluentValidation\FluentRule as Fluent;'));

        $this->assertSame(['string', 'required'], $names);
    }

    #[Test]
    public function a_same_named_class_from_another_namespace_is_not_a_chain(): void
    {
        $this->assertNull(FluentRuleChain::ruleNames($this->expression('FluentRule::string()->required()', 'use App\Rules\FluentRule;')));
        $this->assertNull(FluentRuleChain::ruleNames($this->expression("Rule::in(['a'])", 'use Illuminate\Validation\Rule;')));
    }
}
