<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

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
use PhpParser\Node\Param;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Node\Stmt\TraitUse;
use SanderMuller\Richter\Analysis\Hazards\HazardSource;
use SanderMuller\Richter\Support\AppFiles;

/**
 * How one class declares its validation rules: which methods are the rules hook, the field items
 * each hook returns, and the string constants its keys may name.
 *
 * Reads the `laravel-fluent-validation` forms besides a plain array literal: `RuleSet::from([...])`,
 * `RuleSet::define(fn (FluentSchema $r) => [...])`, `RuleSet::make()->field()/put()`, and a
 * `schema(FluentSchema $rules)` method. Each new form is read whole or not at all — a field the reader
 * skipped could be the one that overrides another, so a partly read form reads as unreadable.
 *
 * @internal
 */
final readonly class RulesDeclaration
{
    private const string RULE_SET = 'SanderMuller\FluentValidation\RuleSet';

    private const string FLUENT_SCHEMA = 'SanderMuller\FluentValidation\FluentSchema';

    private const string FORM_REQUEST = 'Illuminate\Foundation\Http\FormRequest';

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
        $name = $method->name->toString();

        if ($name === 'rules') {
            return true;
        }

        return $name === 'schema' && $this->schemaVariable($method->params[0] ?? null) !== null && $this->canRunSchema();
    }

    /**
     * The hook's field items as [key, value] pairs, with the schema variables chains may start from.
     * Null when the hook is present but cannot be read in full: a body that is not one `return`, a
     * form this reader does not know, or, inside a new form, a key it cannot resolve.
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

        $variables = $method->name->toString() === 'schema'
            ? [$this->schemaVariable($method->params[0] ?? null)]
            : [];
        $variables = array_values(array_filter($variables, is_string(...)));

        // The array-literal `rules()` is the shape read before the new forms existed, and keeps its
        // lenient reading: an item with a key it cannot resolve is skipped, the rest still read.
        if ($return->expr instanceof Array_ && $method->name->toString() === 'rules') {
            return [$this->lenientItems($return->expr), $variables];
        }

        return $this->formItems($return->expr, $variables);
    }

    /**
     * The items of an array literal whose keys resolve, skipping the rest — the reading the
     * `validate([...])` arrays and the array-literal `rules()` have always had.
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

            $items = $this->assign($items, $key, $arguments[1]);
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

        if ($callback instanceof ArrowFunction) {
            $body = $callback->expr;
        } else {
            $return = $callback->stmts[0] ?? null;
            $body = count($callback->stmts) === 1 && $return instanceof Return_ ? $return->expr : null;
        }

        $items = $body instanceof Array_ ? $this->strictItems($body) : null;

        return $items === null ? null : [$items, [...$variables, $variable]];
    }

    /**
     * Every item of a new form's array, or null when one key cannot be resolved or an item is a spread.
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

            $items = $this->assign($items, $key, $item->value);
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

    /**
     * Sets one key, as PHP and the package do: a repeated key keeps its first position and its last
     * value.
     *
     * @param  list<array{string, Expr}>  $items
     * @return list<array{string, Expr}>
     */
    private function assign(array $items, string $key, Expr $value): array
    {
        foreach ($items as $index => [$existing]) {
            if ($existing === $key) {
                $items[$index] = [$key, $value];

                return $items;
            }
        }

        $items[] = [$key, $value];

        return $items;
    }

    private function isRuleSet(StaticCall $call): bool
    {
        return $call->class instanceof Name && AppFiles::resolveName($call->class) === self::RULE_SET;
    }

    /** The parameter's variable name when its type is `FluentSchema`, else null. */
    private function schemaVariable(?Param $param): ?string
    {
        return $param instanceof Param
            && $param->type instanceof Name
            && AppFiles::resolveName($param->type) === self::FLUENT_SCHEMA
            && $param->var instanceof Variable
            && is_string($param->var->name)
                ? $param->var->name
                : null;
    }

    private function canRunSchema(): bool
    {
        $usesTrait = array_any($this->class->stmts, static fn (mixed $statement): bool => $statement instanceof TraitUse);
        $parent = HazardSource::parentOf($this->class);

        return $usesTrait || ($parent !== null && $parent !== self::FORM_REQUEST);
    }
}
