<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis\Hazards;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\NodeFinder;
use SanderMuller\Richter\Support\AppFiles;

/**
 * The existing rows a migration's `up()` changes: a query builder or Eloquent `update`, `upsert` or
 * `delete`, a pivot `sync`, and the same written as raw SQL. The schema half is {@see MigrationHazards}.
 *
 * Inserting rows is not one of these. It overwrites nothing, and a seeding migration is routine. Nor is
 * a write to a table the same `up()` creates, which holds no rows yet.
 *
 * The receiver is proven, as everywhere in this family: `$service->delete()` names no rows. A chain
 * counts when it starts at `DB::table(…)`, at a model under `app/Models`, or at a variable bound to one
 * of those — by assignment, by a `foreach`, or as the parameter of a callback the chain takes. A
 * variable two bindings disagree on is not read.
 *
 * Read in `up()` and in the methods of the same class it calls, because a backfill is commonly its own
 * method. A job, a command or a service it starts draws nothing, and neither does a `DB::connection(…)`
 * chain.
 *
 * @internal
 *
 * @phpstan-type DataRoot array{table: string|null, model: string|null, record: bool}
 * @phpstan-type DataOperation array{table: string, column: null, evidence: string, member?: string}
 */
final class MigrationDataOperations
{
    private const array DB_FACADES = ['Illuminate\\Support\\Facades\\DB', 'DB'];

    /** @var array<string, string> builder and model methods that change rows => what they did to them */
    private const array ROW_METHODS = [
        'update' => 'updated', 'updateQuietly' => 'updated', 'updateOrFail' => 'updated', 'updateFrom' => 'updated',
        'save' => 'updated', 'saveQuietly' => 'updated', 'saveOrFail' => 'updated', 'touch' => 'updated',
        'increment' => 'updated', 'incrementEach' => 'updated', 'decrement' => 'updated', 'decrementEach' => 'updated',
        'upsert' => 'upserted', 'updateOrInsert' => 'upserted', 'updateOrCreate' => 'upserted',
        'delete' => 'deleted', 'deleteQuietly' => 'deleted', 'deleteOrFail' => 'deleted', 'forceDelete' => 'deleted',
        'forceDeleteQuietly' => 'deleted', 'destroy' => 'deleted', 'forceDestroy' => 'deleted', 'truncate' => 'deleted',
    ];

    /** Pivot methods that take rows away or change them. `attach()` only adds. */
    private const array PIVOT_METHODS = ['sync', 'syncWithPivotValues', 'detach', 'toggle', 'updateExistingPivot'];

    /** Methods that answer one record, after which a call is a relation and no longer the same table. */
    private const array RECORD_METHODS = ['find', 'findOrFail', 'first', 'firstOrFail', 'firstWhere', 'sole'];

    /** Raw-statement methods that name their own verb, so an unreadable statement still has one. */
    private const array RAW_METHODS = ['statement' => null, 'unprepared' => null, 'affectingStatement' => null, 'update' => 'updated', 'delete' => 'deleted'];

    /** @var array<string, DataRoot|null> variable => what it holds, null where two bindings disagree */
    private array $variables = [];

    /** @param  list<string>  $created  the tables this migration creates */
    private function __construct(
        private readonly ClassMethod $method,
        private readonly ?ClassLike $class,
        private readonly string $migration,
        private readonly ?string $projectRoot,
        private readonly array $created,
    ) {}

    /**
     * @return array<string, DataOperation>
     */
    public static function in(ClassMethod $up, ?ClassLike $class, string $file, ?string $projectRoot): array
    {
        $methods = MigrationMethods::calledFrom($up, $class);
        $created = MigrationMethods::createdTables($methods, $class);
        $operations = [];

        // One reader per method: a variable is a method's own, and two methods may reuse a name.
        foreach ($methods as $method) {
            $operations = [...$operations, ...new self($method, $class, basename($file, '.php'), $projectRoot, $created)->operations()];
        }

        return $operations;
    }

    /** @return array<string, DataOperation> */
    private function operations(): array
    {
        $this->bindVariables();

        $operations = [];

        foreach (new NodeFinder()->find($this->method, static fn (Node $node): bool => $node instanceof MethodCall || $node instanceof NullsafeMethodCall || $node instanceof StaticCall) as $call) {
            if (! $call instanceof MethodCall && ! $call instanceof NullsafeMethodCall && ! $call instanceof StaticCall) {
                continue;
            }

            foreach ($this->operationsOf($call) as $key => $operation) {
                if (! in_array($operation['table'], $this->created, strict: true)) {
                    $operations[$key] = $operation;
                }
            }
        }

        return $operations;
    }

