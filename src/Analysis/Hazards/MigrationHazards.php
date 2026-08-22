<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis\Hazards;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use SanderMuller\Richter\Analysis\Hazard;
use SanderMuller\Richter\Analysis\HazardFindings;
use SanderMuller\Richter\Changes\MigrationChanges;
use SanderMuller\Richter\Support\AppFiles;

/**
 * The destructive schema operations a migration's `up()` performs: a dropped table, a dropped column,
 * a renamed column. Each is a hazard whether or not anything still names the column — losing the data
 * is the break, and richter cannot see the rows.
 *
 * **Not a {@see HazardLane}, and it cannot become one.** Every lane compares two sides of one file and
 * {@see HazardLanes::for()} returns early for a new file, but the normal migration diff shape is a new
 * file — editing a migration that already ran is the anti-pattern. A conventional migration also
 * declares no named class (`return new class extends Migration`), so `HazardSource::classLikes()`
 * answers with an empty array and the lane loop treats the file as unparseable. Dispatched by path
 * from {@see MigrationChanges} instead, like a route file.
 *
 * **`up()` only.** A conventional `down()` reverses `up()`, so it holds a `dropColumn` for every
 * column `up()` adds. Reading the whole file would report a destructive operation on every migration
 * ever written, additive ones included.
 *
 * A DELETED migration raises nothing, the same call {@see RouteFileHazards} makes for a route the head
 * no longer declares. Head minus base leaves an empty head with nothing to report, which is the right
 * reading: deleting the file removes the instruction, and rolling an unrun migration back out of a
 * branch is routine. Whether it already ran against a real database is not something richter can see.
 *
 * **Head minus base**, not head alone. A new file has no base, so every operation in it reports; a
 * migration edited for an unrelated reason does not re-report the operations it already held.
 *
 * Only operations written directly on the `Schema` facade are read. A connection-scoped chain
 * (`Schema::connection('reporting')->drop('posts')`) is a method call on the facade's return value, and
 * reading it would mean following what that call resolves to. It draws nothing rather than a guess.
 *
 * A base side that does not parse contributes no operations, so every operation in the head reports as
 * new. That over-reports rather than under-reports, which is the only direction a destructive-change
 * reader may fail in.
 *
 * `removedTokens` stays empty deliberately. The moved-not-removed index {@see HazardFindings} owns is
 * guard-token space, and a column named `auth` or `role` entering it would collide across lanes. A
 * column dropped here and added back by another migration in the same diff is the additive half's
 * problem, and nothing populates column additions yet.
 *
 * @internal
 */
final class MigrationHazards
{
    /** The facade every schema operation this reads is written on. */
    private const string SCHEMA_FACADE = 'Illuminate\\Support\\Facades\\Schema';

    /** Facade methods that drop a whole table. */
    private const array TABLE_DROPS = ['drop', 'dropIfExists'];

    /** Facade methods whose second argument is a blueprint closure worth walking. */
    private const array BLUEPRINT_CALLS = ['table', 'create'];

    /**
     * Blueprint helpers that drop a fixed set of columns. Read from the framework's own bodies, which
     * each forward to `dropColumn()` with these names — a helper is a column drop written shorter, and
     * matching only `dropColumn` would miss every one of them in silence.
     *
     * @var array<string, list<string>>
     */
    private const array FIXED_COLUMN_DROPS = [
        'dropTimestamps' => ['created_at', 'updated_at'],
        'dropTimestampsTz' => ['created_at', 'updated_at'],
        'dropRememberToken' => ['remember_token'],
    ];

    /**
     * Blueprint helpers dropping one column, named by their first argument. `dropSoftDeletes()` takes
     * the column it drops and defaults it, so an omitted argument is the default rather than unreadable.
     *
     * @var array<string, string|null>  method => the default when no argument is given
     */
    private const array NAMED_COLUMN_DROPS = [
        'dropSoftDeletes' => 'deleted_at',
        'dropSoftDeletesTz' => 'deleted_at',
        'dropConstrainedForeignId' => null,
    ];

    /**
     * @param  string|null  $baseSrc  null for a new migration — then every operation in `up()` is new
     * @return array{0: list<Hazard>, 1: list<string>, 2: list<string>} hazards, added tokens, findings
     */
    public static function for(string $file, string $headSrc, ?string $baseSrc, ?string $projectRoot = null): array
    {
        $head = self::operations($headSrc);

        if ($head === []) {
            return [[], [], []];
        }

        $base = $baseSrc === null ? [] : self::operations($baseSrc);

        $hazards = [];

        foreach ($head as $key => $operation) {
            if (array_key_exists($key, $base)) {
                continue;
            }

            $hazards[] = self::hazard($operation, $projectRoot);
        }

        return [$hazards, [], []];
    }

