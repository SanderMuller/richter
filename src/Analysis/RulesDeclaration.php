<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Param;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\Node\UnionType;
use SanderMuller\Richter\Analysis\Hazards\HazardSource;
use SanderMuller\Richter\Support\AppFiles;

/**
 * How one class declares its validation rules: which methods are the rules hook, the field items
 * each hook returns, and the string constants its keys may name.
 *
 * Reads the `laravel-fluent-validation` forms besides a plain array literal: `RuleSet::from([...])`,
 * `RuleSet::define(fn (FluentSchema $r) => [...])`, `RuleSet::make()->field()/put()`, and a
 * `schema(FluentSchema $rules)` method. Each `RuleSet` or `schema()` form is read whole or not at all —
 * a field the reader skipped could be the one that overrides another, so a partly read form reads as
 * unreadable. An array-literal `rules()` is the exception: it skips an unresolved key and reads the rest.
 *
 * @internal
 */
final readonly class RulesDeclaration
{
    private const string RULE_SET = 'SanderMuller\FluentValidation\RuleSet';

    private const string FLUENT_SCHEMA = 'SanderMuller\FluentValidation\FluentSchema';

    private const string FORM_REQUEST = 'Illuminate\Foundation\Http\FormRequest';

    private const string HAS_FLUENT_RULES = 'SanderMuller\FluentValidation\HasFluentRules';

    /** @param  array<string, array{string, bool}>  $constants  name => [string value, declared final] */
    private function __construct(private ClassLike $class, private array $constants) {}

    public static function of(ClassLike $class): self
    {
        $constants = [];

        foreach ($class->stmts as $statement) {
            if (! $statement instanceof ClassConst) {
                continue;
            }

            foreach ($statement->consts as $const) {
                if ($const->value instanceof String_) {
                    $constants[$const->name->toString()] = [$const->value->value, $statement->isFinal()];
                }
            }
        }

        return new self($class, $constants);
    }

    /**
     * Whether a method is the class's rules hook: `rules()`, or a `schema()` whose first parameter is
     * typed `FluentSchema` and that the package's `HasFluentRules` can be calling. Only that trait
     * calls `schema()`; a plain `FormRequest` reads `rules()` alone. A class that extends `FormRequest`
     * directly, or nothing, and uses no trait cannot be running it. Any trait or other parent may
     * bring the trait in from a file this one does not show, so those are read.
     */
    public function isHook(ClassMethod $method): bool
    {
        $name = $method->name->toLowerString();

        if ($name === 'rules') {
            return true;
        }

        return $name === 'schema' && $this->schemaVariable($method->params[0] ?? null) !== null && $this->canRunSchema();
    }

    /**
     * The hook's field items as [key, value] pairs, with the schema variables chains may start from.
     * Null when the hook is present but cannot be read in full: a body that is not one `return`, a
     * form this reader does not know, or, inside a `RuleSet` or `schema()` form, a key it cannot
     * resolve.
     *
     * @return array{list<array{string, Expr}>, list<string>}|null
     */
    public function hookItems(ClassMethod $method): ?array
    {
        $statements = $method->stmts ?? [];
        $return = $statements[0] ?? null;

        if (count($statements) !== 1 || ! $return instanceof Return_ || ! $return->expr instanceof Expr) {
            return null;
        }

        $variable = $method->name->toLowerString() === 'schema' ? $this->schemaVariable($method->params[0] ?? null) : null;
        $variables = $variable === null ? [] : [$variable];

        // An array-literal `rules()` reads leniently, so its output stays what it was before the
        // `RuleSet` and `schema()` forms were read: an item with a key it cannot resolve is skipped.
        if ($return->expr instanceof Array_ && $method->name->toLowerString() === 'rules') {
            return [$this->lenientItems($return->expr), $variables];
        }

        return $this->formItems($return->expr, $variables);
    }

    /**
     * The items of an array literal whose keys resolve, skipping the rest: the reading of
     * `validate([...])` arrays and of an array-literal `rules()`.
     *
     * @return list<array{string, Expr}>
     */
    public function lenientItems(Array_ $array): array
    {
        $items = [];

        foreach ($array->items as $item) {
            $key = $item->unpack ? null : $this->key($item->key);

            if ($key !== null) {
                $items[] = [$key, $item->value];
            }
        }

        return $items;
    }

    /**
     * @param  list<string>  $variables
     * @return array{list<array{string, Expr}>, list<string>}|null
     */
    private function formItems(Expr $expr, array $variables): ?array
    {
        if ($expr instanceof Array_) {
            $items = $this->strictItems($expr);

            return $items === null ? null : [$items, $variables];
        }

        if ($expr instanceof StaticCall && $this->isRuleSet($expr)) {
            $method = $expr->name instanceof Identifier ? $expr->name->toString() : null;
            $arguments = $this->positional($expr, $method === 'make' ? 0 : 1);

            if ($arguments === null) {
                return null;
            }

            return match ($method) {
                'from' => $arguments[0] instanceof Array_ && ($items = $this->strictItems($arguments[0])) !== null ? [$items, $variables] : null,
                'define' => $this->defineItems($arguments[0], $variables),
                'make' => [[], $variables],
                default => null,
            };
        }

        if ($expr instanceof MethodCall) {
            return $this->madeItems($expr, $variables);
        }

        return null;
    }

    /**
     * `RuleSet::make()` followed only by `field()` / `put()`. A repeated name keeps the last value, as
     * the package assigns it.
     *
     * @param  list<string>  $variables
     * @return array{list<array{string, Expr}>, list<string>}|null
     */
    private function madeItems(MethodCall $call, array $variables): ?array
    {
        $calls = [];

        while ($call instanceof MethodCall) {
            $calls[] = $call;
            $call = $call->var;
        }

        if (! $call instanceof StaticCall || ! $this->isRuleSet($call) || ! $call->name instanceof Identifier
            || $call->name->toString() !== 'make' || $this->positional($call, 0) === null) {
            return null;
        }

        $items = [];

        foreach (array_reverse($calls) as $link) {
            $name = $link->name instanceof Identifier ? $link->name->toString() : null;
            $arguments = in_array($name, ['field', 'put'], true) ? $this->positional($link, 2) : null;
            $key = $arguments === null ? null : $this->key($arguments[0]);

            if ($arguments === null || $key === null) {
                return null;
            }

            $items[] = [$key, $arguments[1]];
        }

        return [$items, $variables];
    }

    /**
     * `RuleSet::define(fn (FluentSchema $r) => [...])`, or a `function` whose one statement returns the
     * array. The closure's FluentSchema parameter is a schema root for the chains inside.
     *
     * @param  list<string>  $variables
     * @return array{list<array{string, Expr}>, list<string>}|null
     */
    private function defineItems(Expr $callback, array $variables): ?array
    {
        if (! $callback instanceof ArrowFunction && ! $callback instanceof Closure) {
            return null;
        }

        $variable = $this->schemaVariable($callback->params[0] ?? null);

        if ($variable === null) {
            return null;
        }

        // An arrow function sees the enclosing variables; a `function` closure sees only its own
        // parameters without `use`, so the outer schema variable is not a root inside it.
        if ($callback instanceof ArrowFunction) {
            $body = $callback->expr;
            $roots = [...$variables, $variable];
        } else {
            $return = $callback->stmts[0] ?? null;
            $body = count($callback->stmts) === 1 && $return instanceof Return_ ? $return->expr : null;
            $roots = [$variable];
        }

        $items = $body instanceof Array_ ? $this->strictItems($body) : null;

        return $items === null ? null : [$items, $roots];
    }

    /**
     * Every item of a `RuleSet` or `schema()` array, or null when one key cannot be resolved or an
     * item is a spread. A repeated key keeps the last value; the reader assigns by key.
     *
     * @return list<array{string, Expr}>|null
     */
    private function strictItems(Array_ $array): ?array
    {
        $items = [];

        foreach ($array->items as $item) {
            $key = $item->unpack ? null : $this->key($item->key);

            if ($key === null) {
                return null;
            }

            $items[] = [$key, $item->value];
        }

        return $items;
    }

    /**
     * A key as written: a string literal, or a string constant the class declares, through `self::`,
     * or through `static::` when neither the class nor the constant can be overridden. Late static
     * binding lets a subclass replace a `static::` constant, and a method it inherits reads the
     * subclass's value.
     */
    private function key(?Expr $key): ?string
    {
        if ($key instanceof String_) {
            return $key->value;
        }

        if (! $key instanceof ClassConstFetch || ! $key->class instanceof Name || ! $key->name instanceof Identifier) {
            return null;
        }

        $constant = $this->constants[$key->name->toString()] ?? null;
        $scope = strtolower($key->class->toString());

        if ($constant === null) {
            return null;
        }

        [$value, $final] = $constant;

        return match ($scope) {
            'self' => $value,
            'static' => $final || ($this->class instanceof Class_ && $this->class->isFinal()) ? $value : null,
            default => null,
        };
    }

    /**
     * The call's positional arguments, exactly `$count` of them, or null when any is named, unpacked or
     * missing, or the call is a first-class callable. `field()` names its first parameter `name` and
     * `put()` names it `field`, so a positional read of a named call can turn the pair around.
     *
     * @return list<Expr>|null
     */
    private function positional(MethodCall|StaticCall $call, int $count): ?array
    {
        if ($call->isFirstClassCallable()) {
            return null;
        }

        $arguments = [];

        foreach ($call->getArgs() as $arg) {
            if ($arg->name instanceof Identifier || $arg->unpack) {
                return null;
            }

            $arguments[] = $arg->value;
        }

        return count($arguments) === $count ? $arguments : null;
    }

    private function isRuleSet(StaticCall $call): bool
    {
        return $call->class instanceof Name && AppFiles::resolveName($call->class) === self::RULE_SET;
    }

    /**
     * The parameter's variable name when its type is `FluentSchema`, `?FluentSchema` or
     * `FluentSchema|null`, else null. The package accepts all three: reflection names each of them
     * `FluentSchema`.
     */
    private function schemaVariable(?Param $param): ?string
    {
        $type = $param?->type;

        if ($type instanceof NullableType) {
            $type = $type->type;
        } elseif ($type instanceof UnionType && count($type->types) === 2) {
            $named = array_values(array_filter($type->types, static fn (Node $member): bool => ! $member instanceof Identifier || $member->toLowerString() !== 'null'));
            $type = count($named) === 1 ? $named[0] : null;
        }

        return $type instanceof Name
            && AppFiles::resolveName($type) === self::FLUENT_SCHEMA
            && $param?->var instanceof Variable
            && is_string($param->var->name)
                ? $param->var->name
                : null;
    }

    /**
     * Whether the class body itself uses `HasFluentRules`. Then both hooks run, and of a `rules()`
     * and a `schema()` declared in the same body the package keeps `schema()` for a shared field
     * (`HasFluentRules::resolveSchemaOutranksRules()`).
     */
    public function usesHasFluentRules(): bool
    {
        return array_any($this->class->stmts, static fn (Stmt $statement): bool => $statement instanceof TraitUse && array_any($statement->traits, static fn (Name $trait): bool => AppFiles::resolveName($trait) === self::HAS_FLUENT_RULES));
    }

    private function canRunSchema(): bool
    {
        $usesTrait = array_any($this->class->stmts, static fn (mixed $statement): bool => $statement instanceof TraitUse);
        $parent = HazardSource::parentOf($this->class);

        return $usesTrait || ($parent !== null && $parent !== self::FORM_REQUEST);
    }
}
