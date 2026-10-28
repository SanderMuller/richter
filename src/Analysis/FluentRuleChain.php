<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use SanderMuller\Richter\Support\AppFiles;

/**
 * Reads a `sandermuller/laravel-fluent-validation` chain (`FluentRule::string()->required()->max(255)`)
 * as the Laravel rule names it compiles to, statically. Null whenever any link cannot be mapped with
 * certainty: a wrong rule name is worse than none, because a lane comparing two sides would invent a
 * removal.
 *
 * The tables mirror the package's builders, where every chain method adds its rule by hand: there is
 * no snake_case rule to derive from (`alphaNumeric` is `alpha_num`, `exactly` is `size`).
 *
 * @internal
 */
final class FluentRuleChain
{
    private const string ROOT = 'SanderMuller\FluentValidation\FluentRule';

    /**
     * Factory => [builder family, the rules the factory seeds]. `password()`, `enum()` and `anyOf()`
     * build rule objects, and a macro factory is unknown, so none is listed.
     *
     * @var array<string, array{string, list<string>}>
     */
    private const array FACTORIES = [
        'string' => ['string', ['string']],
        'numeric' => ['numeric', ['numeric']],
        'integer' => ['numeric', ['numeric', 'integer']],
        'date' => ['date', ['date']],
        'dateTime' => ['date', ['date_format']],
        'boolean' => ['boolean', ['boolean']],
        'accepted' => ['accepted', ['accepted']],
        'declined' => ['declined', ['declined']],
        'array' => ['array', ['array']],
        'list' => ['array', ['array', 'list']],
        'file' => ['file', ['file']],
        'image' => ['image', ['image']],
        'email' => ['email', ['string', 'email']],
        'url' => ['string', ['string', 'url']],
        'httpUrl' => ['string', ['string', 'url']],
        'uuid' => ['string', ['string', 'uuid']],
        'ulid' => ['string', ['string', 'ulid']],
        'ip' => ['string', ['string', 'ip']],
        'ipv4' => ['string', ['string', 'ipv4']],
        'ipv6' => ['string', ['string', 'ipv6']],
        'macAddress' => ['string', ['string', 'mac_address']],
        'json' => ['string', ['string', 'json']],
        'timezone' => ['string', ['string', 'timezone']],
        'hexColor' => ['string', ['string', 'hex_color']],
        'activeUrl' => ['string', ['string', 'active_url']],
        'regex' => ['string', ['string', 'regex']],
        'field' => ['field', []],
    ];

    /**
     * The embedded rules (`HasEmbeddedRules`), on the string, numeric, date, email and field builders.
     * The package adds them as rule objects; they read as the names the string form of the same rule
     * has, so a migrated rule reads the same on both sides.
     *
     * @var array<string, list<string>>
     */
    private const array EMBEDDED = [
        'unique' => ['unique'], 'exists' => ['exists'], 'enum' => ['enum'], 'in' => ['in'], 'notIn' => ['not_in'],
    ];

    /**
     * The comparison methods the string, numeric, date, email and field builders each define.
     *
     * @var array<string, list<string>>
     */
    private const array COMPARISONS = [
        'same' => ['same'], 'different' => ['different'], 'confirmed' => ['confirmed'],
        'inArray' => ['in_array'], 'distinct' => ['distinct'],
    ];