    /**
     * Every destructive operation `up()` performs, keyed by a canonical signature so the head-minus-base
     * comparison never depends on formatting or on statement order.
     *
     * @return array<string, array{table: string, column: string|null, evidence: string}>
     */
    private static function operations(string $source): array
    {
        $up = self::upMethod($source);

        if (! $up instanceof ClassMethod) {
            return [];
        }

        $operations = [];

        foreach (new NodeFinder()->findInstanceOf($up, StaticCall::class) as $call) {
            $table = self::literalArgument($call, 0);

            // The method name alone is not evidence: `Cache::drop('posts')` names a cache key, not a
            // table, and reporting it would be a hazard invented out of a coincidence of spelling.
            if ($table === null || ! $call->name instanceof Identifier || ! self::isSchemaFacade($call)) {
                continue;
            }

            $method = $call->name->toString();

            if (in_array($method, self::TABLE_DROPS, strict: true)) {
                $operations["drop-table\0{$table}"] = [
                    'table' => $table,
                    'column' => null,
                    'evidence' => "table `{$table}` dropped",
                ];

                continue;
            }

            if (in_array($method, self::BLUEPRINT_CALLS, strict: true)) {
                $operations = [...$operations, ...self::blueprintOperations($call, $table)];
            }
        }

        return $operations;
    }

    /**
     * The column operations inside a `Schema::table()`/`Schema::create()` blueprint closure.
     *
     * @return array<string, array{table: string, column: string|null, evidence: string}>
     */
    private static function blueprintOperations(StaticCall $call, string $table): array
    {
        $second = $call->args[1] ?? null;
        $callback = $second instanceof Arg ? $second->value : null;

        // Laravel accepts either callback shape, and an arrow function is the shorter way to write a
        // one-operation migration — exactly the shape a column drop takes.
        if (! $callback instanceof Closure && ! $callback instanceof ArrowFunction) {
            return [];
        }

        $blueprint = self::blueprintParameter($callback);

        if ($blueprint === null) {
            return [];
        }

        $operations = [];

        foreach (new NodeFinder()->findInstanceOf($callback, MethodCall::class) as $method) {
            // Only calls on the blueprint itself: `$service->dropColumn('subtitle')` inside the same
            // closure is another object's method that happens to share a name.
            if (! $method->name instanceof Identifier || ! self::callsOn($method, $blueprint)) {
                continue;
            }

            $operations = [...$operations, ...self::operationsForCall($method, $method->name->toString(), $table)];
        }

        return $operations;
    }

    /**
     * One blueprint call's destructive operations, keyed the same canonical way.
     *
     * @return array<string, array{table: string, column: string|null, evidence: string}>
     */
    private static function operationsForCall(MethodCall $call, string $name, string $table): array
    {
        if ($name === 'dropColumn') {
            return self::dropOperations(self::droppedColumns($call), $table);
        }

        if ($name === 'renameColumn') {
            return self::renameOperation($call, $table);
        }

        return self::dropOperations(self::shorthandColumns($call, $name), $table);
    }

    /**
     * @param  list<string|null>  $columns  null for a column whose name is built at runtime
     * @return array<string, array{table: string, column: string|null, evidence: string}>
     */
    private static function dropOperations(array $columns, string $table): array
    {
        $operations = [];

        foreach ($columns as $position => $column) {
            $operations[$column === null ? "drop-unread\0{$table}\0{$position}" : "drop-column\0{$table}\0{$column}"] = [
                'table' => $table,
                'column' => $column,
                'evidence' => $column === null
                    ? "a column was dropped from `{$table}`, under a name richter could not read"
                    : "column `{$table}`.`{$column}` dropped",
            ];
        }

        return $operations;
    }

    /**
     * @return array<string, array{table: string, column: string|null, evidence: string}>
     */
    private static function renameOperation(MethodCall $call, string $table): array
    {
        $from = self::literalArgument($call, 0);
        $to = self::literalArgument($call, 1);

        if ($from === null || $to === null) {
            return [];
        }

        return ["rename-column\0{$table}\0{$from}\0{$to}" => [
            'table' => $table,
            'column' => $from,
            'evidence' => "column `{$table}`.`{$from}` renamed to `{$to}`",
        ]];
    }

