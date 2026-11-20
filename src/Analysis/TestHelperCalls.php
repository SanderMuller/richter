<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

use SanderMuller\Richter\Tracers\RouteUrlHelpers;

/**
 * The app methods each test calls by name (`->editUrl(`, `::editUrl(`), so a test that calls a
 * route URL helper ({@see RouteUrlHelpers}) is read as naming the route the helper returns. A regex
 * scan, like the rest of {@see TestReferenceIndex}.
 *
 * @internal
 */
final class TestHelperCalls
{
    /** @var array<string, array<string, true>> method name => test files calling it */
    private array $callers = [];

    /** @var array<string, true> methods a source without a file calls */
    private array $calledWithoutFile = [];

    public function add(string $source, ?string $file): void
    {
        if (preg_match_all('/(?:->|::)(\w+)\s*\(/', $source, $matches) === 0) {
            return;
        }

        foreach ($matches[1] as $method) {
            if ($file === null) {
                $this->calledWithoutFile[$method] = true;
            } else {
                $this->callers[$method][$file] = true;
            }
        }
    }

    /**
     * Each route a called helper returns, with a test file that calls the helper. The file is null
     * for a source added without one, which still counts as a reference.
     *
     * @param  array<string, list<string>>  $helpers  method name => route names
     * @return list<array{0: string, 1: string|null}>
     */
    public function routeReferences(array $helpers): array
    {
        $references = [];

        foreach ($helpers as $method => $routes) {
            $files = array_keys($this->callers[$method] ?? []);

            if (isset($this->calledWithoutFile[$method])) {
                $files[] = null;
            }

            foreach ($routes as $route) {
                foreach ($files as $file) {
                    $references[] = [$route, $file === null ? null : (string) $file];
                }
            }
        }

        return $references;
    }
}