    /**
     * Family => method => the rules it adds. `*` holds the modifiers every builder has
     * (`HasFieldModifiers`). An empty
     * list is a method that adds no rule to the field itself (a label, a message, nested `each()` and
     * `children()` keys, an email validation mode).
     *
     * @var array<string, array<string, list<string>>>
     */
    private const array METHODS = [
        '*' => [
            'label' => [], 'message' => [], 'messageFor' => [], 'fieldMessage' => [],
            'bail' => ['bail'], 'nullable' => ['nullable'], 'required' => ['required'], 'sometimes' => ['sometimes'],
            'filled' => ['filled'], 'present' => ['present'], 'prohibited' => ['prohibited'], 'exclude' => ['exclude'],
            'missing' => ['missing'],
            'presentIf' => ['present_if'], 'presentUnless' => ['present_unless'], 'presentWith' => ['present_with'],
            'presentWithAll' => ['present_with_all'],
            'missingIf' => ['missing_if'], 'missingUnless' => ['missing_unless'], 'missingWith' => ['missing_with'],
            'missingWithAll' => ['missing_with_all'],
            'requiredWith' => ['required_with'], 'requiredWithAll' => ['required_with_all'],
            'requiredWithout' => ['required_without'], 'requiredWithoutAll' => ['required_without_all'],
            'requiredIfAccepted' => ['required_if_accepted'], 'requiredIfDeclined' => ['required_if_declined'],
            'excludeWith' => ['exclude_with'], 'excludeWithout' => ['exclude_without'],
            'prohibits' => ['prohibits'], 'prohibitedIfAccepted' => ['prohibited_if_accepted'],
            'prohibitedIfDeclined' => ['prohibited_if_declined'],
        ],
        'string' => [
            ...self::EMBEDDED, ...self::COMPARISONS, 'inArrayKeys' => ['in_array_keys'],
            'alpha' => ['alpha'], 'alphaDash' => ['alpha_dash'], 'alphaNumeric' => ['alpha_num'], 'ascii' => ['ascii'],
            'encoding' => ['encoding'], 'between' => ['between'], 'doesntEndWith' => ['doesnt_end_with'],
            'doesntStartWith' => ['doesnt_start_with'], 'endsWith' => ['ends_with'], 'exactly' => ['size'],
            'lowercase' => ['lowercase'], 'max' => ['max'], 'min' => ['min'], 'startsWith' => ['starts_with'],
            'uppercase' => ['uppercase'], 'url' => ['url'], 'httpUrl' => ['url'], 'activeUrl' => ['active_url'],
            'uuid' => ['uuid'], 'ulid' => ['ulid'], 'json' => ['json'], 'ip' => ['ip'], 'ipv4' => ['ipv4'],
            'ipv6' => ['ipv6'], 'macAddress' => ['mac_address'], 'regex' => ['regex'], 'notRegex' => ['not_regex'],
            'timezone' => ['timezone'], 'hexColor' => ['hex_color'], 'date' => ['date'], 'email' => ['email'],
            'dateFormat' => ['date_format'], 'currentPassword' => ['current_password'],
        ],
        'numeric' => [
            ...self::EMBEDDED, ...self::COMPARISONS, 'inArrayKeys' => ['in_array_keys'],
            'between' => ['between'], 'decimal' => ['decimal'], 'digits' => ['integer', 'digits'],
            'digitsBetween' => ['integer', 'digits_between'], 'greaterThan' => ['gt'], 'greaterThanOrEqualTo' => ['gte'],
            'integer' => ['integer'], 'lessThan' => ['lt'], 'lessThanOrEqualTo' => ['lte'], 'max' => ['max'],
            'maxDigits' => ['max_digits'], 'min' => ['min'], 'minDigits' => ['min_digits'],
            'multipleOf' => ['multiple_of'], 'positive' => ['gt'], 'negative' => ['lt'], 'nonNegative' => ['gte'],
            'nonPositive' => ['lte'], 'exactly' => ['integer', 'size'],
        ],
        'date' => [
            ...self::EMBEDDED, ...self::COMPARISONS,
            'beforeToday' => ['before'], 'afterToday' => ['after'], 'todayOrBefore' => ['before_or_equal'],
            'todayOrAfter' => ['after_or_equal'], 'past' => ['before'], 'future' => ['after'],
            'nowOrPast' => ['before_or_equal'], 'nowOrFuture' => ['after_or_equal'], 'before' => ['before'],
            'after' => ['after'], 'beforeOrEqual' => ['before_or_equal'], 'afterOrEqual' => ['after_or_equal'],
            'between' => ['after', 'before'], 'betweenOrEqual' => ['after_or_equal', 'before_or_equal'],
            'dateEquals' => ['date_equals'],
        ],
        'boolean' => [
            'accepted' => ['accepted'], 'acceptedIf' => ['accepted_if'], 'declined' => ['declined'],
            'declinedIf' => ['declined_if'],
        ],
        'array' => [
            'min' => ['min'], 'max' => ['max'], 'between' => ['between'], 'exactly' => ['size'], 'list' => ['list'],
            'requiredArrayKeys' => ['required_array_keys'], 'contains' => ['contains'],
            'doesntContain' => ['doesnt_contain'], 'each' => [], 'children' => [], 'inArrayKeys' => ['in_array_keys'],
            'distinct' => ['distinct'],
        ],
        'file' => [
            'min' => ['min'], 'max' => ['max'], 'between' => ['between'], 'exactly' => ['size'],
            'extensions' => ['extensions'], 'mimes' => ['mimes'], 'mimetypes' => ['mimetypes'],
        ],
        'image' => [
            'min' => ['min'], 'max' => ['max'], 'between' => ['between'], 'exactly' => ['size'],
            'extensions' => ['extensions'], 'mimes' => ['mimes'], 'mimetypes' => ['mimetypes'], 'allowSvg' => [],
        ],
        'email' => [
            ...self::EMBEDDED, ...self::COMPARISONS,
            'rfcCompliant' => [], 'strict' => [], 'validateMxRecord' => [], 'preventSpoofing' => [],
            'withNativeValidation' => [], 'max' => ['max'],
        ],
        'field' => [...self::EMBEDDED, ...self::COMPARISONS, 'inArrayKeys' => ['in_array_keys'], 'children' => []],
    ];

