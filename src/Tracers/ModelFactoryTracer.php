<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tracers;

use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\NodeFinder;
use ReflectionMethod;
use SanderMuller\Richter\Support\AppFiles;
use SanderMuller\Richter\Support\AppNamespace;
use SanderMuller\Richter\Support\HydrationSurface;
use Symfony\Component\Finder\Finder;
use Throwable;

/**
 * The app code a model factory runs. `database/factories` is test code and is not in the graph, so
 * a test that builds a model through a factory ran code no edge led to: the members the factory's
 * definition calls (an enum default, a value object), the factories of the models it names for its
 * attributes (`'post_id' => Post::factory()`, which `make()` creates as well), and the code that
 * runs when each of those models is built.
 *
 * Each edge leaves `X::factory`, the node a test reaches by calling `X::factory()`, and tests that
 * do import `X`. Only `definition()` and `configure()` are read, of the factory, its parents and its
 * traits: those run on every call, and a state runs only when a test asks for it.
 *
 * The `factory` edge is test code, so the risk count leaves it out ({@see ImpactAnalyzer}).
 *
 * @internal
 */
final readonly class ModelFactoryTracer
{
    public const string EDGE_TYPE = 'factory';

    private const array ALWAYS_RUN = ['definition', 'configure'];

    public function __construct(private string $projectRoot) {}

    /**
     * @param  list<string>  $classes  the app classes, of which the models with a factory are used
     * @return list<array{source: string, target: string, type: string}>
     */
    public function edges(HydrationSurface $surface, array $classes): array
    {
        $factories = $this->factoryClasses();

        if ($factories === []) {
            return [];
        }

        $modelOf = FactoryModels::byFactory($classes, $factories);
        $factoryOf = array_flip($modelOf);
        $edges = [];

        foreach ($modelOf as $factory => $model) {
            $source = "{$model}::factory";
            [$creates, $calls] = $this->alwaysRun($factory, $factories);

            foreach ($calls as $target) {
                $edges[] = ['source' => $source, 'target' => $target, 'type' => self::EDGE_TYPE];
            }

            foreach ($creates as [$kind, $class]) {
                $created = $kind === 'model' ? (isset($factoryOf[$class]) ? $class : null) : ($modelOf[$class] ?? null);

                if ($created !== null && $created !== $model) {
                    $edges[] = ['source' => $source, 'target' => "{$created}::factory", 'type' => self::EDGE_TYPE];
                }
            }

            foreach ($surface->surfaceOf($model) as $target) {
                $edges[] = ['source' => $source, 'target' => $target, 'type' => self::EDGE_TYPE];
            }
        }

        return AppFiles::dedupeEdges($edges, byType: true);
    }

    /**
     * The `X::factory` nodes the given edges link.
     *
     * @param  list<array{source: string, target: string, type: string}>  $edges
     * @return array<string, true>
     */
    public static function factoryNodes(array $edges): array
    {
        $nodes = [];

        foreach ($edges as $edge) {
            if ($edge['type'] === self::EDGE_TYPE) {
                $nodes[$edge['source']] = true;

                if (str_ends_with($edge['target'], '::factory')) {
                    $nodes[$edge['target']] = true;
                }
            }
        }

        return $nodes;
    }

    /**
     * Every class-like under `database/factories`, with what its always-run methods create and call.
     *
     * @return array<string, array{parent: string|null, traits: list<string>, model: string|null, creates: list<array{0: 'model'|'factory', 1: string}>, calls: list<string>}>
     */
    private function factoryClasses(): array
    {
        $dir = "{$this->projectRoot}/database/factories";

        if (! is_dir($dir)) {
            return [];
        }

        $records = [];

        foreach (Finder::create()->files()->in($dir)->name('*.php')->sortByName() as $file) {
            $ast = AppFiles::parseResolved((string) file_get_contents($file->getPathname()));

            foreach (new NodeFinder()->findInstanceOf($ast ?? [], ClassLike::class) as $class) {
                $fqcn = $class->namespacedName?->toString();

                if ($fqcn !== null) {
                    $records[$fqcn] = $this->record($class);
                }
            }
        }

        return $records;
    }

    /** @return array{parent: string|null, traits: list<string>, model: string|null, creates: list<array{0: 'model'|'factory', 1: string}>, calls: list<string>} */
    private function record(ClassLike $class): array
    {
        $traits = [];

        foreach ($class->stmts as $statement) {
            if ($statement instanceof TraitUse) {
                foreach ($statement->traits as $trait) {
                    $traits[] = AppFiles::resolveName($trait);
                }
            }
        }

        $creates = [];
        $calls = [];

        foreach (self::ALWAYS_RUN as $name) {
            $body = $class->getMethod($name)->stmts ?? [];
            $found = new NodeFinder()->find($body, static fn (Node $node): bool => $node instanceof StaticCall || $node instanceof New_);

            foreach ($found as $node) {
                $this->read($node, $creates, $calls);
            }
        }

        return [
            'parent' => $class instanceof Class_ && $class->extends instanceof Name ? AppFiles::resolveName($class->extends) : null,
            'traits' => $traits,
            'model' => $this->modelProperty($class),
            'creates' => $creates,
            'calls' => $calls,
        ];
    }

    /**
     * @param  list<array{0: 'model'|'factory', 1: string}>  $creates
     * @param  list<string>  $calls
     */
    private function read(Node $node, array &$creates, array &$calls): void
    {
        if (! $node instanceof StaticCall && ! $node instanceof New_ || ! $node->class instanceof Name || $node->class->isSpecialClassName()) {
            return;
        }

        $class = AppFiles::resolveName($node->class);

        if ($node instanceof New_) {
            if (AppNamespace::isInApp($class)) {
                $calls[] = "{$class}::__construct";
            } else {
                $creates[] = ['factory', $class];
            }

            return;
        }

        if (! $node->name instanceof Identifier) {
            return;
        }

        $method = $node->name->toString();

        match (true) {
            $method === 'factory' => $creates[] = ['model', $class],
            $method === 'new' => $creates[] = ['factory', $class],
            $this->declaredInApp($class, $method) => $calls[] = "{$class}::{$method}",
            default => null,
        };
    }

    private function modelProperty(ClassLike $class): ?string
    {
        foreach ($class->getProperty('model')->props ?? [] as $property) {
            $default = $property->default;

            if ($default instanceof ClassConstFetch && $default->class instanceof Name && $default->name instanceof Identifier && $default->name->toLowerString() === 'class') {
                return AppFiles::resolveName($default->class);
            }
        }

        return null;
    }

    /**
     * What the factory's always-run methods create and call, its ancestors' and traits' included.
     *
     * @param  array<string, array{parent: string|null, traits: list<string>, model: string|null, creates: list<array{0: 'model'|'factory', 1: string}>, calls: list<string>}>  $factories
     * @return array{0: list<array{0: 'model'|'factory', 1: string}>, 1: list<string>}
     */
    private function alwaysRun(string $factory, array $factories): array
    {
        $creates = [];
        $calls = [];
        $pending = [$factory];
        $seen = [];

        while ($pending !== []) {
            $class = array_shift($pending);

            if (isset($seen[$class]) || ! isset($factories[$class])) {
                continue;
            }

            $seen[$class] = true;
            $record = $factories[$class];
            array_push($creates, ...$record['creates']);
            array_push($calls, ...$record['calls']);
            array_push($pending, ...$record['traits']);

            if ($record['parent'] !== null) {
                $pending[] = $record['parent'];
            }
        }

        return [$creates, array_values(array_unique($calls))];
    }

    private function declaredInApp(string $class, string $method): bool
    {
        try {
            return AppNamespace::isInApp($class)
                && (class_exists($class) || enum_exists($class))
                && method_exists($class, $method)
                && AppNamespace::isInApp(new ReflectionMethod($class, $method)->getDeclaringClass()->getName());
        } catch (Throwable) {
            return false;
        }
    }
}
