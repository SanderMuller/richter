<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassMethod;
use SanderMuller\Richter\Analysis\Hazards\HazardSource;
use SanderMuller\Richter\Support\AppFiles;

/**
 * Reads validation rules as field => rule names. Shared by the boundary and dropped-request-field
 * lanes; `$requestDataOnly` is the one place they differ.
 *
 * @internal
 */
final class ValidationRules
{
    /** Unconditional rules that fail on a missing field: Laravel runs them even when it is absent. */
    private const array REQUIRING = ['required', 'present', 'accepted', 'declined'];

    /**
     * Rule families whose outcome on a missing field depends on other input, removes the field, or
     * is not modelled here. A field carrying one cannot be called required or optional.
     */
    private const array CONDITIONAL_PREFIXES = ['required_', 'present_', 'accepted_', 'declined_', 'exclude', 'missing', 'prohibited'];

    /**
     * Every validation array one side declares, keyed by the member holding it, then by field name.
     *
     * Anchored on the MEMBER rather than the file for the same reason the inline parity lane is: a
     * controller holds several actions validating different things, and a file-level comparison would
     * report one action's dropped rule against another's.
     *
     * A field whose rules cannot be read in full maps to null rather than to an empty list — "cannot
     * vouch" and "validates nothing" must not collapse into one another.
     *
     * @return array<string, array<string, list<string>|null>>
     */
    public static function rulesByMember(string $source): array
    {
        return array_filter(self::settledMembers($source, requestDataOnly: false), static fn (array $fields): bool => $fields !== []);
    }

    /**
     * Every method's fields, keyed by member id, with a class's two rules hooks settled against each
     * other. The package merges `rules()` and a FluentSchema `schema()` and keeps `schema()` for a
     * shared field when both sit in one class body — but only `HasFluentRules` runs `schema()` at all.
     * When the class body uses that trait, `schema()` wins: `rules()`' shared fields read null, and all
     * of them when `schema()` cannot be read. Otherwise the trait may come from a parent or another
     * trait, or not at all, so a field both declare reads null in both, and an unreadable hook makes
     * every field of the other null too: the unread one could override any of them.
     *
     * @return array<string, array<string, list<string>|null>>
     */
    private static function settledMembers(string $source, bool $requestDataOnly): array
    {
        $byMember = [];

        foreach (HazardSource::classLikes($source) as $fqcn => $class) {
            $declaration = RulesDeclaration::of($class);
            /** @var array<string, array{string, bool}> $hooks method name => [member, read in full] */
            $hooks = [];

            foreach ($class->getMethods() as $method) {
                $member = "{$fqcn}::{$method->name->toString()}";
                [$fields, $complete] = self::fieldsOf($method, $requestDataOnly, $declaration);
                $byMember[$member] = $fields;

                if ($declaration->isHook($method)) {
                    $hooks[$method->name->toLowerString()] = [$member, $complete];
                }
            }

            if (count($hooks) === 2) {
                $byMember = self::settleHooks($byMember, $hooks['rules'], $hooks['schema'], $declaration->usesHasFluentRules());
            }
        }

        return $byMember;
    }

    /**
     * @param  array<string, array<string, list<string>|null>>  $byMember
     * @param  array{string, bool}  $rules
     * @param  array{string, bool}  $schema
     * @param  bool  $schemaWins  the class body uses `HasFluentRules`, so `schema()` overrides `rules()`
     * @return array<string, array<string, list<string>|null>>
     */
    private static function settleHooks(array $byMember, array $rules, array $schema, bool $schemaWins): array
    {
        [$rulesMember, $rulesComplete] = $rules;
        [$schemaMember, $schemaComplete] = $schema;
        $unknown = static fn (array $fields): array => array_fill_keys(array_keys($fields), null);

        if ($schemaWins) {
            $byMember[$rulesMember] = $schemaComplete
                ? array_merge($byMember[$rulesMember], array_fill_keys(array_keys(array_intersect_key($byMember[$rulesMember], $byMember[$schemaMember])), null))
                : $unknown($byMember[$rulesMember]);

            return $byMember;
        }

        if (! $rulesComplete || ! $schemaComplete) {
            $byMember[$rulesMember] = $unknown($byMember[$rulesMember]);
            $byMember[$schemaMember] = $unknown($byMember[$schemaMember]);

            return $byMember;
        }

        foreach (array_keys(array_intersect_key($byMember[$rulesMember], $byMember[$schemaMember])) as $field) {
            $byMember[$rulesMember][$field] = null;
            $byMember[$schemaMember][$field] = null;
        }

        return $byMember;
    }

