<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis\Hazards;

use Illuminate\Support\Str;
use PhpParser\Node\PropertyItem;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Property;
use PhpParser\NodeFinder;
use SanderMuller\Richter\Changes\ChangedSymbols;
use SanderMuller\Richter\Graph\GraphSplitter;
use SanderMuller\Richter\Support\AppFiles;
use SanderMuller\Richter\Tracers\EagerLoadStringChecker;

/**
 * The table a model maps to, inverted: given `articles`, which model owns it. {@see MigrationHazards}
 * needs it to give a migration's hazard a member the reach lane can place, because a migration names
 * a table and the graph is keyed by class.
 *
 * Resolved from disk rather than from the graph. Laravel Brain already derives each model's table and
 * {@see GraphSplitter} puts it on the `model::` node as `erd.table`, but a
 * hazard is emitted inside `ChangedSymbols::classifyFile()`, which is static and holds no graph. A
 * scan of `app/Models` costs one walk per run and needs no plumbing; reading `erd` is the better
 * answer only once a graph-stage consumer exists.
 *
 * The derivation matches Eloquent's own: an explicit `$table` wins, otherwise the snake-cased plural
 * of the short class name. A `$table` declared with a value this cannot read resolves to nothing rather
 * than falling back to the convention the declaration overrides. `Str::pluralStudly()` is the same call Eloquent makes, so an irregular
 * plural (`Person` → `people`) resolves the way the framework resolves it.
 *
 * The scan reads the WORKING TREE, including when a run replays a historical `$head` ref. That is
 * deliberate: the member has to name a node in the graph the reach lane walks, and that graph is built
 * from the working tree. Resolving the model from the replayed ref could name a class the graph does
 * not hold, which grades `no-known-path` on a model richter can actually see.
 *
 * An abstract class claims no table. It maps no rows, and letting it claim the one its subclasses
 * inherit would poison that table as ambiguous.
 *
 * No-guess, like every other hazard collaborator: two models claiming one table is an ambiguity this
 * refuses rather than picks a side on, and an unparseable model contributes nothing.
 *
 * Memoized per run and flushed by {@see ChangedSymbols::resolve()}, the same discipline
 * {@see EagerLoadStringChecker} holds — one scan per invocation, and a long-lived process never
 * answers for the next checkout with the previous one's models.
 *
 * @internal
 */
final class ModelTables
{
    /** The base class every Eloquent model's parent chain reaches. */
    private const string ELOQUENT_MODEL = 'Illuminate\\Database\\Eloquent\\Model';

    /** @var array<string, string|null>|null table => model FQCN, null where two models claim it */
    private static ?array $byTable = null;

    /** The model FQCN owning `$table`, or null when none does or more than one does. */
    public static function modelFor(string $table, ?string $projectRoot = null): ?string
    {
        self::$byTable ??= self::scan($projectRoot);

        return self::$byTable[$table] ?? null;
    }

    /** Drops the memo so the next run rescans. Called once per {@see ChangedSymbols::resolve()}. */
    public static function flush(): void
    {
        self::$byTable = null;
    }

    /**
     * @return array<string, string|null>
     */
    private static function scan(?string $projectRoot): array
    {
        $projectRoot = rtrim($projectRoot ?? base_path(), '/');

        // Read every class first: a model's parent chain may run through another class in the same
        // directory, and the ancestry check needs the whole set before it can answer for any one.
        $declarations = [];

        foreach (AppFiles::phpClasses("{$projectRoot}/app/Models", $projectRoot) as $class) {
            $declarations[$class['fqcn']] = self::declarationIn((string) file_get_contents($class['path']));
        }

        $byTable = [];

        foreach (array_keys($declarations) as $fqcn) {
            $table = self::tableOf($fqcn, $declarations);

            if ($table === null) {
                continue;
            }

            // Two models on one table cannot be told apart from the table name alone, so the entry is
            // poisoned rather than resolved to whichever was scanned first.
            $byTable[$table] = array_key_exists($table, $byTable) ? null : $fqcn;
        }

        return $byTable;
    }

    /**
     * The one class a file declares, reduced to what table ownership needs. Null when the file declares
     * no class or more than one — the same ambiguity refusal.
     *
     * @return array{parent: string|null, table: array{table: string|null}|null, abstract: bool}|null
     */
    private static function declarationIn(string $source): ?array
    {
        $ast = AppFiles::parseResolved($source);

        if ($ast === null) {
            return null;
        }

        $classes = new NodeFinder()->findInstanceOf($ast, Class_::class);

        if (count($classes) !== 1) {
            return null;
        }

        return [
            'parent' => $classes[0]->extends === null ? null : AppFiles::resolveName($classes[0]->extends),
            'table' => self::declaredTable($classes[0]),
            'abstract' => $classes[0]->isAbstract(),
        ];
    }

