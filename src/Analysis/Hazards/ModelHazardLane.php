<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis\Hazards;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Identifier;
use PhpParser\Node\PropertyItem;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Property;
use PhpParser\NodeFinder;
use SanderMuller\Richter\Analysis\Hazard;
use SanderMuller\Richter\Changes\EloquentConfig;
use SanderMuller\Richter\Changes\MemberChange;
use SanderMuller\Richter\Support\AppFiles;

/**
 * A model's write and read surface. `$hidden` narrowed is tier 3 — a field that was never serialised
 * now is, which is a disclosure, not a contract change. Everything else here is tier 2.
 *
 * Comparison is by AST-canonical key and value ({@see EloquentConfig::configMap()}), never by text,
 * so reordering or reformatting an array draws nothing. Anything non-enumerable — a spread, an
 * unresolvable constant, a member two classes in the file declare — yields a null map and the lane
 * stays silent, matching that class's existing no-guess rule.
 */
final class ModelHazardLane implements HazardLane
{
    /** Named as strings: Laravel 12, which this package supports, does not ship them. */
    private const array HIDING_ATTRIBUTES = [
        'Illuminate\Database\Eloquent\Attributes\Hidden',
        'Illuminate\Database\Eloquent\Attributes\Visible',
    ];

    private const array HIDING_OVERRIDES = ['getHidden', 'getVisible'];

    private const array HIDING_CALLS = ['setHidden', 'makeHidden', 'makeHiddenIf', 'mergeHidden', 'setVisible'];

    public static function for(string $file, string $headSrc, string $baseSrc): array
    {
        if (! str_starts_with($file, 'app/Models/')) {
            return [[], []];
        }

        $fqcn = array_key_first(HazardSource::classLikes($headSrc)) ?? '';

        if ($fqcn === '') {
            return [[], []];
        }

        return [[
            ...self::widened($fqcn, $headSrc, $baseSrc, 'fillable', 'CWE-915', '$fillable gained'),
            ...self::narrowed($fqcn, $headSrc, $baseSrc, 'guarded', 2, 'CWE-915', '$guarded no longer blocks'),
            ...self::narrowed($fqcn, $headSrc, $baseSrc, 'hidden', 3, 'CWE-200', '$hidden no longer hides'),
            ...self::hiddenDeleted($file, $headSrc, $baseSrc),
            ...self::castsChanged($fqcn, $headSrc, $baseSrc),
        ], []];
    }

    /**
     * `$fillable` gaining an entry widens what a mass assignment may write. This is the one place the
     * hazard family contradicts an ADDITION being harmless: `EloquentConfig` classifies an
     * addition-only `$fillable` edit as additive so it seeds nothing, and it stays additive — the
     * hazard rides alongside rather than changing the seeding.
     *
     * @return list<Hazard>
     */
    private static function widened(string $fqcn, string $headSrc, string $baseSrc, string $name, ?string $cwe, string $label): array
    {
        [$head, $base] = self::maps($headSrc, $baseSrc, $name, MemberChange::KIND_PROPERTY);

        if ($head === null || $base === null) {
            return [];
        }

        $gained = array_values(array_diff(array_keys($head), array_keys($base)));

        return $gained === []
            ? []
            : [new Hazard('model', 2, $cwe, "{$fqcn}::\${$name}", "{$label} " . self::readable($gained), ignoreKey: "{$fqcn}::\${$name}")];
    }

    /**
     * A block-list or hide-list losing entries. `$guarded = []` is the widest possible
     * mass-assignment surface and is reported even though the ARRAY did not lose a named entry — it
     * lost every entry it had.
     *
     * @return list<Hazard>
     */
    private static function narrowed(string $fqcn, string $headSrc, string $baseSrc, string $name, int $tier, ?string $cwe, string $label): array
    {
        [$head, $base] = self::maps($headSrc, $baseSrc, $name, MemberChange::KIND_PROPERTY);

        if ($head === null || $base === null) {
            return [];
        }

        $lost = array_values(array_diff(array_keys($base), array_keys($head)));

        return $lost === []
            ? []
            : [new Hazard('model', $tier, $cwe, "{$fqcn}::\${$name}", "{$label} " . self::readable($lost), ignoreKey: "{$fqcn}::\${$name}")];
    }

    /**
     * `$hidden` deleted whole discloses every column it named, where {@see narrowed()} has no head list
     * to compare. The same hazard as one entry leaving it, so the same tier.
     *
     * `$fillable` and `$guarded` have no counterpart: without either, Eloquent guards every column.
     *
     * @return list<Hazard>
     */
    private static function hiddenDeleted(string $file, string $headSrc, string $baseSrc): array
    {
        $baseClasses = HazardSource::classLikes($baseSrc);
        $hazards = [];

        // Every class in the file, because the contract lane leaves every one of them to this lane.
        foreach (HazardSource::classLikes($headSrc) as $fqcn => $head) {
            $base = $baseClasses[$fqcn] ?? null;

            if ($base instanceof ClassLike && self::losesHidden($file, $head, $base)) {
                $hazards[] = new Hazard('model', 3, 'CWE-200', "{$fqcn}::\$hidden", '$hidden is gone, and it hid ' . self::readable(self::hiddenColumns($base)), ignoreKey: "{$fqcn}::\$hidden");
            }
        }

        return $hazards;
    }