    /**
     * Whether each field one method validates is required: true when a missing field fails, false
     * when it passes, null when the source cannot say.
     *
     * Matched on the method NAME, not a member id: an inherited action's node points at the file that
     * declares it, where the method is keyed on the parent class. A name found in two classes of one
     * file is ambiguous and reads as nothing.
     *
     * Top-level fields only. A dotted key (`items.*.name`) is skipped, because a frontend sends
     * top-level names.
     *
     * @param  bool  $requestDataOnly  leave out `Validator::make($data, [...])`, whose data need not be
     *   the request
     * @return array<string, bool|null>
     */
    public static function requiredFields(string $source, string $method, bool $requestDataOnly): array
    {
        $member = self::memberNamed($source, $method);

        if ($member === null) {
            return [];
        }

        $required = [];

        foreach (self::settledMembers($source, $requestDataOnly)[$member] ?? [] as $field => $rules) {
            if (! str_contains($field, '.')) {
                $required[$field] = self::isRequired($rules);
            }
        }

        return $required;
    }

    /** Whether a method of that name in the source is a rules hook (`rules()`, or a FluentSchema `schema()` that can run). */
    public static function isRulesHook(string $source, string $method): bool
    {
        foreach (HazardSource::classLikes($source) as $class) {
            foreach ($class->getMethods() as $candidate) {
                if ($candidate->name->toLowerString() === strtolower($method) && RulesDeclaration::of($class)->isHook($candidate)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The one member id a source declares for a method name — `App\Foo::bar`, keyed on the class that
     * declares it. Null when no class, or more than one, declares it.
     */
    public static function memberNamed(string $source, string $method): ?string
    {
        $matches = array_values(array_filter(
            array_keys(HazardSource::methods($source)),
            static fn (string $member): bool => str_ends_with($member, "::{$method}"),
        ));

        return count($matches) === 1 ? $matches[0] : null;
    }

    /**
     * @param  list<string>|null  $rules
     */
    private static function isRequired(?array $rules): ?bool
    {
        if ($rules === null) {
            return null;
        }

        foreach ($rules as $rule) {
            if (array_any(self::CONDITIONAL_PREFIXES, static fn (string $prefix): bool => str_starts_with($rule, $prefix))) {
                return null;
            }
        }

        // `sometimes` skips every rule on a missing field, so the field is optional whatever else it
        // lists.
        if (in_array('sometimes', $rules, true)) {
            return false;
        }

        return array_intersect($rules, self::REQUIRING) !== [];
    }

    /**
     * One method's fields, and whether its rules hook — when it is one — was read in full.
     *
     * @return array{array<string, list<string>|null>, bool}
     */
    private static function fieldsOf(ClassMethod $method, bool $requestDataOnly, RulesDeclaration $declaration): array
    {
        $fields = [];
        $complete = true;

        if ($declaration->isHook($method)) {
            $hook = $declaration->hookItems($method);
            $complete = $hook !== null;

            foreach ($hook[0] ?? [] as [$key, $value]) {
                $fields[$key] = self::ruleNames($value, $hook[1] ?? []);
            }
        }

        foreach (self::validationArrays($method, $requestDataOnly) as $array) {
            foreach ($declaration->lenientItems($array) as [$key, $value]) {
                $fields[$key] = self::ruleNames($value);
            }
        }

        return [$fields, $complete];
    }

    /**
     * The inline rules arrays inside one method: every `validate([...])` call written out at the call
     * site, and — unless `$requestDataOnly` — `Validator::make($data, [...])`. A rules hook's return is
     * read by {@see RulesDeclaration}. A `validate($rules)` passing a variable
     * contributes nothing — its fields are opaque, so the method's readable arrays stand alone.
     *
     * @return list<Array_>
     */
    private static function validationArrays(ClassMethod $method, bool $requestDataOnly): array
    {
        $arrays = [];

        foreach (HazardSource::within($method, MethodCall::class) as $call) {
            if (! $call->name instanceof Identifier || $call->name->toString() !== 'validate' || $call->isFirstClassCallable()) {
                continue;
            }

            // `$request->validate([...])` puts the rules first; the `ValidatesRequests` trait form
            // `$this->validate($request, [...])` puts them second. Reading whichever argument happens
            // to be an array literal would let `Validator::make(['title' => 'x'], $rules)`'s DATA read
            // as a rules map, and an edit to that data would invent a validation hazard.
            $array = self::arrayArgument($call->args, HazardSource::isThis($call->var) ? 1 : 0);

            if ($array instanceof Array_) {
                $arrays[] = $array;
            }
        }

        if ($requestDataOnly) {
            return $arrays;
        }

        foreach (HazardSource::within($method, StaticCall::class) as $call) {
            // `Validator::make($data, [...])` — rules second. Resolved, not the literal token: an
            // imported facade stays the bare `Validator` in the AST, and matching on that would miss
            // every file that imports it, leaving a dropped `required` invisible.
            if (! $call->name instanceof Identifier || $call->name->toString() !== 'make' || ! $call->class instanceof Name) {
                continue;
            }

            if (! str_ends_with(AppFiles::resolveName($call->class), '\\Validator')) {
                continue;
            }

            $array = self::arrayArgument($call->args, 1);

            if ($array instanceof Array_) {
                $arrays[] = $array;
            }
        }

        return $arrays;
    }

    /**
     * The array literal at one argument position, or null when that argument is absent, named, or not
     * written out at the call site. A named argument shifts every position, so it is refused rather
     * than counted — the lane would rather read no rules than the wrong array.
     *
     * @param  array<Node>  $args
     */
    private static function arrayArgument(array $args, int $position): ?Array_
    {
        if (array_any($args, static fn (Node $arg): bool => $arg instanceof Arg && $arg->name instanceof Identifier)) {
            return null;
        }

        $arg = $args[$position] ?? null;

        return $arg instanceof Arg && $arg->value instanceof Array_ ? $arg->value : null;
    }

    /**
     * A field's rule NAMES — `max:255` reduces to `max`, because the lane asks whether the constraint
     * is still there, not what its bound is. A FluentRule chain is read through
     * {@see FluentRuleChain}. Null when any element cannot be read as a literal: a
     * `Rule::unique(…)` object or a spread makes the list unknowable, and comparing a partial list
     * against a full one would invent removals.
     *
     * @param  list<string>  $schemaVariables  the FluentSchema variables a chain may start from
     * @return list<string>|null
     */
    private static function ruleNames(Node $value, array $schemaVariables = []): ?array
    {
        if ($value instanceof String_) {
            return self::normalise(explode('|', $value->value));
        }

        if (! $value instanceof Array_) {
            $names = FluentRuleChain::ruleNames($value, $schemaVariables);

            return $names === null ? null : self::normalise($names);
        }

        $names = [];

        foreach ($value->items as $item) {
            // A FluentRule chain here is refused like any other non-string item: inside an array the
            // builder runs as its own rule object, so its `sometimes()` governs only itself, and one
            // flat name set would misread the field.
            if (! $item->value instanceof String_) {
                return null;
            }

            $names[] = $item->value->value;
        }

        return self::normalise($names);
    }

    /**
     * @param  list<string>  $rules
     * @return list<string>
     */
    private static function normalise(array $rules): array
    {
        return array_values(array_unique(array_map(
            static fn (string $rule): string => strtolower(trim(explode(':', $rule, 2)[0])),
            $rules,
        )));
    }
}
