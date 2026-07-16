<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Finder\Finder;
use Throwable;

/**
 * Answers "does any automated test reference this entry point?" so the impact report can say which
 * reached routes/commands a change touches *without* a test in the way — turning the entry-point
 * list from context into an action list. Reference-based, not coverage-based, by design: a regex
 * scan of tests/ runs in well under a second on every report, real line coverage would need an
 * instrumented full-suite run. A reference is a weaker claim than coverage — the annotation says
 * "referenced", never "covered".
 */
final class TestReferenceIndex
{
    /** @var array<string, true> */
    private array $uris = [];

    /** @var array<string, true> */
    private array $routeNames = [];

    /** @var array<string, true> */
    private array $artisanNames = [];

    /** @var array<string, true> */
    private array $classes = [];

    /** @var list<array{regex: string, name: string|null, methods: list<string>}>|null */
    private ?array $routeMap = null;

    private bool $routerUnavailable = false;

    public static function fromTests(string $testsDir): self
    {
        $index = new self();

        if (! is_dir($testsDir)) {
            return $index;
        }

        foreach (Finder::create()->files()->in($testsDir)->name('*.php') as $file) {
            $index->addSource((string) file_get_contents($file->getPathname()));
        }

        return $index;
    }

    public function addSource(string $source): void
    {
        if (preg_match_all('/route\(\s*[\'"]([^\'"]+)[\'"]/', $source, $matches) > 0) {
            foreach ($matches[1] as $name) {
                $this->routeNames[$name] = true;
            }
        }

        if (preg_match_all('/[\'"](\/[^\'"\s]*)[\'"]/', $source, $matches) > 0) {
            foreach ($matches[1] as $uri) {
                // A query string is not part of the route template.
                $template = strstr($uri, '?', before_needle: true);
                $this->uris[$template === false || $template === '' ? $uri : $template] = true;
            }
        }

        if (preg_match_all('/(?:artisan|Artisan::call|Artisan::queue)\(\s*[\'"]([^\'"\s]+)[\'"]/', $source, $matches) > 0) {
            foreach ($matches[1] as $command) {
                $this->artisanNames[$command] = true;
            }
        }

        // Aliased imports (`use App\Jobs\Foo as FooJob;`) key on the FQCN, not the alias.
        if (preg_match_all('/^use\s+(App\\\\[^;\s{]+)(?:\s+as\s+\w+)?\s*;/mi', $source, $matches) > 0) {
            foreach ($matches[1] as $class) {
                $this->classes[$class] = true;
            }
        }

        // Grouped imports: `use App\Jobs\{ImportJob, OtherJob as O};` — expand to one FQCN per member.
        if (preg_match_all('/^use\s+(App\\\\[^;{]*)\{([^}]+)}\s*;/m', $source, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as $group) {
                foreach (explode(',', $group[2]) as $member) {
                    $parts = preg_split('/\s+as\s+/i', trim($member));
                    $member = $parts === false ? '' : trim($parts[0] ?? '');

                    if ($member !== '') {
                        $this->classes[$group[1] . $member] = true;
                    }
                }
            }
        }
    }

    /**
     * Whether any test references the entry-point node — null when it cannot be checked: schedule
     * nodes, a malformed node, or an infrastructure failure (router/console kernel unavailable)
     * that would otherwise misread as an actionable "no test references this".
     */
    public function hasReference(string $entryPointNode): ?bool
    {
        if (str_starts_with($entryPointNode, 'route::')) {
            return $this->routeHasReference($entryPointNode);
        }

        if (str_starts_with($entryPointNode, 'command::')) {
            return $this->commandHasReference($entryPointNode);
        }

        // A self-listed entry-point class (a changed listener/job with no app-side caller) — a test
        // referencing the class by import counts.
        if (preg_match('/^App\\\\[\w\\\\]+$/', $entryPointNode) === 1) {
            return isset($this->classes[$entryPointNode]);
        }

        return null;
    }

    private function routeHasReference(string $node): ?bool
    {
        // `route::GET::/videos/{video}` → method + URI template.
        $parts = explode('::', $node, 3);

        if (count($parts) !== 3) {
            return null;
        }

        [, $method, $uri] = $parts;

        foreach ($this->routeMap() as $route) {
            if (! in_array($method, $route['methods'], strict: true)) {
                continue;
            }

            if (preg_match($route['regex'], $uri) !== 1) {
                continue;
            }

            if ($route['name'] !== null && isset($this->routeNames[$route['name']])) {
                return true;
            }
        }

        // A test may hit the URI directly (`$this->get('/videos/123')`) — match the node's template
        // against every literal URI the tests contain.
        $template = '#^' . preg_replace('/\\\{[^}]+\\\}/', '[^/]+', preg_quote($uri, '#')) . '$#';

        if (array_any(array_keys($this->uris), fn (string $literal): bool => preg_match($template, $literal) === 1)) {
            return true;
        }

        // With the router unavailable, name-based matching never ran — a miss here means "couldn't
        // check", not "unreferenced".
        return $this->routerUnavailable ? null : false;
    }

    private function commandHasReference(string $node): ?bool
    {
        $signature = substr($node, strlen('command::'));
        $name = preg_split('/\s/', trim($signature), 2)[0] ?? '';

        if ($name === '') {
            return null;
        }

        if (isset($this->artisanNames[$name])) {
            return true;
        }

        try {
            $command = Artisan::all()[$name] ?? null;
        } catch (Throwable) {
            // Console kernel unavailable — a class-import reference can't be ruled out.
            return null;
        }

        return $command instanceof Command && isset($this->classes[$command::class]);
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
            // check" (see routeHasReference), never to an actionable "unreferenced".
            $this->routerUnavailable = true;
        }

        return $this->routeMap = $map;
    }
}