    /**
     * Whether a surviving model lost a `$hidden` that named columns, with nothing on the head class that
     * could hide them instead: a `$visible` list, the attribute form of either, a trait or a parent the
     * base did not have, or the class's own code ({@see hidesNewlyInCode()}). Where one of those is there, the property is only a member that went away, and
     * the contract lane says so.
     */
    public static function losesHidden(string $file, ClassLike $head, ClassLike $base): bool
    {
        return str_starts_with($file, 'app/Models/')
            && self::hiddenColumns($base) !== []
            && ! $head->getProperty('hidden') instanceof Property
            && ! $head->getProperty('visible') instanceof Property
            && ! self::hidesByAttribute($head)
            && ! self::hidesNewlyInCode($head, $base)
            && HazardSource::parentOf($head) === HazardSource::parentOf($base)
            && array_diff(HazardSource::traitsOf($head), HazardSource::traitsOf($base)) === [];
    }

    /**
     * The columns a class's own `$hidden` names, canonical, or none where the list cannot be read whole.
     *
     * @return list<string>
     */
    private static function hiddenColumns(ClassLike $class): array
    {
        $default = array_find($class->getProperty('hidden')->props ?? [], static fn (PropertyItem $prop): bool => $prop->name->toString() === 'hidden')?->default;

        return $default instanceof Array_ ? array_keys(EloquentConfig::mapOf($default) ?? []) : [];
    }

    private static function hidesByAttribute(ClassLike $class): bool
    {
        foreach ($class->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                if (in_array(AppFiles::resolveName($attribute->name), self::HIDING_ATTRIBUTES, strict: true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** Whether the class now overrides the list's getter, or sets a list in code, where the base did not. */
    private static function hidesNewlyInCode(ClassLike $head, ClassLike $base): bool
    {
        return array_any(self::HIDING_OVERRIDES, static fn (string $method): bool => HazardSource::overridesNewly($method, $head, $base))
            || array_diff(self::hidingCode($head), self::hidingCode($base)) !== [];
    }

    /**
     * Every place the class's own code sets a list that names something, as written.
     *
     * @return list<string>
     */
    private static function hidingCode(ClassLike $class): array
    {
        $written = [];

        foreach (new NodeFinder()->find($class->getMethods(), static fn (Node $node): bool => $node instanceof MethodCall || $node instanceof Assign) as $node) {
            $target = $node instanceof Assign ? $node->var : $node;

            if ((! $target instanceof MethodCall && ! $target instanceof PropertyFetch) || ! HazardSource::isThis($target->var) || ! $target->name instanceof Identifier) {
                continue;
            }

            $value = $node instanceof Assign ? $node->expr : ($target instanceof MethodCall && ($target->args[0] ?? null) instanceof Arg ? $target->args[0]->value : null);
            $names = $node instanceof Assign ? ['hidden', 'visible'] : self::HIDING_CALLS;

            if ($value instanceof Expr && in_array($target->name->toString(), $names, strict: true) && (! $value instanceof Array_ || $value->items !== [])) {
                $written[] = HazardSource::print($node);
            }
        }

        return $written;
    }

    /**
     * A cast changed on a key BOTH sides declare. A key only one side has is an addition or a removal
     * — the parity lane's business and already classified additive — so only a surviving key counts.
     * Both syntaxes are checked, and a model declaring each half in a different syntax is compared
     * within its own syntax rather than across the two.
     *
     * @return list<Hazard>
     */
    private static function castsChanged(string $fqcn, string $headSrc, string $baseSrc): array
    {
        $hazards = [];

        foreach ([[MemberChange::KIND_PROPERTY, '$casts'], [MemberChange::KIND_METHOD, 'casts()']] as [$kind, $label]) {
            [$head, $base] = self::maps($headSrc, $baseSrc, 'casts', $kind);

            if ($head === null || $base === null) {
                continue;
            }

            $changed = array_keys(array_filter(
                $base,
                static fn (string $value, string $key): bool => isset($head[$key]) && $head[$key] !== $value,
                ARRAY_FILTER_USE_BOTH,
            ));

            if ($changed !== []) {
                $hazards[] = new Hazard('model', 2, null, "{$fqcn}::{$label}", "{$label} changed the cast on " . self::readable($changed), ignoreKey: "{$fqcn}::{$label}");
            }
        }

        return $hazards;
    }

    /** @return array{0: array<string, string>|null, 1: array<string, string>|null} */
    private static function maps(string $headSrc, string $baseSrc, string $name, string $kind): array
    {
        return [EloquentConfig::configMap($headSrc, $name, $kind), EloquentConfig::configMap($baseSrc, $name, $kind)];
    }

    /**
     * Canonical keys back to something a reader recognises — `s:title` is how the comparison stores
     * a string, not how it should print.
     *
     * @param  list<string>  $keys
     */
    private static function readable(array $keys): string
    {
        sort($keys);

        return implode(', ', array_map(
            static fn (string $key): string => (string) preg_replace('/^(s|i|d|c|cc):/', '', $key),
            $keys,
        ));
    }
}
