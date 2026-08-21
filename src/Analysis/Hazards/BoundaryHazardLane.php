<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis\Hazards;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Node\VariadicPlaceholder;
use SanderMuller\Richter\Analysis\Hazard;
use SanderMuller\Richter\Support\AppFiles;

/**
 * The two edges of the application where a change lets something through that did not get through
 * before: input that is no longer validated, and a queued payload whose in-flight jobs were
 * serialised against the old constructor.
 *
 * A field removed from a rules array entirely is the parity lane's business (§4.6) and is not
 * repeated here — this lane only looks at a field BOTH sides still validate, and only at the
 * constraints it lost.
 *
 * The queued half is decided from the parsed AST and the FQCN string, never by reflection. The base
 * side of a diff is a git blob: the base class is not autoloadable, and on a deletion neither side
 * is, so `is_subclass_of()` could not answer for the side that matters. A class whose `ShouldQueue`
 * arrives through a parent is beyond what an AST can prove, and the lane stays silent there rather
 * than guessing — a constructor change on it still surfaces through the contract lane at tier 1.
 */
final class BoundaryHazardLane implements HazardLane
{
    /**
     * Constraints whose absence lets a previously-rejected value through. Kept to rules with exactly
     * that meaning: a formatting or normalising rule dropping out is not a boundary hazard, and
     * listing it would make the lane fire on cosmetic rule edits.
     *
     * @var list<string>
     */
    private const array GUARDING_RULES = [
        'required', 'confirmed', 'max', 'min', 'size', 'in', 'exists', 'unique', 'mimes', 'image', 'url', 'email', 'uuid',
    ];

    public static function for(string $file, string $headSrc, string $baseSrc): array
    {
        if (! str_starts_with($file, 'app/')) {
            return [[], []];
        }

        return [[...self::validationLoosened($headSrc, $baseSrc), ...self::queuedPayloadChanged($headSrc, $baseSrc)], []];
    }

    /**
     * @return list<Hazard>
     */
    private static function validationLoosened(string $headSrc, string $baseSrc): array
    {
        $head = self::rulesByMember($headSrc);
        $base = self::rulesByMember($baseSrc);
        $hazards = [];

        foreach ($base as $member => $baseFields) {
            $headFields = $head[$member] ?? null;

            if ($headFields === null) {
                continue;
            }

            foreach ($baseFields as $field => $baseRules) {
                $headRules = $headFields[$field] ?? null;

                // Absent: the field went away entirely, which the parity lane reports. Null: the head
                // side of this field could not be enumerated, and a removal cannot be claimed from a
                // side that was never read.
                if ($headRules === null) {
                    continue;
                }

                // A base side that could not be enumerated has nothing to have lost, the same refusal
                // the head side gets above.
                if ($baseRules === null) {
                    continue;
                }

                $lost = array_values(array_intersect(array_diff($baseRules, $headRules), self::GUARDING_RULES));

                if ($lost !== []) {
                    sort($lost);
                    $hazards[] = new Hazard('boundary', 2, 'CWE-20', $member, "`{$field}` no longer validates " . implode(', ', $lost), ignoreKey: "{$member}::{$field}");
                }
            }
        }

        return $hazards;
    }

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
    private static function rulesByMember(string $source): array
    {
        $byMember = [];

        foreach (HazardSource::methods($source) as $member => $method) {
            $arrays = self::validationArrays($method);

            if ($arrays === []) {
                continue;
            }

            $fields = [];

            foreach ($arrays as $array) {
                foreach ($array->items as $item) {
                    if (! $item->key instanceof String_) {
                        continue;
                    }

                    $fields[$item->key->value] = self::ruleNames($item->value);
                }
            }

            if ($fields !== []) {
                $byMember[$member] = $fields;
            }
        }

        return $byMember;
    }

    /**
     * The rules arrays inside one method: a `rules()` that returns an array literal, and every
     * `validate([...])` call written out at the call site. A `validate($rules)` passing a variable
     * contributes nothing — its fields are opaque, so the method's readable arrays stand alone.
     *
     * @return list<Array_>
     */
    private static function validationArrays(ClassMethod $method): array
    {
        $arrays = [];

        if ($method->name->toString() === 'rules') {
            $return = ($method->stmts ?? [])[0] ?? null;

            if (count($method->stmts ?? []) === 1 && $return instanceof Return_ && $return->expr instanceof Array_) {
                $arrays[] = $return->expr;
            }
        }

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
     * @param  array<Arg|VariadicPlaceholder>  $args
     */
    private static function arrayArgument(array $args, int $position): ?Array_
    {
        if (array_any($args, static fn (Arg|VariadicPlaceholder $arg): bool => $arg instanceof Arg && $arg->name instanceof Identifier)) {
            return null;
        }

        $arg = $args[$position] ?? null;

        return $arg instanceof Arg && $arg->value instanceof Array_ ? $arg->value : null;
    }

    /**
     * A field's rule NAMES — `max:255` reduces to `max`, because the lane asks whether the constraint
     * is still there, not what its bound is. Null when any element cannot be read as a literal: a
     * `Rule::unique(…)` object or a spread makes the list unknowable, and comparing a partial list
     * against a full one would invent removals.
     *
     * @return list<string>|null
     */
    private static function ruleNames(Node $value): ?array
    {
        if ($value instanceof String_) {
            return self::normalise(explode('|', $value->value));
        }

        if (! $value instanceof Array_) {
            return null;
        }

        $names = [];

        foreach ($value->items as $item) {
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

    /**
     * A queued class whose constructor signature moved. Jobs already on the queue were serialised
     * against the old one and are deserialised against the new: a parameter that changed name, type,
     * order or default is a payload mismatch the moment the release lands, not on the next call.
     *
     * `DispatchTarget::matches()` is deliberately not the predicate — it also accepts a sync-dispatched
     * command with no in-flight state, whose constructor change is the contract lane's business.
     *
     * @return list<Hazard>
     */
    private static function queuedPayloadChanged(string $headSrc, string $baseSrc): array
    {
        $headClasses = HazardSource::classLikes($headSrc);
        $baseClasses = HazardSource::classLikes($baseSrc);
        $hazards = [];

        foreach ($baseClasses as $fqcn => $baseClass) {
            $headClass = $headClasses[$fqcn] ?? null;

            if ($headClass === null || (! self::isQueued($fqcn, $baseClass) && ! self::isQueued($fqcn, $headClass))) {
                continue;
            }

            $baseConstructor = $baseClass->getMethod('__construct');
            $headConstructor = $headClass->getMethod('__construct');

            if (! $baseConstructor instanceof ClassMethod || ! $headConstructor instanceof ClassMethod) {
                continue;
            }

            $before = HazardSource::signature($baseConstructor);
            $after = HazardSource::signature($headConstructor);

            if ($before !== $after) {
                $hazards[] = new Hazard('boundary', 2, null, "{$fqcn}::__construct", "the queued payload changed from ({$before}) to ({$after})");
            }
        }

        return $hazards;
    }

    /**
     * Queued by declaration: a `\Jobs\` FQCN, or an `implements` list naming `ShouldQueue`. Public so
     * the contract lane can defer a queued constructor to this lane rather than reporting the same
     * change twice at two tiers.
     */
    public static function isQueued(string $fqcn, ClassLike $classLike): bool
    {
        if (str_contains($fqcn, '\\Jobs\\')) {
            return true;
        }

        if (! $classLike instanceof Class_) {
            return false;
        }

        return array_any($classLike->implements, static fn (Name $name): bool => str_ends_with($name->toString(), '\\ShouldQueue') || $name->toString() === 'ShouldQueue');
    }
}
