<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tracers;

use Illuminate\Support\Str;
use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use SanderMuller\Richter\Graph\BladeViews;
use SanderMuller\Richter\Support\AppFiles;
use SanderMuller\Richter\Support\AppNamespace;
use SanderMuller\Richter\Support\RunningApplication;
use Symfony\Component\Finder\Finder;
use Throwable;

/**
 * The Livewire components a Blade view embeds. A page that renders `<livewire:post-comments/>` runs
 * that component, but no edge led from the view to it, so a change to a component member reached
 * its own class and stopped: the route that renders the page, and the tests that drive the route,
 * were never found.
 *
 * Emits `livewire-embed` edges from the view node to the component class for `<livewire:name>`,
 * `@livewire('name')` and `@livewire(X::class)`. A name resolves through Livewire's own registry
 * when the analysed project is the running app, else through Livewire's naming convention, and
 * links only when the class is an app class.
 *
 * A view that renders a component it picks at runtime (`<livewire:dynamic-component :is="…">`,
 * `<livewire:is>`, `@livewire($name)`) names no component. For that view, the edges go to each
 * Livewire component that an app method the view calls by name (`$tab->component()`) names by
 * `::class`. The match is on the method name alone, so a method that names no Livewire component
 * links nothing.
 *
 * @internal
 */
final class LivewireEmbedTracer
{
    public const string EDGE_TYPE = 'livewire-embed';

    private const string VIEWS_DIR = '/resources/views';

    private const string LIVEWIRE_COMPONENT = 'Livewire\\Component';

    private const string REGISTRY = 'Livewire\\Mechanisms\\ComponentRegistry';

    /** `<livewire:name …>`, a whole name not followed by `::` (a package namespace). */
    private const string TAG_PATTERN = '/<livewire:([\w.\-]+)(?![\w.\-:])/';

    private const string DIRECTIVE_NAME_PATTERN = '/@livewire\s*\(\s*[\'"]([\w.\-]+)[\'"]/';

    private const string DIRECTIVE_CLASS_PATTERN = '/@livewire\s*\(\s*\\\\?([\w\\\\]+)::class/';

    private const string DYNAMIC_PATTERN = '/<livewire:(?:dynamic-component|is)(?![\w.\-:])|@livewire\s*\(\s*\$/';

    private const string CALL_PATTERN = '/->(\w+)\s*\(/';

    /** Tag names that pick the component at runtime. */
    private const array DYNAMIC_TAGS = ['dynamic-component', 'is'];

    /** @var array<string, array<string, true>> method name => the classes its bodies name by `::class` */
    private array $classesNamedBy = [];

    /**
     * Records the classes each method names by `::class`, for the views that render a component
     * they pick at runtime.
     *
     * @param  list<ClassMethod>  $methods
     */
    public function collect(array $methods): void
    {
        $finder = new NodeFinder();

        foreach ($methods as $method) {
            $fetches = $finder->find($method->stmts ?? [], static fn (Node $node): bool => $node instanceof ClassConstFetch
                && $node->class instanceof Name
                && ! $node->class->isSpecialClassName()
                && $node->name instanceof Identifier
                && $node->name->toLowerString() === 'class');

            foreach ($fetches as $fetch) {
                if ($fetch instanceof ClassConstFetch && $fetch->class instanceof Name) {
                    $this->classesNamedBy[$method->name->toString()][AppFiles::resolveName($fetch->class)] = true;
                }
            }
        }
    }

    /**
     * @param  array<string, array{parent: string|null, declared: list<string>}>  $inheritance  the app classes
     * @return list<array{source: string, target: string, type: string}>
     */
    public function edges(string $projectRoot, array $inheritance): array
    {
        $viewsRoot = $projectRoot . self::VIEWS_DIR;

        if (! is_dir($viewsRoot)) {
            return [];
        }

        $namespace = $this->componentNamespace($projectRoot);
        $useRegistry = RunningApplication::isProject($projectRoot);
        $edges = [];

        foreach (Finder::create()->files()->in($viewsRoot)->name('*.blade.php')->sortByName() as $file) {
            $view = BladeViews::viewNameFromPath(substr($file->getPathname(), strlen($projectRoot) + 1));

            if ($view === null) {
                continue;
            }

            $source = BladeViews::nodeId($view);
            $content = (string) file_get_contents($file->getPathname());

            foreach ($this->embeddedComponents($content, $namespace, $useRegistry) as $class) {
                if (isset($inheritance[$class])) {
                    $edges[] = ['source' => $source, 'target' => $class, 'type' => self::EDGE_TYPE];
                }
            }

            foreach ($this->runtimePicks($content, $inheritance, $namespace) as $class) {
                $edges[] = ['source' => $source, 'target' => $class, 'type' => self::EDGE_TYPE];
            }
        }

        return AppFiles::dedupeEdges($edges, byType: true);
    }

