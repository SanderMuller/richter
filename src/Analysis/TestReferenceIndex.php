<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Finder\Finder;
use Throwable;

/**
 * Answers "does any automated test reference this entry point?" — and since the index records which
 * test file each reference came from, also "which tests?" ({@see testsReferencing()}), the mapping
 * `richter:affected-tests` inverts into a test selection. Reference-based, not coverage-based, by
 * design: a regex scan of tests/ runs in well under a second on every report, real line coverage
 * would need an instrumented full-suite run. A reference is a weaker claim than coverage — the
 * annotation says "referenced", never "covered".
 */
final class TestReferenceIndex
{
    /** @var array<string, list<string>> uri => test files containing it */
    private array $uris = [];

    /** @var array<string, list<string>> route name => test files containing it */
    private array $routeNames = [];

    /** @var array<string, list<string>> artisan command name => test files containing it */
    private array $artisanNames = [];

    /** @var array<string, list<string>> imported FQCN => test files importing it */
    private array $classes = [];

    /** @var list<array{regex: string, name: string|null, methods: list<string>}>|null */
    private ?array $routeMap = null;

    private bool $routerUnavailable = false;

    /**
     * @param  string|null  $projectRoot  when given, recorded test-file paths are made relative to
     *   it — the form a test-runner invocation (`php artisan test <file>`) expects
     */
    public static function fromTests(string $testsDir, ?string $projectRoot = null): self
    {
        $index = new self();

        if (! is_dir($testsDir)) {
            return $index;
        }

        foreach (Finder::create()->files()->in($testsDir)->name('*.php') as $file) {
            $path = $file->getPathname();

            if ($projectRoot !== null && str_starts_with($path, $projectRoot . '/')) {
                $path = substr($path, strlen($projectRoot) + 1);
            }

            $index->addSource((string) file_get_contents($file->getPathname()), $path);
        }

        return $index;
    }

    /**
     * @param  string|null  $file  the test file the source came from; a null-file source still
     *   counts for {@see hasReference()} but can never contribute a path to {@see testsReferencing()}
     */
    public function addSource(string $source, ?string $file = null): void
    {
        if (preg_match_all('/route\(\s*[\'"]([^\'"]+)[\'"]/', $source, $matches) > 0) {
            foreach ($matches[1] as $name) {
                $this->record($this->routeNames, $name, $file);
            }
        }

        if (preg_match_all('/[\'"](\/[^\'"\s]*)[\'"]/', $source, $matches) > 0) {
            foreach ($matches[1] as $uri) {
                // A query string is not part of the route template.
                $template = strstr($uri, '?', before_needle: true);
                $this->record($this->uris, $template === false || $template === '' ? $uri : $template, $file);
            }
        }

        if (preg_match_all('/(?:artisan|Artisan::call|Artisan::queue)\(\s*[\'"]([^\'"\s]+)[\'"]/', $source, $matches) > 0) {
            foreach ($matches[1] as $command) {
                $this->record($this->artisanNames, $command, $file);
            }
        }

        $this->recordClassReferences($source, $file);
    }

