<?php declare(strict_types=1);

namespace SanderMuller\Richter\Support;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Enum_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\NodeFinder;
use SanderMuller\Richter\Graph\CodeGraphBuilder;

/**
 * The code that runs when a model is loaded or saved, and the two edge kinds that follow from it.
 *
 * - `casts`: a model's `casts()` method (or the class, for a `$casts` property) to each app cast
 *   class it names. `Foo::class` in that array is a class literal, and no other lane draws an edge
 *   for one, so without this a change to a cast class reached nothing.
 * - `hydrates`: a relation method to the hydration surface of the model it returns: casts,
 *   accessors and mutators, `boot*`/`booted`/`initialize*`, the cast classes a `$casts`
 *   property names, and the observers of the model and its parents ({@see observerEdges()}).
 *   Loading the relation runs that code with no call site to trace.
 *
 * `hydrates` is what lets test selection skip `relation-to-model`: a change to an ordinary model
 * method no longer reaches every class that relates to the model, and a change to code that runs
 * on load still reaches the bodies, and the importers, of each relation that loads it.
 *
 * Cross-file like {@see RelationIndex}: a model, its traits, its parent and its cast classes are
 * different files, so it accumulates via {@see collect()} across the consolidated AST pass in
 * {@see CodeGraphBuilder} and answers only after every file has been collected.
 *
 * @internal
 */
final class HydrationSurface
{
    private const string ATTRIBUTE_CAST = 'Illuminate\\Database\\Eloquent\\Casts\\Attribute';

    /** Marks a boot or initialize hook of any name (Laravel 13). */
    private const array HOOK_ATTRIBUTES = [
        'Illuminate\\Database\\Eloquent\\Attributes\\Boot',
        'Illuminate\\Database\\Eloquent\\Attributes\\Initialize',
    ];

    /** Methods Eloquent calls on load, save or serialisation, by name. Broad on purpose: a miss under-selects. */
    private const string HYDRATION_METHOD = '/^(?:__construct|getTable|casts|getCasts|get\w*Attribute|set\w*Attribute|getAttributeValue|boot\w*|booted|initialize\w+|newCollection|newEloquentBuilder|newFromBuilder|newInstance|setRawAttributes|newQuery\w*|newModelQuery|getDateFormat|getKeyName|getMorphClass|getConnectionName|serializeDate|toArray|jsonSerialize)$/';

    /**
     * @var array<string, array{enum: bool, parent: string|null, traits: list<string>, members: list<string>, methodCasts: list<string>, propertyCasts: list<string>}>
     *   keyed by FQCN
     */
    private array $records = [];

    /** @param  list<ClassLike>  $classLikes  every ClassLike in one file, any depth */
    public function collect(array $classLikes): void
    {
        foreach ($classLikes as $node) {
            $fqcn = $node->namespacedName?->toString();

            if ($fqcn === null) {
                continue;
            }

            $this->records[$fqcn] = [
                'enum' => $node instanceof Enum_,
                'parent' => $node instanceof Class_ && $node->extends instanceof Name ? AppFiles::resolveName($node->extends) : null,
                'traits' => $this->traitsUsedBy($node),
                'members' => $this->hydrationMembers($node),
                'methodCasts' => $this->methodCasts($node),
                'propertyCasts' => $this->propertyCasts($node),
            ];
        }
    }

    /**
     * The `casts` edges, plus one `hydrates` edge from each relation method to each piece of the
     * hydration surface of the model it returns.
     *
     * @param  list<array{source: string, target: string, type: string}>  $relationEdges  the `relation-to-model` edges
     * @return list<array{source: string, target: string, type: string}>
     */
    public function edges(array $relationEdges): array
    {
        $edges = [];

        foreach ($this->records as $fqcn => $record) {
            foreach ($this->castClasses($record['methodCasts']) as $cast) {
                $edges[] = ['source' => "{$fqcn}::casts", 'target' => $cast, 'type' => 'casts'];
            }

            foreach ($this->castClasses($record['propertyCasts']) as $cast) {
                $edges[] = ['source' => $fqcn, 'target' => $cast, 'type' => 'casts'];
            }
        }

        $surfaces = [];

        foreach ($relationEdges as $relation) {
            $surfaces[$relation['target']] ??= $this->surfaceOf($relation['target']);

            foreach ($surfaces[$relation['target']] as $target) {
                $edges[] = ['source' => $relation['source'], 'target' => $target, 'type' => 'hydrates'];
            }
        }

        return AppFiles::dedupeEdges($edges, byType: true);
    }