    /**
     * Methods that REPLACE a seeded rule rather than add one: family => method => [removed, added].
     *
     * @var array<string, array<string, array{string, string}>>
     */
    private const array REPLACING = [
        'date' => ['format' => ['date', 'date_format']],
        'accepted' => ['acceptedIf' => ['accepted', 'accepted_if']],
        'declined' => ['declinedIf' => ['declined', 'declined_if']],
    ];

    /**
     * Presence methods whose rule depends on their argument: a field name gives the snake_case rule,
     * a Closure or bool a rule object. Method => [rule for a field name, rule a literal `true` yields,
     * rule a literal `false` yields]; '' yields no rule, and null means a literal bool cannot be read.
     *
     * @var array<string, array{string, string|null, string|null}>
     */
    private const array BY_ARGUMENT = [
        'requiredIf' => ['required_if', 'required', ''],
        'requiredUnless' => ['required_unless', '', 'required'],
        'excludeIf' => ['exclude_if', null, null],
        'excludeUnless' => ['exclude_unless', null, null],
        'prohibitedIf' => ['prohibited_if', null, null],
        'prohibitedUnless' => ['prohibited_unless', null, null],
    ];

    /** A rule passed through `->rule()` under one of these names reads differently per execution mode. */
    private const array PRESENCE_NAMES = ['required', 'present', 'sometimes', 'nullable', 'filled', 'accepted', 'declined'];

    private const array PRESENCE_PREFIXES = ['required_', 'present_', 'exclude', 'prohibited', 'missing'];

    /**
     * The rule names the chain compiles to, without their parameters. Null when the value is no FluentRule chain,
     * or any link cannot be mapped.
     *
     * @return list<string>|null
     */
    public static function ruleNames(Node $value): ?array
    {
        $calls = [];

        while ($value instanceof MethodCall) {
            $calls[] = $value;
            $value = $value->var;
        }

        if (! $value instanceof StaticCall
            || ! $value->class instanceof Name
            || AppFiles::resolveName($value->class) !== self::ROOT
            || ! $value->name instanceof Identifier
            || $value->isFirstClassCallable()) {
            return null;
        }

        $factory = self::FACTORIES[$value->name->toString()] ?? null;

        if ($factory === null) {
            return null;
        }

        [$family, $names] = $factory;

        foreach (array_reverse($calls) as $call) {
            $names = self::apply($family, $names, $call);

            if ($names === null) {
                return null;
            }
        }

        return self::presenceIsCertain($names) ? $names : null;
    }