    /**
     * Every way a test can name an `App\` class: single imports (aliased or not, keyed on the
     * FQCN), grouped imports expanded per member, and qualified in-body references
     * (`\App\Services\X::class`, `new \App\Services\X(...)`) — a test need not import a class to
     * break when it changes. Strings and comments can over-match; for reference detection and
     * test selection, over is the safe direction.
     */
    private function recordClassReferences(string $source, ?string $file): void
    {
        if (preg_match_all('/^use\s+(App\\\\[^;\s{]+)(?:\s+as\s+\w+)?\s*;/mi', $source, $matches) > 0) {
            foreach ($matches[1] as $class) {
                $this->record($this->classes, $class, $file);
            }
        }

        if (preg_match_all('/(?<![\w$\\\\])\\\\?(App\\\\(?:[A-Za-z_]\w*\\\\)*[A-Za-z_]\w*)/', $source, $matches) > 0) {
            foreach ($matches[1] as $class) {
                $this->record($this->classes, $class, $file);
            }
        }

        if (preg_match_all('/^use\s+(App\\\\[^;{]*)\{([^}]+)}\s*;/m', $source, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as $group) {
                foreach (explode(',', $group[2]) as $member) {
                    $parts = preg_split('/\s+as\s+/i', trim($member));
                    $member = $parts === false ? '' : trim($parts[0] ?? '');

                    if ($member !== '') {
                        $this->record($this->classes, $group[1] . $member, $file);
                    }
                }
            }
        }

        if (preg_match_all('/(?:Livewire::test|(?<![\w$])livewire)\(\s*[\'"]([a-z0-9\-.]+)[\'"]/', $source, $matches) > 0) {
            foreach ($matches[1] as $name) {
                $this->record($this->classes, $this->livewireClassFor($name), $file);
            }
        }
    }

    /**
     * `admin.dashboard-stats` → `App\Livewire\Admin\DashboardStats` — Livewire's default naming
     * convention, applied in reverse, mirroring the README's "assumes standard Laravel conventions"
     * stance. A custom-namespaced or manually-registered component won't match; over-recording a
     * non-existent class is harmless (nothing imports it), under-recording a real one is the
     * direction this closes.
     */
    private function livewireClassFor(string $name): string
    {
        $segments = array_map(
            static fn (string $segment): string => str_replace(' ', '', ucwords(str_replace('-', ' ', $segment))),
            explode('.', $name),
        );

        return 'App\\Livewire\\' . implode('\\', $segments);
    }

    /**
     * Whether any test references the entry-point node — null when it cannot be checked: schedule
     * nodes, a malformed node, or an infrastructure failure (router/console kernel unavailable)
     * that would otherwise misread as an actionable "no test references this".
     */
    public function hasReference(string $entryPointNode): ?bool
    {
        return $this->resolve($entryPointNode)['referenced'] ?? null;
    }

    /**
     * The test files referencing the entry-point node, with {@see hasReference()}'s exact tri-state:
     * null means "couldn't check" — a consumer selecting tests must then fall back to the full
     * suite, never to a silently smaller set. A source added without a file counts for the boolean
     * but cannot contribute a path, so the list may be empty while hasReference() is true.
     *
     * @return list<string>|null
     */
    public function testsReferencing(string $entryPointNode): ?array
    {
        return $this->resolve($entryPointNode)['tests'] ?? null;
    }

    /**
     * The test files importing an `App\` class — always determinable (imports are a pure text
     * scan), so no tri-state. This is `affected-tests`' second selection axis: a unit test that
     * exercises a changed class directly never references an entry point.
     *
     * @return list<string>
     */
    public function testsImporting(string $fqcn): array
    {
        return $this->classes[ltrim($fqcn, '\\')] ?? [];
    }

    /**
     * One resolver behind both queries so the boolean and the file list can never drift; null means
     * the node cannot be checked at all.
     *
     * @return array{referenced: bool, tests: list<string>}|null
     */
    private function resolve(string $entryPointNode): ?array
    {
        if (str_starts_with($entryPointNode, 'route::')) {
            return $this->resolveRoute($entryPointNode);
        }

        if (str_starts_with($entryPointNode, 'command::')) {
            return $this->resolveCommand($entryPointNode);
        }

        // A self-listed entry-point class (a changed listener/job with no app-side caller) — a test
        // referencing the class by import counts.
        if (preg_match('/^App\\\\[\w\\\\]+$/', $entryPointNode) === 1) {
            return [
                'referenced' => isset($this->classes[$entryPointNode]),
                'tests' => $this->classes[$entryPointNode] ?? [],
            ];
        }

        return null;
    }