    /**
     * A relation method to each observer of the model it returns: an observer runs when a model is
     * saved or deleted through the relation. A parent's observer counts too: `#[ObservedBy]` and an
     * `observe()` call in a parent's `booted()` both apply to the subclass.
     *
     * @param  array<string, array{parent: string|null, ...}>  $inheritance
     * @param  list<array{source: string, target: string, type: string}>  $edges  the merged graph edges
     * @return list<array{source: string, target: string, type: string}>
     */
    public static function observerEdges(array $inheritance, array $edges): array
    {
        $observers = [];

        foreach ($edges as $edge) {
            if ($edge['type'] === 'model-to-observer') {
                $observers[$edge['source']][] = $edge['target'];
            }
        }

        $hydrates = [];

        foreach ($edges as $edge) {
            if ($edge['type'] !== 'relation-to-model') {
                continue;
            }

            $seen = [];

            for ($model = $edge['target']; $model !== null && ! isset($seen[$model]); $model = $inheritance[$model]['parent'] ?? null) {
                $seen[$model] = true;

                foreach ($observers[$model] ?? [] as $observer) {
                    $hydrates[] = ['source' => $edge['source'], 'target' => $observer, 'type' => 'hydrates'];
                }
            }
        }

        return $hydrates;
    }

    /**
     * The nodes that run when this model loads: its own hydration members and the property cast
     * classes, then its traits' and its ancestors'. A member is named on the class-like that
     * declares it, since that is the node a change seeds.
     *
     * @return list<string>
     */
    private function surfaceOf(string $model): array
    {
        $nodes = [];
        $pending = [$model];
        $seen = [];

        while ($pending !== []) {
            $fqcn = array_shift($pending);

            if (isset($seen[$fqcn]) || ! isset($this->records[$fqcn])) {
                continue;
            }

            $seen[$fqcn] = true;
            $record = $this->records[$fqcn];

            foreach ($record['members'] as $member) {
                $nodes[] = "{$fqcn}::{$member}";
            }

            array_push($nodes, ...$this->castClasses($record['propertyCasts']));
            array_push($pending, ...$record['traits']);

            if ($record['parent'] !== null) {
                $pending[] = $record['parent'];
            }
        }

        return $nodes;
    }

    /**
     * The named classes that are app cast classes this index can see: an enum cast runs only the
     * native `from()`, and a class no file declares has no node to reach.
     *
     * @param  list<string>  $classes
     * @return list<string>
     */
    private function castClasses(array $classes): array
    {
        return array_values(array_filter(
            $classes,
            fn (string $class): bool => isset($this->records[$class]) && ! $this->records[$class]['enum'],
        ));
    }

    /** @return list<string> */
    private function hydrationMembers(ClassLike $node): array
    {
        $members = [];

        foreach ($node->getMethods() as $method) {
            $name = $method->name->toString();

            if (preg_match(self::HYDRATION_METHOD, $name) === 1 || $this->returnsAttribute($method) || $this->hasHookAttribute($method)) {
                $members[] = $name;
            }
        }

        return $members;
    }

    private function hasHookAttribute(ClassMethod $method): bool
    {
        foreach ($method->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                if (in_array(AppFiles::resolveName($attribute->name), self::HOOK_ATTRIBUTES, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function returnsAttribute(ClassMethod $method): bool
    {
        $type = $method->returnType instanceof NullableType ? $method->returnType->type : $method->returnType;

        return $type instanceof Name && AppFiles::resolveName($type) === self::ATTRIBUTE_CAST;
    }

    /** @return list<string> */
    private function methodCasts(ClassLike $node): array
    {
        $method = $node->getMethod('casts');

        if (! $method instanceof ClassMethod) {
            return [];
        }

        $classes = [];

        foreach (new NodeFinder()->findInstanceOf($method->stmts ?? [], Return_::class) as $return) {
            if ($return->expr instanceof Array_) {
                array_push($classes, ...$this->classesIn($return->expr));
            }
        }

        return array_values(array_unique($classes));
    }

    /** @return list<string> */
    private function propertyCasts(ClassLike $node): array
    {
        $default = null;

        foreach ($node->getProperty('casts')->props ?? [] as $item) {
            if ($item->name->toString() === 'casts') {
                $default = $item->default;
            }
        }

        return $default instanceof Array_ ? $this->classesIn($default) : [];
    }

    /**
     * The classes an array of casts names as `Foo::class`, or as `Foo::class.':argument'`.
     *
     * @return list<string>
     */
    private function classesIn(Array_ $array): array
    {
        $classes = [];

        foreach ($array->items as $item) {
            $value = $item->value;
            $fetch = $value instanceof Concat ? $value->left : $value;
            $class = $this->classOf($fetch);

            if ($class !== null && AppNamespace::isInApp($class)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    private function classOf(Expr $expr): ?string
    {
        return $expr instanceof ClassConstFetch
            && $expr->class instanceof Name
            && $expr->name instanceof Identifier
            && $expr->name->toLowerString() === 'class'
                ? AppFiles::resolveName($expr->class)
                : null;
    }

    /** @return list<string> */
    private function traitsUsedBy(ClassLike $node): array
    {
        $traits = [];

        foreach ($node->stmts as $statement) {
            if ($statement instanceof TraitUse) {
                foreach ($statement->traits as $trait) {
                    $traits[] = AppFiles::resolveName($trait);
                }
            }
        }

        return $traits;
    }
}