    /**
     * The table a scanned class owns, or null when it is not an Eloquent model at all. A model usually
     * extends the framework's `Model` directly, and a project base model puts one or more of its own
     * classes in between, so the parent chain is followed through the scanned set. A helper or DTO
     * parked under `app/Models` extends none of them and owns no table — assigning it one would poison
     * a real model's table as ambiguous.
     *
     * @param  array<string, array{parent: string|null, table: array{table: string|null}|null, abstract: bool}|null>  $declarations
     */
    private static function tableOf(string $fqcn, array $declarations): ?string
    {
        $declaration = $declarations[$fqcn] ?? null;

        // An abstract base model maps no rows of its own. Letting it claim the table its subclasses
        // inherit would poison that table as ambiguous and cost every one of them its reach.
        if ($declaration === null || $declaration['abstract'] || ! self::isModel($fqcn, $declarations)) {
            return null;
        }

        $declared = self::nearestDeclaredTable($fqcn, $declarations);

        // A model that DECLARES `$table` has said the convention does not apply to it. If the value
        // cannot be read — `protected $table = Tables::ARTICLES` — falling back to the convention would
        // claim a table the model does not map to, and hand a migration for it the wrong model.
        return $declared === null ? self::conventionalTable($fqcn) : $declared['table'];
    }

    /**
     * The nearest `$table` declaration in the class's own chain, or null when nothing in it declares
     * one. Stops at the first declaration, the way PHP resolves an inherited property.
     *
     * @param  array<string, array{parent: string|null, table: array{table: string|null}|null, abstract: bool}|null>  $declarations
     * @return array{table: string|null}|null
     */
    private static function nearestDeclaredTable(string $fqcn, array $declarations): ?array
    {
        $seen = [];

        while (! isset($seen[$fqcn]) && isset($declarations[$fqcn])) {
            $seen[$fqcn] = true;
            // `isset` already skipped an unreadable class: it has no declaration to inherit, and the
            // chain ends there rather than reaching past it.
            $declaration = $declarations[$fqcn];

            if ($declaration['table'] !== null) {
                return $declaration['table'];
            }

            $fqcn = $declaration['parent'] ?? '';
        }

        return null;
    }

    /**
     * Whether the class is an Eloquent model. Reflection answers exactly where the class loads, which
     * is the case richter's own autoloading of the analysed checkout usually gives it. Where it does
     * not load, the scanned parent chain answers instead, and a cycle terminates on the seen-set.
     *
     * A chain that leaves the scanned set is ACCEPTED. A base model parked outside `app/Models` —
     * `App\Models\Article extends App\Support\BaseModel` — is an ordinary layout, and refusing it
     * would cost every model behind it the reach this class exists to give. Refusing only what is
     * provably not a model keeps the cost on the rarer shape: a helper extending an unknown class.
     *
     * @param  array<string, array{parent: string|null, table: array{table: string|null}|null, abstract: bool}|null>  $declarations
     */
    private static function isModel(string $fqcn, array $declarations): bool
    {
        if (class_exists($fqcn)) {
            return is_subclass_of($fqcn, self::ELOQUENT_MODEL);
        }

        $seen = [];

        while (! isset($seen[$fqcn])) {
            $seen[$fqcn] = true;
            $parent = ($declarations[$fqcn] ?? null)['parent'] ?? null;

            // Nothing to climb: a class declaring no parent is not a model, and neither is one whose
            // file this could not read.
            if ($parent === null) {
                return false;
            }

            if ($parent === self::ELOQUENT_MODEL) {
                return true;
            }

            if (! array_key_exists($parent, $declarations)) {
                return true;
            }

            $fqcn = $parent;
        }

        return false;
    }

    /**
     * Null when the class declares no `$table` property at all. Otherwise the declared value, which is
     * itself null when it is not a plain string.
     *
     * @return array{table: string|null}|null
     */
    private static function declaredTable(Class_ $class): ?array
    {
        foreach (new NodeFinder()->findInstanceOf($class, Property::class) as $property) {
            foreach ($property->props as $prop) {
                if ($prop instanceof PropertyItem && $prop->name->toString() === 'table') {
                    return ['table' => $prop->default instanceof String_ ? $prop->default->value : null];
                }
            }
        }

        return null;
    }

    /** Eloquent's fallback: the snake-cased plural of the short class name. */
    private static function conventionalTable(string $fqcn): string
    {
        $separator = strrpos($fqcn, '\\');

        return Str::snake(Str::pluralStudly($separator === false ? $fqcn : substr($fqcn, $separator + 1)));
    }
}
