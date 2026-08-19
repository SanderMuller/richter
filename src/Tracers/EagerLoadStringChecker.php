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
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\NodeFinder;
use SanderMuller\Richter\Support\AppFiles;
use SanderMuller\Richter\Support\AppNamespace;
use SanderMuller\Richter\Support\Fqcn;
use SanderMuller\Richter\Support\RelationIndex;
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

    /** `{root}Models\` — derived, so a non-`App\`-rooted app's models still gate through. */
    private function modelNamespace(): string
    {
        return AppNamespace::qualify('Models\\');
    }

    /**
     * Per-instance cache so the model scan runs once per instance, not once per expression.
     * Deliberately not static: a process-lifetime cache (queue worker, MCP server) would keep
     * serving a stale set after a relation is added mid-session, turning the new relation into a
     * confident false alarm. Callers that check many files share one instance per run instead —
     * {@see ChangedSymbols::resolve()} — so the scan cost stays once-per-run and every run is fresh.
     *
     * @var array<string, true>|null
     */
    private ?array $modelMethodsCache = null;

    /** A model class that fails to load would shrink the valid set and fire false alarms — degrade to a visible skip note instead. */
    private bool $modelSetIncomplete = false;

    /** Same per-instance, per-run caching as {@see $modelMethodsCache}, and for the same staleness reason. */
    private ?RelationIndex $relationIndex = null;

    /** @param  string|null  $modelsPath  the `app/Models` directory to scan; defaults to the consuming app's */
    public function __construct(private readonly ?string $modelsPath = null) {}

    /** @return list<string> findings, phrased for the change author */
    public function findingsFor(string $source): array
    {
        $ast = AppFiles::parseResolved($source);

        if ($ast === null) {
            return [];
        }

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
        $rootModel = null;
        $folded = $this->fold($expression, $usesModelConstant, $rootModel);

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
        // The model each segment is read against, while the chain still resolves. Null means the
        // walk lost the receiver, and the union check below stands in from there on.
        $model = $rootModel;

        foreach (explode('.', $folded) as $segment) {
            // `relation:id,name` column selection — the relation is the part before the colon.
            $beforeColon = strstr($segment, ':', before_needle: true);
            $relation = $beforeColon === false || $beforeColon === '' ? $segment : $beforeColon;
            // An alias form (`relation as alias`) or empty segment is out of scope for this check.
            if ($relation === '') {
                $model = null;

                continue;
            }

            if (str_contains($relation, ' ')) {
                $model = null;

                continue;
            }

            $resolved = $model === null ? null : $this->relations()->relationOf($model, $relation);

            if ($resolved !== null) {
                // Resolved against the model that segment really belongs to — an eager-load path
                // keeps walking through a to-many hop, since `comments.author` does mean the author
                // of each comment.
                $model = $resolved['related'];

                continue;
            }

            if ($model !== null && $this->relations()->declaresAnyRelation($model) && $this->relations()->isRelationName($relation)) {
                // The receiver is a model this index knows, it has no such relation, and the name IS
                // a relation somewhere else: the string names a real relation on the wrong model, so
                // the finding can name the model instead of the whole application.
                //
                // The last test is what keeps this from firing on a relation written in a shape the
                // index cannot read (a string class argument, a macro). Such a method reads as "not
                // a relation here" too, and a false alarm costs this checker more than a false pass.
                $findings[] = "eager-load string '{$folded}': segment '{$relation}' is not a relation on {$model} — check the relation name (a broken constant concatenation reads exactly like this)";

                return $findings;
            }

            // No receiver, or one the index cannot speak for: fall back to the broad check.
            $model = null;

            if (! isset($methods[$relation])) {
                $findings[] = "eager-load string '{$folded}': segment '{$relation}' is not a method on any model — check the relation name (a broken constant concatenation reads exactly like this)";
            }
        }

        return $findings;
    }

    /**
     * The relation map for the app's models, built once per instance from source rather than by
     * reflection: a relation's target is in the `hasMany(X::class)` argument, which no reflection
     * call reports. Kept beside {@see modelMethods()} rather than replacing it — that union comes
     * from `get_class_methods()`, so it sees inherited and vendor-supplied methods this scan cannot.
     */
    private function relations(): RelationIndex
    {
        if ($this->relationIndex instanceof RelationIndex) {
            return $this->relationIndex;
        }

        $index = new RelationIndex();
        $modelsPath = $this->modelsPath ?? base_path('app/Models');

        if (! is_dir($modelsPath)) {
            return $this->relationIndex = $index;
        }

        foreach (Finder::create()->files()->in($modelsPath)->name('*.php') as $file) {
            $ast = AppFiles::parseResolved((string) file_get_contents($file->getPathname()));

            if ($ast !== null) {
                $index->collect(array_values(new NodeFinder()->findInstanceOf($ast, ClassLike::class)));
            }
        }

        return $this->relationIndex = $index;
    }

    /**
     * Statically evaluate the expression to its relation string, or null when any part is dynamic.
     * Sets the flag when a model constant participates — the signal that the string targets an app
     * model and is therefore checkable — and names the FIRST such model, which is the receiver the
     * path's segments resolve against ({@see ReferenceEdgeTracer} reads a receiver the same way).
     */
    private function fold(Expr $expression, bool &$usesModelConstant, ?string &$rootModel = null): ?string
    {
        if ($expression instanceof String_) {
            return $expression->value;
        }

        if ($expression instanceof Concat) {
            $left = $this->fold($expression->left, $usesModelConstant, $rootModel);
            $right = $this->fold($expression->right, $usesModelConstant, $rootModel);

            return $left === null || $right === null ? null : $left . $right;
        }

        if ($expression instanceof ClassConstFetch && $expression->class instanceof Name && $expression->name instanceof Identifier) {
            $class = AppFiles::resolveName($expression->class);
            $value = AppFiles::stringConstantValue($class, $expression->name->toString());

            if ($value !== null && str_starts_with($class, $this->modelNamespace())) {
                $usesModelConstant = true;
                $rootModel ??= $class;
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

        return $this->modelMethodsCache = $methods;
    }
}
