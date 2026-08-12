<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tracers;

use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\InterpolatedStringPart;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\InterpolatedString;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use SanderMuller\Richter\Analysis\ImpactAnalyzer;
use SanderMuller\Richter\Support\AppFiles;
use SanderMuller\Richter\Support\AppNamespace;
use Symfony\Component\Finder\Finder;

/**
 * A subsystem dispatched through a config-keyed class registry is reachable from nothing.
 *
 * The shape: `config/calculators.php` names app classes as `::class` constants, and a method
 * resolves one with `config("calculators.{$id}")`. Nothing statically connects the two, so every
 * class in the registry has no caller — a change to one reports zero entry points however central it
 * is, and a registry this shape can name hundreds of classes.
 *
 * The registry's KEYS are frequently not enumerable (such a file may build them by looping the class
 * list and calling a static method on each), and the call's key is dynamic anyway. Its VALUES are: a
 * `::class` constant resolves through the config file's own `namespace` declaration like any other
 * name. So the lane matches at file granularity — a `config('calculators…')` call reaches every app
 * class the file `config/calculators.php` names — and does not try to pick one.
 *
 * That is an over-approximation, deliberately, and the same one {@see ClassHierarchyTracer} makes
 * for polymorphic dispatch: a runtime-chosen target is honestly "any of these". Like `override`, the
 * edge is therefore excluded from the risk count ({@see ImpactAnalyzer::RISK_EXCLUDED_EDGE_TYPES}) —
 * it carries reach and entry-point discovery, which is the whole point, without letting one edit to
 * the resolver saturate the level on fan-out alone.
 *
 * A deeper static key (`config('services.stripe.class')`) does not narrow the match yet; the file's
 * whole class list is used. Cohesive config files make that harmless, and narrowing needs the array
 * literal evaluated, which the registry shape above defeats anyway.
 *
 * Dev/CI tooling only.
 */
final class ConfigRegistryTracer
{
    /** @var array<string, list<string>>|null config file basename (no extension) => the app classes it names */
    private ?array $registries = null;

    public function __construct(private readonly string $projectRoot) {}

    /**
     * The app classes each `config/*.php` file names, read once per build.
     *
     * @return array<string, list<string>>
     */
    public function registries(): array
    {
        if ($this->registries !== null) {
            return $this->registries;
        }

        $registries = [];
        $directory = rtrim($this->projectRoot, '/') . '/config';

        if (! is_dir($directory)) {
            return $this->registries = [];
        }

        foreach (Finder::create()->files()->in($directory)->depth(0)->name('*.php') as $file) {
            $classes = $this->appClassesIn((string) file_get_contents($file->getPathname()));

            if ($classes !== []) {
                $registries[$file->getBasename('.php')] = $classes;
            }
        }

        return $this->registries = $registries;
    }

    /**
     * The edges one file's class-likes contribute: caller member → every app class the config file
     * its `config()` call names.
     *
     * Class-scoped rather than fed the file's flat method bucket, for the reason
     * {@see StaticCallEdgeTracer} gives: a second class in the same file would otherwise have its
     * lookups attributed to the first, and the whole registry would hang off a caller that never
     * reads the config.
     *
     * @param  list<ClassLike>  $classLikes  every class-like in the file, any depth
     * @param  string  $fallbackFqcn  used for an anonymous class, which carries no resolved name
     * @return list<array{source: string, target: string, type: string}>
     */
    public function edgesForClassLikes(array $classLikes, string $fallbackFqcn): array
    {
        $registries = $this->registries();

        if ($registries === []) {
            return [];
        }

        $edges = [];

        foreach ($classLikes as $classLike) {
            $fqcn = ltrim($classLike->namespacedName?->toString() ?? $fallbackFqcn, '\\');

            foreach ($classLike->getMethods() as $method) {
                $source = $fqcn . '::' . $method->name->toString();

                foreach ($this->configFilesRead($method) as $file) {
                    foreach ($registries[$file] ?? [] as $target) {
                        $edges[] = ['source' => $source, 'target' => $target, 'type' => 'config-registry'];
                    }
                }
            }
        }

        return AppFiles::dedupeEdges($edges, byType: true);
    }

    /**
     * The config file names a method reads, from `config('file.key')` and `Config::get('file.key')`.
     * An interpolated key (`"calculators.{$id}"`) still names its file in the leading literal part,
     * which is the case this lane exists for; a fully dynamic argument names nothing and is skipped.
     *
     * @return list<string>
     */
    private function configFilesRead(ClassMethod $method): array
    {
        $files = [];

        foreach (new NodeFinder()->find($method, $this->isConfigRead(...)) as $call) {
            /** @var FuncCall|StaticCall $call */
            $argument = ($call->getArgs()[0] ?? null)?->value;
            $key = $this->leadingLiteral($argument);

            if ($key === null) {
                continue;
            }

            $file = explode('.', $key, 2)[0];

            if ($file !== '') {
                $files[$file] = true;
            }
        }

        return array_keys($files);
    }

    private function isConfigRead(mixed $node): bool
    {
        if ($node instanceof FuncCall) {
            return $node->name instanceof Name && $node->name->toLowerString() === 'config' && $node->getArgs() !== [];
        }

        return $node instanceof StaticCall
            && $node->name instanceof Identifier
            && $node->name->toLowerString() === 'get'
            && $node->class instanceof Name
            && AppFiles::resolveName($node->class) === 'Illuminate\\Support\\Facades\\Config'
            && $node->getArgs() !== [];
    }

    /** The statically known head of the key: the whole string, or the literal prefix of an interpolated one. */
    private function leadingLiteral(mixed $argument): ?string
    {
        if ($argument instanceof String_) {
            return $argument->value;
        }

        if (! $argument instanceof InterpolatedString) {
            return null;
        }

        $first = $argument->parts[0] ?? null;

        return $first instanceof InterpolatedStringPart ? $first->value : null;
    }

    /**
     * Every app class a config file names as a `::class` constant. Parsed with name resolution, so a
     * file declaring `namespace App\Calculators;` and listing `Basic::class` yields the full FQCN —
     * the shape that makes this lane possible at all.
     *
     * @return list<string>
     */
    private function appClassesIn(string $source): array
    {
        $ast = AppFiles::parseResolved($source);

        if ($ast === null) {
            return [];
        }

        $classes = [];

        foreach (new NodeFinder()->findInstanceOf($ast, ClassConstFetch::class) as $fetch) {
            if (! $fetch->name instanceof Identifier) {
                continue;
            }

            if ($fetch->name->toString() !== 'class') {
                continue;
            }

            if (! $fetch->class instanceof Name) {
                continue;
            }

            $fqcn = AppFiles::resolveName($fetch->class);

            if (AppNamespace::isInApp($fqcn)) {
                $classes[$fqcn] = true;
            }
        }

        return array_keys($classes);
    }
}