    /** @return array{referenced: bool, tests: list<string>}|null */
    private function resolveRoute(string $node): ?array
    {
        // `route::GET::/videos/{video}` → method + URI template.
        $parts = explode('::', $node, 3);

        if (count($parts) !== 3) {
            return null;
        }

        [, $method, $uri] = $parts;
        $referenced = false;
        $tests = [];

        foreach ($this->routeMap() as $route) {
            if (! in_array($method, $route['methods'], strict: true)) {
                continue;
            }

            if (preg_match($route['regex'], $uri) !== 1) {
                continue;
            }

            if ($route['name'] !== null && isset($this->routeNames[$route['name']])) {
                $referenced = true;
                $tests = [...$tests, ...$this->routeNames[$route['name']]];
            }
        }

        // A test may hit the URI directly (`$this->get('/videos/123')`) — match the node's template
        // against every literal URI the tests contain.
        $template = '#^' . preg_replace('/\\\{[^}]+\\\}/', '[^/]+', preg_quote($uri, '#')) . '$#';

        foreach ($this->uris as $literal => $files) {
            if (preg_match($template, (string) $literal) === 1) {
                $referenced = true;
                $tests = [...$tests, ...$files];
            }
        }

        if ($referenced) {
            return ['referenced' => true, 'tests' => $this->unique($tests)];
        }

        // With the router unavailable, name-based matching never ran — a miss here means "couldn't
        // check", not "unreferenced".
        return $this->routerUnavailable ? null : ['referenced' => false, 'tests' => []];
    }

    /** @return array{referenced: bool, tests: list<string>}|null */
    private function resolveCommand(string $node): ?array
    {
        $signature = substr($node, strlen('command::'));
        $name = preg_split('/\s/', trim($signature), 2)[0] ?? '';

        if ($name === '') {
            return null;
        }

        $referenced = isset($this->artisanNames[$name]);
        $tests = $this->artisanNames[$name] ?? [];

        try {
            $command = Artisan::all()[$name] ?? null;
        } catch (Throwable) {
            // Console kernel unavailable — a class-import reference can't be ruled out. An artisan
            // string match already in hand is still a determined (positive) answer.
            return $referenced ? ['referenced' => true, 'tests' => $this->unique($tests)] : null;
        }

        if ($command instanceof Command && isset($this->classes[$command::class])) {
            $referenced = true;
            $tests = [...$tests, ...$this->classes[$command::class]];
        }

        return ['referenced' => $referenced, 'tests' => $this->unique($tests)];
    }

    /** @param  array<string, list<string>>  $bucket */
    private function record(array &$bucket, string $key, ?string $file): void
    {
        $files = $bucket[$key] ?? [];

        if ($file !== null && ! in_array($file, $files, strict: true)) {
            $files[] = $file;
        }

        $bucket[$key] = $files;
    }

    /**
     * @param  list<string>  $tests
     * @return list<string>
     */
    private function unique(array $tests): array
    {
        $tests = array_values(array_unique($tests));
        sort($tests);

        return $tests;
    }

    /**
     * The app's route templates, each as a regex over the node's URI form — the node renders `{param}`
     * placeholders literally, so `/videos/{video}` matches itself, and both sides normalise to a
     * leading slash.
     *
     * @return list<array{regex: string, name: string|null, methods: list<string>}>
     */
    private function routeMap(): array
    {
        if ($this->routeMap !== null) {
            return $this->routeMap;
        }

        $map = [];

        try {
            /** @var RoutingRoute $route */
            foreach (Route::getRoutes()->getRoutes() as $route) {
                $uri = '/' . ltrim($route->uri(), '/');
                $map[] = [
                    'regex' => '#^' . preg_quote($uri, '#') . '$#',
                    'name' => $route->getName(),
                    'methods' => array_values(array_filter($route->methods(), is_string(...))),
                ];
            }
        } catch (Throwable) {
            // No booted router — URI matching still works, but a miss must degrade to "couldn't
            // check" (see testsReferencingRoute), never to an actionable "unreferenced".
            $this->routerUnavailable = true;
        }

        return $this->routeMap = $map;
    }
}
