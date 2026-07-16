<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tracers;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\BinaryOp\Concat;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use SanderMuller\Richter\Support\AppFiles;
use SanderMuller\Richter\Support\Fqcn;
use Symfony\Component\Finder\Finder;
use Throwable;

/**
 * Flags an eager-load/relation string that cannot name a relation on any model. The graph sees a
 * `->load(...)` call, not the string inside it — yet that string is a real defect surface: a missing
 * comma silently concatenates two relation constants into one invalid name Eloquent never resolves.
 * Folds each argument statically (string literals, model constants, concatenation) and checks every
 * dot-segment against the union of all model method names. Only arguments in which a model constant
 * participates are checked — a plain string may target a vendor model this checker cannot know.
 */
final class EagerLoadStringChecker
{
    /**
     * Relation-string-taking methods this checker validates. Bare `has`/`doesntHave` are excluded
     * *here only*: they are heavily overloaded (Request, Session, Collection), and validating a
     * folded string from those against model methods risks a confident false alarm — the tracer in
     * {@see ReferenceEdgeTracer} still follows them for reach, where over-approximation is cheap.
     * `with` is also overloaded (views, redirects) but too central to eager loading to drop — the
     * model-constant gate keeps its noise near zero.
     *
     * @var list<string>
     */
    public const array LOAD_METHODS = ['load', 'loadMissing', 'loadCount', 'with', 'withOnly', 'withCount', 'withWhereHas', 'whereHas', 'orWhereHas', 'whereDoesntHave', 'orWhereDoesntHave'];

    private const string MODEL_NAMESPACE = 'App\\Models\\';

    /**
     * Memoized only when the build was complete — an incomplete set must be retried, not cached
     * across instances. Keyed by models path so checkers pointed at different trees never share a set.
     *
     * @var array<string, array<string, true>>
     */
    private static array $modelMethodsByPath = [];

    /**
     * Per-instance cache so an incomplete build is still computed once per checked file, not once per expression.
     *
     * @var array<string, true>|null
     */
    private ?array $modelMethodsCache = null;

    /** A model class that fails to load would shrink the valid set and fire false alarms — degrade to a visible skip note instead. */
    private bool $modelSetIncomplete = false;

    /** @param  string|null  $modelsPath  the `app/Models` directory to scan; defaults to the consuming app's */
    public function __construct(private readonly ?string $modelsPath = null) {}

    /** @return list<string> findings, phrased for the change author */
    public function findingsFor(string $source): array
    {
        $ast = AppFiles::parse($source);

        if ($ast === null) {
            return [];
        }

        new NodeTraverser(new NameResolver(null, ['preserveOriginalNames' => true, 'replaceNodes' => false]))->traverse($ast);

        $findings = [];

        $finder = new NodeFinder();
        /** @var list<MethodCall|StaticCall> $calls */
        $calls = [...$finder->findInstanceOf($ast, MethodCall::class), ...$finder->findInstanceOf($ast, StaticCall::class)];

        foreach ($calls as $call) {
            if ($call->isFirstClassCallable()) {
                continue;
            }

            if (! $call->name instanceof Identifier) {
                continue;
            }

            if (! in_array($call->name->toString(), self::LOAD_METHODS, strict: true)) {
                continue;
            }

            foreach ($this->relationExpressions($call) as $expression) {
                $findings = [...$findings, ...$this->check($expression)];
            }
        }

        return array_values(array_unique($findings));
    }

    /**
     * @return list<Expr>
     */
    private function relationExpressions(CallLike $call): array
    {
        $expressions = [];

        foreach ($call->getArgs() as $arg) {
            if (! $arg instanceof Arg) {
                continue;
            }

            if (! $arg->value instanceof Array_) {
                $expressions[] = $arg->value;

                continue;
            }

            foreach ($arg->value->items as $item) {
                if ($item->key instanceof Expr) {
                    $expressions[] = $item->key;
                }

                $expressions[] = $item->value;
            }
        }

        return $expressions;
    }