    /** @return array<string, DataOperation> */
    private function operationsOf(MethodCall|NullsafeMethodCall|StaticCall $call): array
    {
        $method = $call->name instanceof Identifier ? $call->name->toString() : '';

        if ($call->isFirstClassCallable()) {
            return [];
        }

        if ($call instanceof StaticCall && $this->isDbFacade($call)) {
            return array_key_exists($method, self::RAW_METHODS) ? $this->rawOperations($call, $method) : [];
        }

        if (in_array($method, self::PIVOT_METHODS, strict: true)) {
            return $this->pivotOperation($call, $method);
        }

        $verb = self::ROW_METHODS[$method] ?? null;
        $root = $verb === null ? null : $this->rootOf($call instanceof StaticCall ? $call : $call->var);

        if ($verb === null || $root === null) {
            return [];
        }

        // `$post->comments()->delete()` changes the rows behind the relation, and one file does not say
        // which table that is. The model it hangs off is named, and the relation beside it.
        $relation = $root['record'] && ! $call instanceof StaticCall ? $this->relationOf($call->var) : null;

        return $this->operation($verb, $root, $relation);
    }

    /** @return array<string, DataOperation> */
    private function pivotOperation(MethodCall|NullsafeMethodCall|StaticCall $call, string $method): array
    {
        $relation = $call instanceof StaticCall ? null : $call->var;
        $root = $relation instanceof Expr ? $this->rootOf($relation) : null;

        if (! $relation instanceof MethodCall || ! $relation->name instanceof Identifier || $root === null || $root['model'] === null) {
            return [];
        }

        $table = $this->ignoreKey($root);
        $name = $relation->name->toString();

        return ["data-pivot\0{$root['model']}\0{$name}\0{$method}" => [
            'table' => $table,
            'column' => null,
            'evidence' => "pivot rows of `{$root['model']}::{$name}()` changed by `{$method}()`",
            'member' => $root['model'],
        ]];
    }

    /**
     * @param  DataRoot  $root
     * @return array<string, DataOperation>
     */
    private function operation(string $verb, array $root, ?string $relation = null): array
    {
        $table = $this->ignoreKey($root);

        $rows = match (true) {
            $relation !== null && $root['model'] !== null => "rows of `{$root['model']}::{$relation}()`",
            $root['table'] === null && $root['model'] === null => 'rows of a table richter could not read were',
            default => 'rows of `' . ($root['table'] ?? $root['model']) . '`',
        };

        $operation = ['table' => $table, 'column' => null, 'evidence' => "{$rows} {$verb}"];

        return ["data-{$verb}\0{$table}\0{$relation}" => $root['model'] === null ? $operation : [...$operation, 'member' => $root['model']]];
    }

    /**
     * What `hazards.ignore` silences it by: the table, the model where the table is not known, or the
     * migration itself where neither is.
     *
     * @param  DataRoot  $root
     */
    private function ignoreKey(array $root): string
    {
        return $root['table'] ?? $root['model'] ?? $this->migration;
    }

    // ---------------------------------------------------------------- roots

    /**
     * Whose rows a chain is about, or null when it starts at nothing proven.
     *
     * @return DataRoot|null
     */
    private function rootOf(Expr $expr): ?array
    {
        $record = false;

        while ($expr instanceof MethodCall || $expr instanceof NullsafeMethodCall || $expr instanceof PropertyFetch) {
            $record = $record || ($expr->name instanceof Identifier && in_array($expr->name->toString(), self::RECORD_METHODS, strict: true));
            $expr = $expr->var;
        }

        $root = match (true) {
            $expr instanceof Variable && is_string($expr->name) => $this->variables[$expr->name] ?? null,
            $expr instanceof StaticCall => $this->staticRoot($expr),
            default => null,
        };

        return $root === null ? null : [...$root, 'record' => $root['record'] || $record];
    }

    /** @return DataRoot|null */
    private function staticRoot(StaticCall $call): ?array
    {
        $method = $call->name instanceof Identifier ? $call->name->toString() : '';

        if ($this->isDbFacade($call)) {
            return $method === 'table' ? ['table' => MigrationLiterals::string($call->args[0] ?? null, $this->class), 'model' => null, 'record' => false] : null;
        }

        $class = $call->class instanceof Name ? AppFiles::resolveName($call->class) : '';

        return ModelTables::isModelClass($class, $this->projectRoot)
            ? ['table' => ModelTables::tableFor($class, $this->projectRoot), 'model' => $class, 'record' => in_array($method, self::RECORD_METHODS, strict: true)]
            : null;
    }

