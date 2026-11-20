<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

/**
 * Which test classes use a support file under `tests/`: a trait or base class the tests `use` or
 * extend, a class they import (a browser test's page object), or one they name in a Pest `uses()`
 * call. A route that only such a file names is still driven by
 * each test that uses it, so {@see AffectedTests} can select those tests instead of the whole suite.
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
    }

    /**
     * The runnable test files that use, directly or through another support file, a class one of
     * the given files declares. Null when a user cannot be mapped to test files: a file that
     * declares no class, such as a Pest `uses()->in()` call in `tests/Pest.php`, applies the class
     * to a directory this scan does not resolve.
     *
     * @param  list<string>  $files
     * @return list<string>|null
     */
    public function runnableUsersOf(array $files): ?array
    {
        $pending = [];

        foreach ($files as $file) {
            array_push($pending, ...array_keys($this->declaredIn, $file, strict: true));
        }

        $seen = [];
        $users = [];

        while ($pending !== []) {
            $class = array_shift($pending);

            if (isset($seen[$class])) {
                continue;
            }

            $seen[$class] = true;

            foreach ($this->usedBy[$class] ?? [] as $user) {
                if (isset($this->classless[$user])) {
                    return null;
                }

                $users[$user] = true;
                array_push($pending, ...array_keys($this->declaredIn, $user, strict: true));
            }
        }

        return TestReferenceIndex::runnableOnly(array_keys($users));
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

        if (preg_match_all('/\buses\(([^;]*?)\)/', $source, $calls) > 0) {
            foreach ($calls[1] as $arguments) {
                if (preg_match_all('/([\w\\\\]+)::class/', $arguments, $classes) > 0) {
                    array_push($names, ...$classes[1]);
                }
            }
        }

        return $names;
    }
}