    /**
     * @param  list<string>  $names
     * @return list<string>|null
     */
    private static function apply(string $family, array $names, MethodCall $call): ?array
    {
        if (! $call->name instanceof Identifier || $call->isFirstClassCallable()) {
            return null;
        }

        $method = $call->name->toString();
        $added = self::METHODS[$family][$method] ?? self::METHODS['*'][$method] ?? null;

        if ($added !== null) {
            return [...$names, ...$added];
        }

        if (isset(self::REPLACING[$family][$method])) {
            [$removed, $replacement] = self::REPLACING[$family][$method];
            // The seeded rule only: a `->rule()` adding the same name earlier stays.
            $seeded = array_search($removed, $names, true);

            if ($seeded !== false) {
                unset($names[$seeded]);
            }

            return [...array_values($names), $replacement];
        }

        // `->rule()` and the conditional presence methods read their first argument. A named or
        // unpacked argument may not be that parameter, so it is refused, as the shared reader refuses
        // one for a rules array.
        $readsArgument = isset(self::BY_ARGUMENT[$method]) || $method === 'rule';

        if (! $readsArgument || array_any($call->getArgs(), static fn (Arg $arg): bool => $arg->name instanceof Identifier || $arg->unpack)) {
            return null;
        }

        $first = $call->getArgs()[0] ?? null;
        $rule = $method === 'rule' ? self::ruleToken($first) : self::byArgument($method, $first);

        if ($rule === null) {
            return null;
        }

        return $rule === '' ? $names : [...$names, $rule];
    }

    /** The rule a conditional presence method adds, '' for none, null when the argument cannot be read. */
    private static function byArgument(string $method, ?Arg $arg): ?string
    {
        [$named, $whenTrue, $whenFalse] = self::BY_ARGUMENT[$method];
        $value = $arg?->value;

        if ($value instanceof String_) {
            return $named;
        }

        if ($value instanceof ConstFetch) {
            return match (strtolower($value->name->toString())) {
                'true' => $whenTrue,
                'false' => $whenFalse,
                default => null,
            };
        }

        return null;
    }

    /**
     * The one rule a `->rule()` literal adds. A tuple is read as the token the package rebuilds from
     * it (`['mimes', 'pdf']` is `mimes:pdf`). A token holding `|` is refused: `email()` pipe-joins its
     * compiled strings, so there the token splits into several rules. A presence rule is refused too:
     * the package's rule-object mode matches presence on exact strings, so `->rule('Required')`
     * fails a missing field compiled and passes it as an object.
     */
    private static function ruleToken(?Arg $arg): ?string
    {
        $value = $arg?->value;

        if ($value instanceof Array_) {
            $parts = [];

            foreach ($value->items as $item) {
                if (! $item->value instanceof String_ || $item->key !== null || $item->unpack) {
                    return null;
                }

                $parts[] = $item->value->value;
            }

            $value = $parts === [] ? null : new String_($parts[0] . (count($parts) > 1 ? ':' . implode(',', array_slice($parts, 1)) : ''));
        }

        if (! $value instanceof String_ || str_contains($value->value, '|')) {
            return null;
        }

        $name = strtolower(trim(explode(':', $value->value, 2)[0]));

        if ($name === '' || in_array($name, self::PRESENCE_NAMES, true)
            || array_any(self::PRESENCE_PREFIXES, static fn (string $prefix): bool => str_starts_with($name, $prefix))) {
            return null;
        }

        return $name;
    }

    /**
     * Whether the chain's required-ness reads the same in both modes the package runs it in. Compiled
     * (through `HasFluentRules`), a bare `accepted` fails a missing field; as a rule object it skips an
     * absent field without a presence modifier, and a null one under `nullable`. With `required` or
     * `present` both modes reject a missing field.
     *
     * @param  list<string>  $names
     */
    private static function presenceIsCertain(array $names): bool
    {
        if (array_intersect($names, ['accepted', 'declined']) === []) {
            return true;
        }

        return array_intersect($names, ['required', 'present']) !== [];
    }
}