    /**
     * The relation a record's chain goes through: `comments` in `$post->comments()->where(…)`. Every call
     * before the one that answers a record is still a builder's, so it is not looked at.
     */
    private function relationOf(Expr $expr): ?string
    {
        $calls = [];

        while ($expr instanceof MethodCall || $expr instanceof NullsafeMethodCall || $expr instanceof PropertyFetch) {
            if (! $expr instanceof PropertyFetch && $expr->name instanceof Identifier) {
                array_unshift($calls, $expr->name->toString());
            }

            $expr = $expr->var;
        }

        if ($expr instanceof StaticCall && $expr->name instanceof Identifier) {
            array_unshift($calls, $expr->name->toString());
        }

        if ($expr instanceof Variable && is_string($expr->name) && ($this->variables[$expr->name]['record'] ?? false)) {
            return $calls[0] ?? null;
        }

        $answers = array_find_key($calls, static fn (string $name): bool => in_array($name, self::RECORD_METHODS, strict: true));

        return $answers === null ? null : ($calls[$answers + 1] ?? null);
    }

    private function isDbFacade(StaticCall $call): bool
    {
        return $call->class instanceof Name && in_array(AppFiles::resolveName($call->class), self::DB_FACADES, strict: true);
    }

    // ------------------------------------------------------------ variables

    /** Every binding in the method, in source order, so a binding can build on the one before it. */
    private function bindVariables(): void
    {
        $bindings = new NodeFinder()->find($this->method, static fn (Node $node): bool => $node instanceof Assign
            || $node instanceof Foreach_
            || $node instanceof MethodCall
            || $node instanceof StaticCall
            || $node instanceof Closure
            || $node instanceof ArrowFunction);

        $seen = [];

        foreach ($bindings as $node) {
            match (true) {
                $node instanceof Assign => $this->bind($node->var, $this->rootOf($node->expr)),
                $node instanceof Foreach_ => $this->bind($node->valueVar, $this->rootOf($node->expr), record: true),
                $node instanceof MethodCall, $node instanceof StaticCall => $seen = [...$seen, ...$this->bindCallbacks($node)],
                $node instanceof Closure, $node instanceof ArrowFunction => in_array($node, $seen, strict: true) ? null : $this->bindParameters($node, null),
                default => null,
            };
        }
    }

    /**
     * The callbacks a proven chain takes: `Post::each(fn ($post) => …)`, `->chunkById(100, fn ($posts) => …)`.
     *
     * @return list<Closure|ArrowFunction>
     */
    private function bindCallbacks(MethodCall|StaticCall $call): array
    {
        $root = $this->rootOf($call);
        $bound = [];

        if ($root === null) {
            return [];
        }

        foreach ($call->args as $arg) {
            if ($arg instanceof Arg && ($arg->value instanceof Closure || $arg->value instanceof ArrowFunction)) {
                $this->bindParameters($arg->value, $root, record: $call->name instanceof Identifier && str_starts_with($call->name->toString(), 'each'));
                $bound[] = $arg->value;
            }
        }

        return $bound;
    }

    /** @param  DataRoot|null  $root */
    private function bindParameters(Closure|ArrowFunction $function, ?array $root, bool $record = false): void
    {
        foreach ($function->params as $position => $param) {
            $class = $param->type instanceof Name ? AppFiles::resolveName($param->type) : '';

            // A typed parameter says what it holds, whoever calls the closure.
            $this->bind($param->var, match (true) {
                ModelTables::isModelClass($class, $this->projectRoot) => ['table' => ModelTables::tableFor($class, $this->projectRoot), 'model' => $class, 'record' => true],
                $position === 0 => $root,
                default => null,
            }, $record);
        }
    }

    /** @param  DataRoot|null  $root */
    private function bind(Node $variable, ?array $root, bool $record = false): void
    {
        if (! $variable instanceof Variable || ! is_string($variable->name)) {
            return;
        }

        $root = $root === null ? null : [...$root, 'record' => $root['record'] || $record];

        // Kept as null rather than forgotten: a later binding must not make a disagreeing name readable.
        $this->variables[$variable->name] = ! array_key_exists($variable->name, $this->variables) || $this->variables[$variable->name] === $root
            ? $root
            : null;
    }

    // ------------------------------------------------------------------- SQL

    /** @return array<string, DataOperation> */
    private function rawOperations(StaticCall $call, string $method): array
    {
        $argument = $call->args[0] ?? null;
        $changes = $argument instanceof Arg ? MigrationSql::changes($argument->value) : [];

        // `DB::update($sql)` says what it does even where the statement cannot be read.
        if ($changes === [] && self::RAW_METHODS[$method] !== null) {
            $changes = [['verb' => self::RAW_METHODS[$method], 'table' => null]];
        }

        $operations = [];

        foreach ($changes as $change) {
            $operations = [...$operations, ...$this->operation($change['verb'], ['table' => $change['table'], 'model' => null, 'record' => false])];
        }

        return $operations;
    }
}
