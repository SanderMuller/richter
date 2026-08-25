<?php declare(strict_types=1);

namespace SanderMuller\Richter\Changes;

use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\NodeFinder;
use SanderMuller\Richter\Analysis\Hazards\ModelTables;
use SanderMuller\Richter\Support\AppFiles;
use Throwable;

/**
 * Recognises the one property addition that is not additive: a model declaring, for the first time, a
 * property Eloquent itself reads.
 *
 * A new member normally has no callers at base, which is why {@see ChangedSymbols::changeTypeFor()}
 * reads it as additive and seeds nothing. A framework-behaviour property breaks that premise. Adding
 * `protected $table = 'legacy_articles';` to an existing model redirects every query untouched code
 * already makes; `$perPage` repaginates existing callers, `$timestamps = false` stops writes those
 * callers depend on. The class gains no member node either way — a property is unresolvable
 * ({@see MemberResolver}) — so the honest classification is the one a *changed* property already gets:
 * a coarse class seed, named in the low-confidence reason as `(Class::table, property)`.
 *
 * Deliberately an explicit list, not reflection over whatever the installed `Model` declares. The
 * package supports more than one Laravel major, and a list answers the same on each. Left out are the
 * caches Eloquent writes at runtime — `$original`, `$relations`, `$changes`, the cast caches — which an
 * application does not declare. Left in are a few properties it does not usually declare either
 * (`$exists`, `$preventsLazyLoading`): declaring one still changes what existing callers get, and the
 * cost of listing it is one coarse seed nobody triggers.
 *
 * The other base classes have the same shape — `Command::$signature`, `Job::$tries`,
 * `FormRequest::$redirect` — and each needs its own list to stay this precise; models come first
 * because they carry the reach.
 *
 * @internal
 */
final class ModelBehaviourProperty
{
    /** The base class every Eloquent model's parent chain reaches. */
    private const string ELOQUENT_MODEL = 'Illuminate\\Database\\Eloquent\\Model';

    /**
     * Eloquent properties whose first declaration changes what existing callers get.
     *
     * `fillable`/`casts`/`guarded` sit here for the *first* declaration only, which is a different
     * case from the one {@see EloquentConfig} exempts: adding a column to a list that already exists
     * is additive, while declaring `$fillable` where none stood narrows mass assignment for every
     * existing `create()` call.
     *
     * Every name is a property `Illuminate\Database\Eloquent\Model` itself declares, checked by
     * reflection rather than recalled. `snakeAttributes` is the one static among them, and a static
     * declaration changes the same callers an instance one does.
     *
     * @var list<string>
     */
    private const array NAMES = [
        'appends', 'attributes', 'casts', 'connection', 'dateFormat', 'dispatchesEvents',
        'escapeWhenCastingToString', 'exists', 'fillable', 'guarded', 'hidden', 'incrementing', 'keyType',
        'observables', 'perPage', 'preventsLazyLoading', 'primaryKey', 'snakeAttributes', 'table',
        'timestamps', 'touches', 'usesUniqueIds', 'visible', 'with', 'withCount',
    ];

    /**
     * How a member that did not exist at base classifies: MODIFIED where the declaration changes what
     * untouched callers get, ADDED otherwise. A new file (`$baseSrc` null) has no such callers, so it
     * is always ADDED.
     */
    public static function additionChangeType(string $name, string $kind, string $headSrc, ?string $baseSrc, string $fqcn): string
    {
        if ($baseSrc === null || $kind !== MemberChange::KIND_PROPERTY || ! in_array($name, self::NAMES, strict: true)) {
            return MemberChange::CHANGE_ADDED;
        }

        return self::isModel($headSrc, $fqcn) ? MemberChange::CHANGE_MODIFIED : MemberChange::CHANGE_ADDED;
    }

    /**
     * Whether the changed class is an Eloquent model.
     *
     * The analysed source decides, not reflection on the class of the same name. `resolveWithScope()`
     * replays a historical range by reading both sides from git, so the working tree can hold a
     * different revision of this very class — one that gained or lost `extends Model` since. Reflection
     * would answer for that other revision. It is still the fallback for a source this cannot parse,
     * where a stale answer beats no answer.
     *
     * The parent chain is the one part the source cannot settle alone: `Article extends BaseModel` says
     * nothing about `BaseModel`. Reflection answers there where the base class loads, and an ancestry
     * that leaves both is ACCEPTED — the same rule {@see ModelTables::isModel()} holds. Its one known
     * gap is a replay whose base class has since stopped being a model: the loaded parent then answers
     * no for a revision where the answer was yes, and the addition reads additive. Accepting every
     * unproven parent instead would coarse-seed an `InstallCommand extends Command` that declares
     * `$hidden` — a common shape traded for a rare one, so the narrower answer stands. A base model
     * parked outside the scanned set is an ordinary layout, and the uncertainty costs one coarse seed
     * rather than a missed one. A class declaring no parent is not a model, so a plain DTO with a
     * `$table` property stays additive.
     */
    private static function isModel(string $headSrc, string $fqcn): bool
    {
        $ast = AppFiles::parseResolved($headSrc);

        if ($ast === null) {
            return self::loadedAncestry($fqcn) ?? false;
        }

        $class = self::changedClass(new NodeFinder()->findInstanceOf($ast, Class_::class), $fqcn);

        if (! $class?->extends instanceof Name) {
            return false;
        }

        $parent = AppFiles::resolveName($class->extends);

        return $parent === self::ELOQUENT_MODEL || self::loadedAncestry($parent) !== false;
    }

    /**
     * The file's own class, by name. A model file can hold more than one `Class_` node — an anonymous
     * cast or rule class inside a method is one — and reading "not exactly one class" as "not a model"
     * would miss the very callers this exists to find. Where no declared name matches the path (a
     * PSR-4 mismatch), a lone named class still answers; anything more ambiguous than that does not.
     *
     * @param  array<Class_>  $classes
     */
    private static function changedClass(array $classes, string $fqcn): ?Class_
    {
        $named = array_values(array_filter($classes, static fn (Class_ $class): bool => $class->namespacedName instanceof Name));

        $matching = array_values(array_filter($named, static fn (Class_ $class): bool => $class->namespacedName?->toString() === $fqcn));

        if (count($matching) === 1) {
            return $matching[0];
        }

        return count($named) === 1 ? $named[0] : null;
    }

    /** Null when the class does not load, so the caller falls back to the source. */
    private static function loadedAncestry(string $fqcn): ?bool
    {
        try {
            return class_exists($fqcn) ? is_subclass_of($fqcn, self::ELOQUENT_MODEL) : null;
        } catch (Throwable) {
            return null;
        }
    }
}
