<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tracers;

use PhpParser\Node;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use SanderMuller\Richter\Graph\BladeViews;
use SanderMuller\Richter\Graph\CodeGraphBuilder;
use SanderMuller\Richter\Support\AppFiles;
use Symfony\Component\Finder\Finder;

/**
 * Laravel Brain models authorization only for `$this->authorize('ability', Model)` (as a model call),
 * so the constant-based convention — `$user->can(PostPolicy::ABILITY, $post)` in a controller and
 * `@can(App\Policies\PostPolicy::ABILITY, $post)` in a Blade view — produces no edge. A change to
 * such code therefore gives no signal that it is authorization-sensitive.
 *
 * Emits `authorizes` edges from the referencing symbol (a controller/service method, or a view) to
 * the `App\Policies\*` class it gates on, so a policy shows up in the changed code's reach. The link
 * is class-level by design: it answers "does this touch authorization", not which ability resolves
 * to which policy method (that needs the model→policy map and the ability constant's value).
 *
 * Dev/CI tooling only.
 */
final class PolicyEdgeTracer
{
    private const string POLICY_NAMESPACE = 'App\\Policies\\';

    private const string VIEWS_DIR = '/resources/views';

    /** Matches a `App\Policies\Foo` (or nested `…\Foo\Bar`) reference in Blade source, optionally root-qualified. */
    private const string BLADE_POLICY_PATTERN = '/\\\\?App\\\\Policies\\\\([\w\\\\]+)/';

    /** @return list<array{source: string, target: string, type: string}> */
    public function edgesForSource(string $source, string $classFqcn): array
    {
        $ast = AppFiles::parseResolved($source);

        return $ast === null ? [] : $this->edgesForResolvedAst($ast, $classFqcn);
    }

    /**
     * @param  list<Node\Stmt>  $ast  a name-resolved AST ({@see AppFiles::parseResolved()})
     * @return list<array{source: string, target: string, type: string}>
     */
    public function edgesForResolvedAst(array $ast, string $classFqcn): array
    {
        return $this->edgesForMethods(array_values(new NodeFinder()->findInstanceOf($ast, ClassMethod::class)), $classFqcn);
    }

    /**
     * Bucket-fed variant of {@see edgesForResolvedAst()}: the consolidated loop in
     * {@see CodeGraphBuilder} collects each file's nodes in one descent and hands every tracer its
     * bucket, so no tracer re-walks the full tree.
     *
     * @param  list<ClassMethod>  $classMethods  every ClassMethod in the file, any depth
     * @return list<array{source: string, target: string, type: string}>
     */
    public function edgesForMethods(array $classMethods, string $classFqcn): array
    {
        $classFqcn = ltrim($classFqcn, '\\');
        $edges = [];

        foreach ($classMethods as $method) {
            $sourceNode = $classFqcn . '::' . $method->name->toString();

            foreach ($this->policiesReferencedIn($method) as $policy) {
                // A policy referencing itself (via self/static, not its own FQCN) is not a dependency edge.
                if ($policy !== $classFqcn) {
                    $edges[] = ['source' => $sourceNode, 'target' => $policy, 'type' => 'authorizes'];
                }
            }
        }

        return AppFiles::dedupeEdges($edges, byType: true);
    }

    /** @return list<array{source: string, target: string, type: string}> */
    public function bladeEdges(string $projectRoot): array
    {
        $viewsRoot = $projectRoot . self::VIEWS_DIR;

        if (! is_dir($viewsRoot)) {
            return [];
        }

        $edges = [];

        foreach (Finder::create()->files()->in($viewsRoot)->name('*.blade.php') as $file) {
            $view = BladeViews::viewNameFromPath(substr($file->getPathname(), strlen($projectRoot) + 1));

            if ($view === null) {
                continue;
            }

            $source = BladeViews::nodeId($view);

            foreach ($this->policiesReferencedInBlade((string) file_get_contents($file->getPathname())) as $policy) {
                $edges[] = ['source' => $source, 'target' => $policy, 'type' => 'authorizes'];
            }
        }

        return $edges;
    }

    /**
     * Distinct `App\Policies\*` FQCNs referenced anywhere inside a PHP node — a policy constant
     * (`VideoPolicy::UPDATE`), `::class`, `new`, or type hint all read as a dependency on the policy.
     *
     * @return list<string>
     */
    public function policiesReferencedIn(Node $node): array
    {
        $policies = [];

        foreach (new NodeFinder()->findInstanceOf($node, Name::class) as $name) {
            $fqcn = AppFiles::resolveName($name);

            if (str_starts_with($fqcn, self::POLICY_NAMESPACE)) {
                $policies[] = $fqcn;
            }
        }

        return array_values(array_unique($policies));
    }

    /** @return list<string> */
    public function policiesReferencedInBlade(string $content): array
    {
        if (preg_match_all(self::BLADE_POLICY_PATTERN, $content, $matches) < 1) {
            return [];
        }

        return array_values(array_unique(array_map(
            static fn (string $class): string => self::POLICY_NAMESPACE . $class,
            $matches[1],
        )));
    }
}
