<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

/**
 * Tests that run a change without naming the route it reaches, so test selection adds them while
 * the report still grades the route by its real references ({@see TestReferenceIndex}).
 *
 * - A test that registers its own route in a middleware group (`Route::middleware('web')->get(…)`)
 *   runs every middleware of the group.
 * - A test that runs `scramble:export` or `scramble:analyze` reads every route under Scramble's API
 *   path, and the controllers, requests and resources behind them.
 *
 * A regex scan, like the rest of the index.
 *
 * @internal
 */
final class SelectionOnlyTests
{
    private const string GROUP_CALL_PATTERN = '/(?:->|::)middleware\(\s*([^)]*)\)/';

    private const string API_DOCS_PATTERN = '/[\'"]scramble:(?:export|analyze)[\'"]/';

    /** @var array<string, array<string, true>> group name => test files that register a route in it */
    private array $groupRegistrations = [];

    /** @var array<string, true> test files that export the API documentation */
    private array $apiDocs = [];

    public function add(string $source, string $file): void
    {
        if (preg_match_all(self::GROUP_CALL_PATTERN, $source, $calls) > 0) {
            foreach ($calls[1] as $arguments) {
                preg_match_all('/[\'"]([\w\-]+)[\'"]/', $arguments, $names);

                foreach ($names[1] as $group) {
                    $this->groupRegistrations[$group][$file] = true;
                }
            }
        }

        if (preg_match(self::API_DOCS_PATTERN, $source) === 1) {
            $this->apiDocs[$file] = true;
        }
    }

    /** @return list<string> */
    public function registeringRoutesIn(string $group): array
    {
        return array_map(strval(...), array_keys($this->groupRegistrations[$group] ?? []));
    }

    /**
     * The tests that export the API documentation, when the route node is under Scramble's API path.
     *
     * @return list<string>
     */
    public function documenting(string $routeNode): array
    {
        if ($this->apiDocs === [] || ! str_starts_with($routeNode, 'route::')) {
            return [];
        }

        $uri = trim(explode('::', $routeNode, 3)[2] ?? '', '/');
        $configured = config('scramble.api_path', 'api');
        $prefix = is_string($configured) ? trim($configured, '/') : 'api';

        if ($prefix !== '' && $uri !== $prefix && ! str_starts_with($uri, $prefix . '/')) {
            return [];
        }

        return array_map(strval(...), array_keys($this->apiDocs));
    }
}
