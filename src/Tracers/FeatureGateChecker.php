<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tracers;

use BackedEnum;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeFinder;
use SanderMuller\Richter\Support\AppFiles;
use Throwable;

/**
 * Notes the Pennant feature-flag checks in a changed source, so the report can say "this change
 * sits behind flag X" — a smaller live blast radius than the raw graph suggests when the flag is
 * off. Deliberately local: only checks IN the changed source itself are noted, never "somewhere on
 * the path" gating — path-sensitive claims are exactly the over-claiming this tool avoids.
 */
final class FeatureGateChecker
{
    /** The Pennant Feature-facade methods whose first argument names the flag being checked. */
    private const array FEATURE_METHODS = ['active', 'inactive', 'when', 'unless', 'allAreActive', 'someAreActive', 'activeForEveryone'];

    /**
     * @param  list<array{int, int}>|null  $lineRanges  restrict to checks whose call starts inside
     *   one of these [start, end] line spans — the CHANGED members — so an untouched sibling
     *   method's flag check never implies the change itself is gated; null scans the whole source
     * @return list<string>
     */
    public function findingsFor(string $source, ?array $lineRanges = null): array
    {
        $ast = AppFiles::parseResolved($source);

        if ($ast === null) {
            return [];
        }

        $flags = [];

        foreach (new NodeFinder()->findInstanceOf($ast, CallLike::class) as $call) {
            if (! $call instanceof StaticCall && ! $call instanceof MethodCall) {
                continue;
            }

            if (! $call->name instanceof Identifier || ! in_array($call->name->toString(), self::FEATURE_METHODS, strict: true)) {
                continue;
            }

            if ($lineRanges !== null && ! self::withinRanges($call->getStartLine(), $lineRanges)) {
                continue;
            }

            if (! self::onFeatureFacade($call)) {
                continue;
            }

            foreach (self::flagNames($call->args[0]->value ?? null) as $flag) {
                $flags[$flag] = true;
            }
        }

        return self::findings(array_keys($flags));
    }

    /**
     * A direct `Feature::active(...)` static call, or the fluent scoped form
     * `Feature::for($scope)->active(...)` — a method call whose receiver chain roots in the facade.
     */
    private static function onFeatureFacade(StaticCall|MethodCall $call): bool
    {
        // Walk fluent receivers (`Feature::for($u)->when(...)`) down to the rooting static call.
        $root = $call;

        while ($root instanceof MethodCall) {
            $root = $root->var;
        }

        if (! $root instanceof StaticCall || ! $root->class instanceof Name) {
            return false;
        }

        // Exact facade match (import-resolved), plus the bare name for unresolvable sources.
        $class = AppFiles::resolveName($root->class);

        return $class === 'Laravel\\Pennant\\Feature' || $class === 'Feature';
    }

    /** @param  list<array{int, int}>  $ranges */
    private static function withinRanges(int $line, array $ranges): bool
    {
        return array_any($ranges, static fn (array $range): bool => $line >= $range[0] && $line <= $range[1]);
    }

    /**
     * `@feature('x')` in a changed Blade view — the same locality rule, string-literal flags only.
     *
     * @return list<string>
     */
    public function bladeFindingsFor(string $source): array
    {
        if (preg_match_all('/@feature\(\s*[\'"]([^\'"]+)[\'"]/', $source, $matches) === 0) {
            return [];
        }

        return self::findings(array_values(array_unique($matches[1])));
    }

    /**
     * The flag(s) a check names — a single string/enum argument, or the array form the aggregate
     * methods take (`Feature::allAreActive(['a', 'b'])`).
     *
     * @return list<string>
     */
    private static function flagNames(mixed $argument): array
    {
        if ($argument instanceof Array_) {
            $flags = [];

            foreach ($argument->items as $item) {
                $flags = [...$flags, ...self::flagNames($item->value)];
            }

            return $flags;
        }

        $flag = self::flagName($argument);

        return $flag === null ? [] : [$flag];
    }

    /**
     * The flag a check names: a string literal, or an enum/constant reference — resolved to its
     * backing string when the class loads, kept verbatim (`FeatureFlag::X`) when it doesn't, so an
     * unresolvable flag still reads as gated rather than disappearing.
     */
    private static function flagName(mixed $argument): ?string
    {
        if ($argument instanceof String_) {
            return $argument->value;
        }

        if (! $argument instanceof ClassConstFetch || ! $argument->class instanceof Name || ! $argument->name instanceof Identifier) {
            return null;
        }

        $class = AppFiles::resolveName($argument->class);
        $case = $argument->name->toString();

        try {
            $value = constant("{$class}::{$case}");
        } catch (Throwable) {
            $value = null;
        }

        if ($value instanceof BackedEnum && is_string($value->value)) {
            return $value->value;
        }

        if (is_string($value)) {
            return $value;
        }

        $separator = strrpos($class, '\\');
        $basename = $separator === false ? $class : substr($class, $separator + 1);

        return "{$basename}::{$case}";
    }

    /**
     * @param  list<string>  $flags
     * @return list<string>
     */
    private static function findings(array $flags): array
    {
        sort($flags);

        return array_map(
            static fn (string $flag): string => "checks feature flag '{$flag}' — behaviour behind this flag only runs where it is active",
            $flags,
        );
    }
}