    /**
     * The classes a view names a component by, through a tag or a directive.
     *
     * @return list<string>
     */
    private function embeddedComponents(string $content, string $namespace, bool $useRegistry): array
    {
        $names = [];

        if (preg_match_all(self::TAG_PATTERN, $content, $matches) > 0) {
            $names = array_diff($matches[1], self::DYNAMIC_TAGS);
        }

        if (preg_match_all(self::DIRECTIVE_NAME_PATTERN, $content, $matches) > 0) {
            array_push($names, ...$matches[1]);
        }

        $classes = array_map(fn (string $name): string => $this->classFor($name, $namespace, $useRegistry), array_unique($names));

        if (preg_match_all(self::DIRECTIVE_CLASS_PATTERN, $content, $matches) > 0) {
            array_push($classes, ...$matches[1]);
        }

        return array_values(array_unique($classes));
    }

    /**
     * The Livewire components a view with a runtime pick can render: those named by the methods the
     * view calls.
     *
     * @param  array<string, array{parent: string|null, declared: list<string>}>  $inheritance
     * @return list<string>
     */
    private function runtimePicks(string $content, array $inheritance, string $namespace): array
    {
        if (preg_match(self::DYNAMIC_PATTERN, $content) !== 1 || preg_match_all(self::CALL_PATTERN, $content, $matches) === 0) {
            return [];
        }

        $classes = [];

        foreach (array_unique($matches[1]) as $method) {
            foreach (array_keys($this->classesNamedBy[$method] ?? []) as $class) {
                if ($this->isComponent($class, $inheritance, $namespace)) {
                    $classes[] = $class;
                }
            }
        }

        return $classes;
    }

    /**
     * An app class under Livewire's component namespace, or one whose parent chain reaches
     * Livewire's base component.
     *
     * @param  array<string, array{parent: string|null, declared: list<string>}>  $inheritance
     */
    private function isComponent(string $class, array $inheritance, string $namespace): bool
    {
        if (! isset($inheritance[$class])) {
            return false;
        }

        if (str_starts_with($class, $namespace)) {
            return true;
        }

        $seen = [];

        while (isset($inheritance[$class]) && ! isset($seen[$class])) {
            $seen[$class] = true;
            $class = $inheritance[$class]['parent'];

            if ($class === null) {
                return false;
            }
        }

        return $class === self::LIVEWIRE_COMPONENT;
    }

    private function classFor(string $name, string $namespace, bool $useRegistry): string
    {
        return ($useRegistry ? $this->registryClass($name) : null)
            ?? $namespace . implode('\\', array_map(Str::studly(...), explode('.', $name)));
    }

    /** What Livewire's own registry resolves a name to, when the installed Livewire has one. */
    private function registryClass(string $name): ?string
    {
        try {
            if (! class_exists(self::REGISTRY)) {
                return null;
            }

            $registry = resolve(self::REGISTRY);

            if (! is_object($registry) || ! method_exists($registry, 'getClass')) {
                return null;
            }

            $class = $registry->getClass($name);

            return is_string($class) && $class !== '' ? ltrim($class, '\\') : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** The namespace Livewire's naming convention puts a component in, with a trailing separator. */
    private function componentNamespace(string $projectRoot): string
    {
        $configured = RunningApplication::isProject($projectRoot) ? config('livewire.class_namespace') : null;

        return is_string($configured) && $configured !== ''
            ? trim($configured, '\\') . '\\'
            : AppNamespace::qualify('Livewire\\');
    }
}
