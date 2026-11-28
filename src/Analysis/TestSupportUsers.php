<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

use PhpParser\Node\Expr\BooleanNot;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Declare_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\Node\Stmt\GroupUse;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Namespace_;
use PhpParser\Node\Stmt\Nop;
use PhpParser\Node\Stmt\Use_;
use SanderMuller\Richter\Support\AppFiles;

/**
 * Which test classes use a support file under `tests/`: a trait or base class the tests `use` or
 * extend, a class they import (a browser test's page object), or one they name in a Pest `uses()`
 * call or a `pest()->extend()->use()` chain. A route that only such a file names is still driven by
 * each test that uses it, so {@see AffectedTests} can select those tests instead of the whole suite.
 *
 * A file of helper functions is used by the files that call its functions: its code runs only then.
 * A helper file with top-level code is not followed. That code runs wherever the file is loaded, and
 * Pest loads `tests/Helpers.php` and `tests/Datasets/` with no include to find.
 *
 * A regex scan, like the rest of {@see TestReferenceIndex}. Names resolve against the file's
 * namespace and its `use` imports.
 *
 * @internal
 */
final class TestSupportUsers
{
    /** @var array<string, string> declared FQCN => the file that declares it */
    private array $declaredIn = [];

    /** @var array<string, list<string>> FQCN => the files that use or extend it */
    private array $usedBy = [];

    /**
     * @var array<string, true> files that are not tests, use a support class, and declare no class
     *   of their own, such as `tests/Pest.php`
     */
    private array $classless = [];

    /** @var array<string, list<string>> file => the functions it declares, lower case */
    private array $functionsIn = [];

    /** @var array<string, list<string>> lower-case name => the files that call it, or name it in a string */
    private array $callers = [];

    /** Call-shaped words that are no function call. */
    private const array NOT_CALLS = ['array', 'catch', 'declare', 'elseif', 'empty', 'fn', 'for', 'foreach', 'function', 'if', 'include', 'include_once', 'isset', 'list', 'match', 'require', 'require_once', 'return', 'switch', 'unset', 'use', 'while'];

    public function add(string $source, string $file): void
    {
        $namespace = preg_match('/^namespace\s+([\w\\\\]+)\s*;/m', $source, $match) === 1 ? $match[1] . '\\' : '';
        $imports = $this->imports($source);
        $resolve = static function (string $name) use ($namespace, $imports): string {
            if (str_starts_with($name, '\\')) {
                return ltrim($name, '\\');
            }

            $first = explode('\\', $name, 2)[0];

            return isset($imports[$first]) ? $imports[$first] . substr($name, strlen($first)) : $namespace . $name;
        };

        $declares = false;

        if (preg_match_all('/^\s*(?:(?:abstract|final|readonly)\s+)*(?:class|trait)\s+(\w+)(?:\s+extends\s+([\w\\\\]+))?/m', $source, $declarations, PREG_SET_ORDER) > 0) {
            foreach ($declarations as $declaration) {
                $declares = true;
                $this->declaredIn[$namespace . $declaration[1]] = $file;

                if (($declaration[2] ?? '') !== '') {
                    $this->usedBy[$resolve($declaration[2])][] = $file;
                }
            }
        }

        $used = $this->traitUses($source);

        foreach ($imports as $imported) {
            $this->usedBy[$imported][] = $file;
        }

        foreach ($used as $name) {
            $this->usedBy[$resolve($name)][] = $file;
        }

        if (! $declares && $used !== [] && TestReferenceIndex::runnableOnly([$file]) === []) {
            $this->classless[$file] = true;
        }

        $this->addFunctionUse($source, $file);
    }

    /** Whether the file declares a class or trait, so that its users can be followed. */
    public function declaresClass(string $file): bool
    {
        return in_array($file, $this->declaredIn, strict: true);
    }

    /** Whether a file is a test, or declares a class, a trait or a function whose users can be followed. */
    private function followable(string $file): bool
    {
        if (isset($this->classless[$file])) {
            return false;
        }

        return TestReferenceIndex::runnableOnly([$file]) !== [] || $this->declaresClass($file) || isset($this->functionsIn[$file]);
    }

    /**
     * The runnable test files that use, directly or through another support file, a class one of
     * the given files declares, or call a function one of them declares. Null when a user cannot be
     * mapped to test files: a file that is no test and declares nothing to follow, such as a Pest
     * `uses()->in()` call in `tests/Pest.php` or a Pest dataset file, applies to tests this scan does
     * not resolve. That holds for a given file as much as for a user found on the way.
     *
     * @param  list<string>  $files
     * @return list<string>|null
     */
    public function runnableUsersOf(array $files): ?array
    {
        if (array_any($files, fn (string $file): bool => ! $this->followable($file))) {
            return null;
        }

        $pending = $files;
        $seen = [];
        $users = [];

        while ($pending !== []) {
            $file = array_shift($pending);

            if (isset($seen[$file])) {
                continue;
            }

            $seen[$file] = true;

            foreach ($this->dependentsOf($file) as $user) {
                if (! $this->followable($user)) {
                    return null;
                }

                $users[$user] = true;
                $pending[] = $user;
            }
        }

        return TestReferenceIndex::runnableOnly(array_keys($users));
    }