    /**
     * `dropColumn()` takes one column, an array of them, or a variadic list. A non-literal name is
     * still a real drop, so it reports with the name unread rather than staying silent — the column
     * is gone either way, and silence on a destructive operation is the one direction this must not
     * fail in.
     *
     * @return list<string|null> null for a column whose name is built at runtime
     */
    private static function droppedColumns(MethodCall $call): array
    {
        $columns = [];

        foreach ($call->args as $argument) {
            // `dropColumn(...)` in first-class-callable form carries a placeholder, not an argument.
            if (! $argument instanceof Arg) {
                return [];
            }

            $value = $argument->value;

            if (! $value instanceof Array_) {
                $columns[] = $value instanceof String_ ? $value->value : null;

                continue;
            }

            foreach ($value->items as $item) {
                $columns[] = $item->value instanceof String_ ? $item->value->value : null;
            }
        }

        return $columns;
    }

    /**
     * Whether a static call is on the `Schema` facade. The AST is name-resolved, so an aliased import
     * resolves to the same FQCN; a call on anything else is not a schema operation.
     */
    private static function isSchemaFacade(StaticCall $call): bool
    {
        return $call->class instanceof Name && AppFiles::resolveName($call->class) === self::SCHEMA_FACADE;
    }

    /** The blueprint parameter's variable name, or null when the callback does not take one plainly. */
    private static function blueprintParameter(Closure|ArrowFunction $callback): ?string
    {
        $first = $callback->params[0] ?? null;

        return $first?->var instanceof Variable && is_string($first->var->name) ? $first->var->name : null;
    }

    /** Whether a method call's receiver is the blueprint variable rather than some other object. */
    private static function callsOn(MethodCall $call, string $variable): bool
    {
        return $call->var instanceof Variable && $call->var->name === $variable;
    }

    /**
     * The columns a Blueprint drop helper removes, empty when the call is not one of them.
     * `dropMorphs('x')` removes two columns named after its argument.
     *
     * @return list<string|null> null for a column the helper drops under a name this cannot read
     */
    private static function shorthandColumns(MethodCall $call, string $name): array
    {
        if (array_key_exists($name, self::FIXED_COLUMN_DROPS)) {
            return self::FIXED_COLUMN_DROPS[$name];
        }

        if (array_key_exists($name, self::NAMED_COLUMN_DROPS)) {
            // An argument written but unreadable leaves the column unnamed, not the drop unreported —
            // the same rule `dropColumn()` follows.
            return [self::literalArgument($call, 0) ?? (self::hasArgument($call, 0) ? null : self::NAMED_COLUMN_DROPS[$name])];
        }

        if ($name !== 'dropMorphs') {
            return [];
        }

        $morph = self::literalArgument($call, 0);

        return $morph === null ? [null] : ["{$morph}_type", "{$morph}_id"];
    }

    /** Whether an argument was written at this position at all, present but unreadable included. */
    private static function hasArgument(MethodCall $call, int $index): bool
    {
        return ($call->args[$index] ?? null) !== null;
    }

    /** The `up()` body. Null when the file declares no `up()`, or more than one — an ambiguity to refuse. */
    private static function upMethod(string $source): ?ClassMethod
    {
        // Name-resolved: the receiver of a destructive call has to be checked, and `Schema` as written
        // is only a facade alias until the `use` statements are applied.
        $ast = AppFiles::parseResolved($source);

        if ($ast === null) {
            return null;
        }

        $methods = array_values(array_filter(
            new NodeFinder()->findInstanceOf($ast, ClassMethod::class),
            static fn (ClassMethod $method): bool => $method->name->toString() === 'up',
        ));

        return count($methods) === 1 ? $methods[0] : null;
    }

    /** A plain string argument at `$index`, or null when absent or built at runtime. */
    private static function literalArgument(StaticCall|MethodCall $call, int $index): ?string
    {
        $argument = $call->args[$index] ?? null;

        return $argument instanceof Arg && $argument->value instanceof String_ ? $argument->value->value : null;
    }

    /**
     * Tier 2: losing a column or a table breaks a contract and loses data. It is not tier 3, which the
     * taxonomy reserves for someone gaining access they did not have.
     *
     * The member is the model owning the table, so the reach lane places the hazard through the same
     * declaring-class path a removed member already takes. A table no model claims keeps the table
     * name, which the reach lane answers with `no-known-path` — honest, since richter cannot see what
     * reaches it. The ignore key is always the table (or `table.column`), so a project silences a
     * framework or pivot table through `hazards.ignore` without richter curating a list of them.
     *
     * @param  array{table: string, column: string|null, evidence: string}  $operation
     */
    private static function hazard(array $operation, ?string $projectRoot): Hazard
    {
        $table = $operation['table'];
        $column = $operation['column'];

        return new Hazard(
            lane: 'migration',
            tier: 2,
            cwe: null,
            member: ModelTables::modelFor($table, $projectRoot) ?? $table,
            evidence: $operation['evidence'],
            ignoreKey: $column === null ? $table : "{$table}.{$column}",
            alsoIgnoredBy: $column === null ? [] : [$table],
            field: $column,
        );
    }
}
