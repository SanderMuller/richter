<?php declare(strict_types=1);

namespace SanderMuller\Richter\Support;

use Closure;
use PhpParser\ErrorHandler\Collecting;
use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;
use SanderMuller\Richter\Graph\CodeGraphBuilder;
use Symfony\Component\Finder\Finder;
use Throwable;

/**
 * Shared file-scan + edge + parse helpers for the graph tracers. Centralises the app/ php-file walk
 * (path → FQCN via {@see Fqcn::fromPath}), the edge dedupe, and source parsing. Dev/CI tooling only.
 */
final class AppFiles
{
    /**
     * Parse PHP source to its statement AST, or null when it doesn't parse (advisory tooling skips
     * unparseable input rather than aborting).
     *
     * @return list<Stmt>|null
     */
    public static function parse(string $source): ?array
    {
        try {
            $ast = new ParserFactory()->createForHostVersion()->parse($source);

            return $ast === null ? null : array_values($ast);
        } catch (Throwable) {
            return null;
        }
    }

    /** @return list<array{fqcn: string, path: string}> */
    public static function phpClasses(string $dir, string $projectRoot): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        $classes = [];

        foreach (Finder::create()->files()->in($dir)->name('*.php') as $file) {
            $path = $file->getPathname();
            $classes[] = [
                'fqcn' => Fqcn::fromPath(substr($path, strlen($projectRoot) + 1)),
                'path' => $path,
            ];
        }

        return $classes;
    }

    /**
     * Parse and name-resolve in one step — the shared front half of every AST tracer. One resolved
     * AST feeds all per-file tracers in {@see CodeGraphBuilder}, so those tracers cost one shared
     * app-tree walk instead of one each (Brain's own analysis and the member-declaration pass still
     * parse separately).
     *
     * @return list<Stmt>|null
     */
    public static function parseResolved(string $source): ?array
    {
        $ast = self::parse($source);

        if ($ast === null) {
            return null;
        }

        // NameResolver attaches a `resolvedName` FQCN to every Name node (imports/aliases applied);
        // replaceNodes=false keeps originals so names read by written form.
        //
        // Errors are COLLECTED, not thrown. A file can parse and still be semantically invalid —
        // two `use` statements binding one alias is the common shape — and the default throwing
        // handler turned that into a `PhpParser\Error` out of this method, which no call site
        // catches: one such file anywhere under `app/` aborted the whole graph build, and one in a
        // diff aborted `detect-changes`. Collecting keeps the rest of the file's names resolved and
        // lets advisory tooling degrade instead of refusing to run.
        new NodeTraverser(new NameResolver(new Collecting(), ['preserveOriginalNames' => true, 'replaceNodes' => false]))->traverse($ast);

        return $ast;
    }

    /** The NameResolver-attached FQCN of a name node (imports/aliases applied), root-slash trimmed. */
    public static function resolveName(Name $name): string
    {
        $resolved = $name->getAttribute('resolvedName');

        return ltrim($resolved instanceof Name ? $resolved->toString() : $name->toString(), '\\');
    }

    /** A class constant's string value, or null when the class/constant doesn't resolve or isn't a string. */
    public static function stringConstantValue(string $class, string $constant): ?string
    {
        try {
            if (! class_exists($class) || ! defined("{$class}::{$constant}")) {
                return null;
            }

            $value = constant("{$class}::{$constant}");

            return is_string($value) ? $value : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  list<array{source: string, target: string, type: string}>  $edges
     * @return list<array{source: string, target: string, type: string}>
     */
    public static function dedupeEdges(array $edges, bool $byType = false): array
    {
        $seen = [];
        $unique = [];

        foreach ($edges as $edge) {
            $key = $byType
                ? $edge['source'] . "\0" . $edge['target'] . "\0" . $edge['type']
                : $edge['source'] . "\0" . $edge['target'];

            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $unique[] = $edge;
            }
        }

        return $unique;
    }

    /**
     * Every matching node in a method's OWN scope — parameter defaults and attributes included —
     * skipping anything inside a NAMED class-like nested within it, and descending into anonymous ones.
     *
     * The asymmetry is the point, and it follows from who else scans the node. A named inner class is
     * handed to the caller as a class-like in its own right, so walking into it here would attribute
     * the same call twice, the second time to a method that never made it. An anonymous class is not:
     * it has no name to be a source, and the callers skip it for exactly that reason — so its calls
     * belong to the method that builds it, which is both true (that method's return value renders the
     * view / reads the config) and the only attribution that names something a reader can open.
     *
     * @param  Closure(Node): bool  $matches  a first-class callable, so the predicate can be typed
     * @return list<Node>
     */
    public static function nodesOwnedBy(ClassMethod $method, Closure $matches): array
    {
        return array_column(self::nodesOwnedByWithNesting($method, $matches), 0);
    }

    /**
     * {@see nodesOwnedBy()}, plus whether each node sits inside an ANONYMOUS class nested in the
     * method rather than in the method's own body.
     *
     * The flag exists because scope-relative names do not survive the descent: `self`, `static` and
     * `parent` inside an anonymous class mean that class, not the one whose method builds it, so a
     * caller resolving them against the enclosing class would draw a confidently wrong edge. Callers
     * with no scope-relative names can ignore it and use {@see nodesOwnedBy()}.
     *
     * @param  Closure(Node): bool  $matches
     * @return list<array{0: Node, 1: bool}>
     */
    public static function nodesOwnedByWithNesting(ClassMethod $method, Closure $matches): array
    {
        $visitor = new class ($matches) extends NodeVisitorAbstract {
            /** @var list<array{0: Node, 1: bool}> */
            public array $found = [];

            private int $depth = 0;

            /** @param  Closure(Node): bool  $matches */
            public function __construct(private readonly Closure $matches) {}

            public function enterNode(Node $node): ?int
            {
                // Named: scanned in its own right, so pruning here is what keeps one call from
                // becoming two edges. Anonymous: scanned nowhere else, so this is its only chance.
                if ($node instanceof ClassLike) {
                    if ($node->namespacedName instanceof Name) {
                        return NodeVisitor::DONT_TRAVERSE_CHILDREN;
                    }

                    ++$this->depth;

                    return null;
                }

                if (($this->matches)($node)) {
                    $this->found[] = [$node, $this->depth > 0];
                }

                return null;
            }

            public function leaveNode(Node $node): null
            {
                if ($node instanceof ClassLike && ! $node->namespacedName instanceof Name) {
                    --$this->depth;
                }

                return null;
            }
        };

        $traverser = new NodeTraverser();
        $traverser->addVisitor($visitor);
        $traverser->traverse($method->stmts ?? []);

        // Params and attributes are traversed separately because they are not statements: a match in
        // a parameter default is as real a call as one in the body.
        foreach ($method->params as $param) {
            $traverser->traverse([$param]);
        }

        foreach ($method->attrGroups as $attrGroup) {
            $traverser->traverse([$attrGroup]);
        }

        return $visitor->found;
    }
}