    /**
     * The files whose code runs the given file's code: the users of the classes it declares and the
     * callers of its functions.
     *
     * @return list<string>
     */
    private function dependentsOf(string $file): array
    {
        $dependents = [];

        foreach (array_keys($this->declaredIn, $file, strict: true) as $class) {
            array_push($dependents, ...$this->usedBy[$class] ?? []);
        }

        foreach ($this->functionsIn[$file] ?? [] as $function) {
            array_push($dependents, ...$this->callers[$function] ?? []);
        }

        return array_values(array_diff(array_unique($dependents), [$file]));
    }

    /**
     * Indexes the function calls and string callables in a file, and the functions it declares. The
     * calls are read as words: a call through a variable or a method of the same name is no call,
     * and a function name in a string can be a callable.
     */
    private function addFunctionUse(string $source, string $file): void
    {
        // A call, not a method or a call through a variable, and the last part of a namespaced
        // string callable.
        if (preg_match_all('/(?<!->)(?<!::)(?<![\w$\\\\])(?:[\w\\\\]+\\\\)?(\w+)\s*\(|[\'"](?:[\w\\\\]+\\\\)?(\w+)[\'"]/', $source, $words, PREG_SET_ORDER) > 0) {
            $called = [];

            foreach ($words as $word) {
                $called[strtolower(implode('', array_slice($word, 1)))] = true;
            }

            // Every name in a `use function` statement, so an alias or a group still counts.
            if (preg_match_all('/^use\s+function\s+([^;]+);/m', $source, $imports) > 0 && preg_match_all('/\w+/', implode(' ', $imports[1]), $names) > 0) {
                foreach ($names[0] as $name) {
                    $called[strtolower($name)] = true;
                }
            }

            foreach (array_keys(array_diff_key($called, array_flip(self::NOT_CALLS))) as $name) {
                $this->callers[(string) $name][] = $file;
            }
        }

        if (preg_match('/^\s*function\s+\w+\s*\(/m', $source) !== 1) {
            return;
        }

        $statements = AppFiles::parse($source);
        $functions = [];

        if ($statements === null) {
            return;
        }

        // A test runs its own top-level code, and its callers are followed like any helper's.
        if ((! self::runsCode($statements, $functions) || TestReferenceIndex::runnableOnly([$file]) !== []) && $functions !== []) {
            $this->functionsIn[$file] = $functions;
        }
    }

    /**
     * Collects the functions the statements declare, and says whether anything else runs. A
     * namespace, an import, a declaration, and a function behind a `function_exists()` guard run
     * nothing on include.
     *
     * @param  array<Stmt>  $statements
     * @param  list<string>  $functions
     * @param-out list<string>  $functions
     */
    private static function runsCode(array $statements, array &$functions): bool
    {
        $runs = false;

        foreach ($statements as $statement) {
            $runs = match (true) {
                $statement instanceof Function_ => self::declares($functions, $statement) || $runs,
                $statement instanceof Namespace_ => self::runsCode($statement->stmts, $functions) || $runs,
                $statement instanceof If_ && self::isFunctionExistsGuard($statement) => self::runsCode($statement->stmts, $functions) || $runs,
                $statement instanceof Use_, $statement instanceof GroupUse, $statement instanceof Declare_, $statement instanceof ClassLike, $statement instanceof Nop => $runs,
                default => true,
            };
        }

        return $runs;
    }

    /**
     * @param  list<string>  $functions
     * @param-out list<string>  $functions
     */
    private static function declares(array &$functions, Function_ $function): bool
    {
        $functions[] = $function->name->toLowerString();

        return false;
    }

    /** `if (! function_exists('x')) { function x() … }`, with no other branch. */
    private static function isFunctionExistsGuard(If_ $statement): bool
    {
        return $statement->elseifs === []
            && $statement->else === null
            && $statement->cond instanceof BooleanNot
            && $statement->cond->expr instanceof FuncCall
            && $statement->cond->expr->name instanceof Name
            && $statement->cond->expr->name->toLowerString() === 'function_exists'
            && array_all($statement->stmts, static fn (Stmt $stmt): bool => $stmt instanceof Function_);
    }

    /** @return array<string, string> alias => imported FQCN */
    private function imports(string $source): array
    {
        $imports = [];

        if (preg_match_all('/^use\s+\\\\?([\w\\\\]+)(?:\s+as\s+(\w+))?\s*;/mi', $source, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as $match) {
                $segments = explode('\\', $match[1]);
                $imports[($match[2] ?? '') !== '' ? $match[2] : end($segments)] = $match[1];
            }
        }

        return $imports;
    }

    /**
     * The names in an indented `use A, B;` inside a class body, and in a Pest `uses(A::class)`.
     *
     * @return list<string>
     */
    private function traitUses(string $source): array
    {
        $names = [];

        if (preg_match_all('/^[ \t]+use\s+([\w\\\\]+(?:\s*,\s*[\w\\\\]+)*)\s*[;{]/m', $source, $matches) > 0) {
            foreach ($matches[1] as $list) {
                array_push($names, ...array_map(trim(...), explode(',', $list)));
            }
        }

        // `uses(A::class)`, and the same binding in the chained form: `pest()->extend(A::class)`,
        // `->use(B::class)`. A file that only binds this way must still read as a user of A, or the
        // tests under the directory it binds are missing from A's users with nothing to say so.
        if (preg_match_all('/(?:\buses|->(?:extend|extends|use|uses))\(([^;]*?)\)/', $source, $calls) > 0) {
            foreach ($calls[1] as $arguments) {
                if (preg_match_all('/([\w\\\\]+)::class/', $arguments, $classes) > 0) {
                    array_push($names, ...$classes[1]);
                }
            }
        }

        return $names;
    }
}
