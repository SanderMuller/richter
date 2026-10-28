<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use PhpParser\Node\Expr;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Namespace_;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
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
