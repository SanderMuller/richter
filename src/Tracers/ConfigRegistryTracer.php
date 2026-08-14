<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tracers;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
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
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Return_;
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
 * A registry's KEYS are frequently not enumerable (such a file may build them by looping the class
 * list and calling a static method on each), and an interpolated key is dynamic anyway. Its VALUES
 * are: a `::class` constant resolves through the config file's own `namespace` declaration like any
 * other name. So for a key this cannot enumerate, the lane matches at FILE granularity — a
 * `config("calculators.{$id}")` call reaches every app class `config/calculators.php` names.
 *
 * That is an over-approximation, deliberately, and the same one {@see ClassHierarchyTracer} makes
 * for polymorphic dispatch: a runtime-chosen target is honestly "any of these". Like `override`, the
 * edge is therefore excluded from the risk count ({@see ImpactAnalyzer::RISK_EXCLUDED_EDGE_TYPES}) —
 * it carries reach and entry-point discovery, which is the whole point, without letting one edit to
 * the resolver saturate the level on fan-out alone.
 *
 * A FULLY LITERAL key gets none of that latitude, because it needs none: the key is knowable at
 * build time, so {@see targetsFor()} looks it up in the file and draws only what that value names.
 * The first release of this lane skipped the lookup and over-approximated on every key alike, on the
 * reasoning that cohesive config files made it harmless. `config/app.php` is the counter-example
 * every Laravel application ships: it names app classes in its `aliases` map, so an ordinary
 * `config('app.timezone')` fanned out into all of them, and from there through their real edges,
 * multiplying the reported reach of a routine change several times over with no true positive
 * behind any of it. An over-approximation is only defensible where the alternative is a guess; here
 * the answer was sitting in the file.
 *
 * Dev/CI tooling only.
 */
final class ConfigRegistryTracer
{
    /** @var array<string, list<string>>|null config file basename (no extension) => the app classes it names */
    private ?array $registries = null;

    /** @var array<string, Array_|null> the same files' top-level `return [...]`, for key-path lookup */
    private array $returnedArrays = [];

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
            $ast = AppFiles::parseResolved((string) file_get_contents($file->getPathname()));
            $classes = $ast === null ? [] : $this->appClassesIn($ast);