    /** @return list<string> */
    private function check(Expr $expression): array
    {
        $usesModelConstant = false;
        $folded = $this->fold($expression, $usesModelConstant);

        if ($folded === null || ! $usesModelConstant) {
            return [];
        }

        $methods = $this->modelMethods();

        // Validating against an incomplete set would fire false alarms — skip, but say so: an
        // invisible degradation would silently disable this detector for the whole report.
        if ($this->modelSetIncomplete) {
            return ['eager-load check skipped: a model class failed to load, so relation names could not be verified this run'];
        }

        $findings = [];

        foreach (explode('.', $folded) as $segment) {
            // `relation:id,name` column selection — the relation is the part before the colon.
            $beforeColon = strstr($segment, ':', before_needle: true);
            $relation = $beforeColon === false || $beforeColon === '' ? $segment : $beforeColon;
            // An alias form (`relation as alias`) or empty segment is out of scope for this check.
            if ($relation === '') {
                continue;
            }

            if (str_contains($relation, ' ')) {
                continue;
            }

            if (! isset($methods[$relation])) {
                $findings[] = "eager-load string '{$folded}': segment '{$relation}' is not a method on any model — check the relation name (a broken constant concatenation reads exactly like this)";
            }
        }

        return $findings;
    }

    /**
     * Statically evaluate the expression to its relation string, or null when any part is dynamic.
     * Sets the flag when a model constant participates — the signal that the string targets an app
     * model and is therefore checkable.
     */
    private function fold(Expr $expression, bool &$usesModelConstant): ?string
    {
        if ($expression instanceof String_) {
            return $expression->value;
        }

        if ($expression instanceof Concat) {
            $left = $this->fold($expression->left, $usesModelConstant);
            $right = $this->fold($expression->right, $usesModelConstant);

            return $left === null || $right === null ? null : $left . $right;
        }

        if ($expression instanceof ClassConstFetch && $expression->class instanceof Name && $expression->name instanceof Identifier) {
            $class = AppFiles::resolveName($expression->class);
            $value = AppFiles::stringConstantValue($class, $expression->name->toString());

            if ($value !== null && str_starts_with($class, self::MODEL_NAMESPACE)) {
                $usesModelConstant = true;
            }

            return $value;
        }

        return null;
    }

    /**
     * Union of the method names of every `App\Models` class — a valid relation segment must be one.
     * Deliberately broad (any model, any method): a false "valid" is fine for an advisory check, a
     * false alarm is not.
     *
     * @return array<string, true>
     */
    private function modelMethods(): array
    {
        $modelsPath = $this->modelsPath ?? base_path('app/Models');

        if (isset(self::$modelMethodsByPath[$modelsPath])) {
            return self::$modelMethodsByPath[$modelsPath];
        }

        if ($this->modelMethodsCache !== null) {
            return $this->modelMethodsCache;
        }

        $methods = [];
        $incomplete = false;

        // No app/Models directory means the set cannot be built at all — the model-constant gate
        // already proved a model class is autoloadable, so treat it as incomplete, not as "no methods".
        if (! is_dir($modelsPath)) {
            $this->modelSetIncomplete = true;

            return $this->modelMethodsCache = [];
        }

        foreach (Finder::create()->files()->in($modelsPath)->name('*.php') as $file) {
            $fqcn = Fqcn::fromPath('app/Models/' . $file->getRelativePathname());

            try {
                if (! class_exists($fqcn)) {
                    // Interface/trait files under app/Models contribute no methods but don't
                    // invalidate the set — only a load *failure* does. (Enums and abstract classes
                    // pass class_exists and never reach this branch.)
                    if (! interface_exists($fqcn) && ! trait_exists($fqcn)) {
                        $incomplete = true;
                    }

                    continue;
                }
            } catch (Throwable) {
                $incomplete = true;

                continue;
            }

            foreach (get_class_methods($fqcn) as $method) {
                $methods[$method] = true;
            }
        }

        $this->modelSetIncomplete = $incomplete;

        // Memoize only a complete build statically — caching an incomplete set would disable the
        // checker for the process lifetime (queue worker, MCP server) even after the transient
        // failure clears. The instance cache bounds the retry to once per checked file.
        if (! $incomplete) {
            self::$modelMethodsByPath[$modelsPath] = $methods;
        }

        return $this->modelMethodsCache = $methods;
    }
}
