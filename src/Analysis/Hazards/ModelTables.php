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
 * of the short class name. `Str::pluralStudly()` is the same call Eloquent makes, so an irregular
 * plural (`Person` → `people`) resolves the way the framework resolves it.
 *
 * The scan reads the WORKING TREE, including when a run replays a historical `$head` ref. That is
 * deliberate: the member has to name a node in the graph the reach lane walks, and that graph is built
 * from the working tree. Resolving the model from the replayed ref could name a class the graph does
 * not hold, which grades `no-known-path` on a model richter can actually see.
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

        $byTable = [];

        foreach (AppFiles::phpClasses("{$projectRoot}/app/Models", $projectRoot) as $class) {
            $table = self::tableOf((string) file_get_contents($class['path']), $class['fqcn']);

            if ($table === null) {
                continue;
            }

            // Two models on one table cannot be told apart from the table name alone, so the entry is
            // poisoned rather than resolved to whichever was scanned first.
            $byTable[$table] = array_key_exists($table, $byTable) ? null : $class['fqcn'];
        }

        return $byTable;
    }

    /** Null when the file declares no class, or declares more than one — the same ambiguity refusal. */
    private static function tableOf(string $source, string $fqcn): ?string
    {
        $ast = AppFiles::parse($source);

        if ($ast === null) {
            return null;
        }

        $classes = new NodeFinder()->findInstanceOf($ast, Class_::class);

        if (count($classes) !== 1) {
            return null;
        }

        return self::declaredTable($classes[0]) ?? self::conventionalTable($fqcn);
    }

    /** The `protected $table = '...'` value, or null when absent or not a plain string. */
    private static function declaredTable(Class_ $class): ?string
    {
        foreach (new NodeFinder()->findInstanceOf($class, Property::class) as $property) {
            foreach ($property->props as $prop) {
                if ($prop instanceof PropertyItem && $prop->name->toString() === 'table' && $prop->default instanceof String_) {
                    return $prop->default->value;
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
