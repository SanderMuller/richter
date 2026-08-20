<?php declare(strict_types=1);

namespace SanderMuller\Richter\Support;

use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Use_;
use PhpParser\NodeFinder;
use SanderMuller\Richter\Tracers\RelationTraversalTracer;

/**
 * The types a method states about the values it handles, read rather than inferred: declared
 * property, promoted-property and parameter types, plus the `@var` docblocks PHP has no syntax for.
 *
 * Split out of {@see RelationTraversalTracer} because reading a type and walking a chain are two
 * jobs, and only the second one needs the relation index.
 *
 * Every reader is app-scoped and refuses a union: two classes mean the value could be either, and a
 * relation hop taken on the wrong one names a model the code never reaches.
 *
 * @internal
 */
final class DeclaredTypes
{
    /** @var array<string, string> lowercased alias => FQCN, from the file's `use` statements */
    private array $aliases = [];

    /** @param  list<Use_>  $uses  the file's imports, for the docblock names name resolution never saw */
    public function readImports(array $uses): void
    {
        $this->aliases = [];

        foreach ($uses as $use) {
            // `use function` and `use const` import no class, so a docblock name they happen to match
            // is not theirs to resolve.
            if ($use->type !== Use_::TYPE_NORMAL) {
                continue;
            }

            foreach ($use->uses as $item) {
                if ($item->type !== Use_::TYPE_UNKNOWN && $item->type !== Use_::TYPE_NORMAL) {
                    continue;
                }

                $alias = $item->alias instanceof Identifier ? $item->alias->toString() : $item->name->getLast();
                $this->aliases[strtolower($alias)] = $item->name->toString();
            }
        }
    }

    /**
     * The `@var` types a scope states, mapped to the ASSIGNMENT each one annotates.
     *
     * Method-wide would be wrong twice over: a later annotation would type an earlier read, and two
     * annotations for one variable would collapse into whichever came last. A docblock speaks for the
     * statement it sits on, so it is keyed by the assignment inside that statement.
     *
     * @return array<int, array<string, string>> assignment node id => variable => FQCN
     */
    public function docblockTypesIn(FunctionLike $scope): array
    {
        $finder = new NodeFinder();
        $types = [];

        foreach ($finder->find($scope, static fn (Node $node): bool => $node->getDocComment() instanceof Doc) as $node) {
            $declared = $this->varTagsIn($node->getDocComment()?->getText() ?? '');

            if ($declared === []) {
                continue;
            }

            foreach ($finder->findInstanceOf($node, Assign::class) as $assign) {
                $types[spl_object_id($assign)] = [...$types[spl_object_id($assign)] ?? [], ...$declared];
            }
        }

        return $types;
    }

    /**
     * The `@var Type $name` pairs in one docblock, as variable => FQCN.
     *
     * @return array<string, string>
     */
    private function varTagsIn(string $text): array
    {
        if (preg_match_all('/@var\s+(\S+)\s+\$(\w+)/', $text, $matches, PREG_SET_ORDER) === 0) {
            return [];
        }

        $types = [];

        foreach ($matches as [, $declared, $variable]) {
            $class = $this->docblockClass($declared);

            if ($class !== null) {
                $types[$variable] = $class;
            }
        }

        return $types;
    }

    /** One class out of a docblock type, or null for a union of two classes, a builtin, or a vendor class. */
    private function docblockClass(string $declared): ?string
    {
        $parts = array_values(array_filter(
            explode('|', ltrim($declared, '?')),
            static fn (string $part): bool => $part !== '' && strtolower($part) !== 'null',
        ));

        if (count($parts) !== 1 || preg_match('/^\\\\?[\w\\\\]+$/', $parts[0]) !== 1) {
            return null;
        }

        $declared = $parts[0];

        // A leading backslash is already the answer. Anything else is written the way the file reads
        // it, so it goes through the same imports the code around it uses.
        if (str_starts_with($declared, '\\')) {
            return $this->appClass(ltrim($declared, '\\'));
        }

        $head = strstr($declared, '\\', before_needle: true);
        $alias = $this->aliases[strtolower($head === false ? $declared : $head)] ?? null;

        if ($alias !== null) {
            return $this->appClass($head === false ? $alias : $alias . substr($declared, strlen($head)));
        }

        return $this->appClass($declared);
    }

    /**
     * Declared property types, including promoted constructor properties. A union type names more
     * than one class and is left out rather than guessed at; a nullable one is its inner type.
     *
     * @return array<string, string>
     */
    public function propertyTypesOf(ClassLike $node): array
    {
        $types = [];

        foreach ($node->getProperties() as $property) {
            $type = $this->classTypeOf($property->type);

            foreach ($property->props as $prop) {
                if ($type !== null) {
                    $types[$prop->name->toString()] = $type;
                }
            }
        }

        $constructor = $node->getMethod('__construct');

        foreach ($constructor instanceof ClassMethod ? $constructor->params : [] as $parameter) {
            $type = $this->classTypeOf($parameter->type);

            if ($type !== null && $parameter->flags !== 0 && $parameter->var instanceof Variable && is_string($parameter->var->name)) {
                $types[$parameter->var->name] = $type;
            }
        }

        return $types;
    }

    /** @return array<string, string> */
    public function parameterTypesOf(FunctionLike $method): array
    {
        $types = [];

        foreach ($method->getParams() as $parameter) {
            $type = $this->classTypeOf($parameter->type);

            if ($type !== null && $parameter->var instanceof Variable && is_string($parameter->var->name)) {
                $types[$parameter->var->name] = $type;
            }
        }

        return $types;
    }

    /** The app class a declared type names, or null for a union, a builtin, or a vendor class. */
    private function classTypeOf(?Node $type): ?string
    {
        if ($type instanceof NullableType) {
            return $this->classTypeOf($type->type);
        }

        return $type instanceof Name ? $this->appClass(AppFiles::resolveName($type)) : null;
    }

    /** App-scoped like every other lane: a vendor class has no node any hop could continue from. */
    public function appClass(string $fqcn): ?string
    {
        return AppNamespace::isInApp($fqcn) ? $fqcn : null;
    }
}