            if ($classes !== []) {
                $name = $file->getBasename('.php');
                $registries[$name] = $classes;
                $this->returnedArrays[$name] = $this->returnedArray($ast);
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
     * An anonymous class is skipped: it has no name to be an edge source, and inventing one from the
     * file's primary class mints a member that may not exist — a caller a reviewer opens and cannot
     * find. Its lookups are not lost, they are attributed to the method that builds it, which is where
     * they belong ({@see AppFiles::nodesOwnedBy()}).
     *
     * @param  list<ClassLike>  $classLikes  every class-like in the file, any depth
     * @return list<array{source: string, target: string, type: string}>
     */
    public function edgesForClassLikes(array $classLikes): array
    {
        $registries = $this->registries();

        if ($registries === []) {
            return [];
        }

        $edges = [];

        foreach ($classLikes as $classLike) {
            $fqcn = $classLike->namespacedName?->toString();

            if ($fqcn === null) {
                continue;
            }

            $fqcn = ltrim($fqcn, '\\');

            foreach ($classLike->getMethods() as $method) {
                $source = $fqcn . '::' . $method->name->toString();

                foreach ($this->configFilesRead($method) as ['file' => $file, 'path' => $path]) {
                    foreach ($this->targetsFor($file, $path) as $target) {
                        $edges[] = ['source' => $source, 'target' => $target, 'type' => 'config-registry'];
                    }
                }
            }
        }

        return AppFiles::dedupeEdges($edges, byType: true);
    }

    /**
     * The classes one `config()` read reaches.
     *
     * A **fully literal** key is knowable at build time, so nothing has to be guessed: look the key
     * up in the file's own returned array and draw only the app classes that key's value actually
     * names. This is what keeps an ordinary scalar read out of the lane — `config('app.timezone')`
     * resolves to a string, so it draws nothing, even though `config/app.php` names app classes
     * elsewhere in its `aliases` map. Without the lookup that read fanned out into every one of them.
     *
     * The file's whole class list is used in the two cases where the key genuinely cannot be
     * enumerated: an interpolated key (`config("calculators.{$id}")`, the shape the lane exists
     * for), and a file whose array this cannot walk — built by a loop, spread from a default, or
     * keyed by a constant. Falling back to over-approximation there is the safe direction; the lane
     * adds reach, so drawing nothing would be the under-report.
     *
     * @return list<string>
     */
    private function targetsFor(string $file, ?string $path): array
    {
        $all = $this->registries()[$file] ?? [];

        if ($all === [] || $path === null || $path === '') {
            return $all;
        }

        return $this->classesAtPath($file, $path) ?? $all;
    }

    /**
     * The app classes the value at a dot-separated key path names, or null when the array cannot be
     * walked far enough to be sure. An empty list is a real answer: the key was found and names no
     * class. Null is "cannot tell", and the caller over-approximates on it.
     *
     * @return list<string>|null
     */
    private function classesAtPath(string $file, string $path): ?array
    {
        $node = $this->returnedArrays[$file] ?? null;

        foreach (explode('.', $path) as $segment) {
            if (! $node instanceof Array_) {
                return null;
            }

            ['value' => $next, 'certain' => $certain] = $this->itemValue($node, $segment);

            if (! $certain) {
                return null;
            }

            // Certain, and nothing there: the key is genuinely absent.
            if (! $next instanceof Expr) {
                return [];
            }

            $node = $next;
        }

        return $this->appClassesIn([new Return_($node)]);
    }

    /**
     * The value an array literal ends up holding under a key, and whether that answer is certain.
     *
     * Two ways PHP's own semantics defeat a naive first-match scan, both of which produce a
     * confidently wrong edge rather than a missing one:
     *
     * - A **repeated key** is legal, and the array keeps the LAST value. Returning the first would
     *   resolve to something the application never sees.
     * - A **spread or computed key** can set any key at all, so anything it follows may have been
     *   overwritten. Only entries positioned AFTER the matched one can do that, which is why this
     *   tracks position rather than rejecting the whole array: `['a' => X::class, ...$extra]` is
     *   uncertain, while `[...$extra, 'a' => X::class]` is not.
     *
     * Uncertain is reported as such and the caller falls back to the file's whole class list, the
     * safe direction for a lane that adds reach.
     *
     * @return array{value: Expr|null, certain: bool}
     */
    private function itemValue(Array_ $array, string $key): array
    {
        $value = null;
        $certain = true;

        foreach ($array->items as $item) {
            if ($item->unpack || ! $item->key instanceof String_) {
                $certain = false;

                continue;
            }

            if ($item->key->value === $key) {
                $value = $item->value;
                $certain = true;
            }
        }

        return ['value' => $value, 'certain' => $certain];
    }

    /**
     * The config file each `config('file.key')` / `Config::get('file.key')` read names, with the
     * remainder of the key when the whole string is literal. An interpolated key still names its
     * file in the leading literal part but carries no resolvable path; a fully dynamic argument
     * names nothing and is skipped.
     *
     * @return list<array{file: string, path: string|null}>
     */
    private function configFilesRead(ClassMethod $method): array
    {
        $reads = [];

        foreach (AppFiles::nodesOwnedBy($method, $this->isConfigRead(...)) as $call) {
            /** @var FuncCall|StaticCall $call */
            $key = $this->leadingLiteral(($call->getArgs()[0] ?? null)?->value);

            if ($key === null) {
                continue;
            }

            $segments = explode('.', $key['value'], 2);
            $file = $segments[0];
            $path = $segments[1] ?? null;

            if ($file === '') {
                continue;
            }

            // Deduped on the PAIR, not the file: two reads of different keys in one file are two
            // different answers, and collapsing them on the file name would drop one of them.
            $path = $key['complete'] ? $path : null;
            $reads[$file . "\0" . $path] = ['file' => $file, 'path' => $path];
        }

        return array_values($reads);
    }

    /**
     * Whether this source can possibly read config — a cheap pre-check before the AST walk.
     *
     * Both matched forms, `config(...)` and `Config::get(...)`, contain the word; a file without it
     * cannot produce a registry edge. Widen this if the matcher grows a third form.
     */
    public static function mayMatch(string $source): bool
    {
        return stripos($source, 'config') !== false;
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

    /**
     * The statically known head of the key, and whether that is the whole of it. `complete` is what
     * separates a key that can be looked up from one that only names its file.
     *
     * @return array{value: string, complete: bool}|null
     */
    private function leadingLiteral(mixed $argument): ?array
    {
        if ($argument instanceof String_) {
            return ['value' => $argument->value, 'complete' => true];
        }

        if (! $argument instanceof InterpolatedString) {
            return null;
        }

        $first = $argument->parts[0] ?? null;

        return $first instanceof InterpolatedStringPart
            ? ['value' => $first->value, 'complete' => false]
            : null;
    }

    /**
     * The `return [...]` a config file ends on, or null when it returns anything else.
     *
     * A `namespace` declaration wraps the whole file in one node, so the return is a level deeper —
     * and that is not an exotic shape here but the documented one: this lane exists because a config
     * file's own namespace is what resolves its bare `::class` constants. Missing it made every
     * literal lookup in such a file read as unwalkable, which fell back to the whole class list and
     * quietly restored the fan-out the lookup is there to prevent.
     *
     * Only these two levels are searched. A recursive walk would also find a `return` inside a
     * closure the file builds, which is not the file's own value.
     *
     * @param  array<Node>  $ast
     */
    private function returnedArray(array $ast): ?Array_
    {
        foreach ($ast as $statement) {
            $candidates = $statement instanceof Namespace_ ? $statement->stmts : [$statement];

            foreach ($candidates as $candidate) {
                if ($candidate instanceof Return_ && $candidate->expr instanceof Array_) {
                    return $candidate->expr;
                }
            }
        }

        return null;
    }

    /**
     * Every app class the given nodes name as a `::class` constant. Parsed with name resolution, so
     * a file declaring `namespace App\Calculators;` and listing `Basic::class` yields the full FQCN —
     * the shape that makes this lane possible at all.
     *
     * @param  array<Node>  $ast
     * @return list<string>
     */
    private function appClassesIn(array $ast): array
    {
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
