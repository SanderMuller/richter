<?php declare(strict_types=1);

namespace SanderMuller\Richter\Changes;

use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use SanderMuller\Richter\Analysis\TestReferenceIndex;
use SanderMuller\Richter\Support\RichterConfig;
use SanderMuller\Richter\Tracers\FrontendReferenceScanner;
use Throwable;

/**
 * Turns a changed frontend file into a {@see ChangedFileSymbols} whose direct seeds are the
 * `route::` node ids of every backend endpoint it references. All reference kinds land uniformly
 * as route nodes: a Wayfinder action import resolves through the router's action index (the route
 * knows its controller), a Wayfinder route import or Ziggy call through the name index. The
 * asymmetry between the two is deliberate — an `actions/App/…` import is unambiguously Wayfinder,
 * so an unmatched one flips the unresolved flag; a `routes/…` import or `route('name')` call
 * collides with frontend-router conventions, so an unmatched name silently isn't a reference,
 * never a guess.
 */
final class FrontendChanges
{
    private const array EXTENSIONS = ['ts', 'tsx', 'js', 'jsx', 'vue'];

    private readonly FrontendReferenceScanner $scanner;

    /** @var array{byName: array<string, list<string>>, byAction: array<string, list<string>>, byClass: array<string, list<string>>, uriTemplates: list<array{regex: string, nodes: list<string>}>}|null */
    private ?array $indexes = null;

    private bool $routerUnavailable = false;

    public function __construct()
    {
        $this->scanner = new FrontendReferenceScanner();
    }

    /** Whether the file is a scannable frontend source: bridge on, under a configured root, a frontend extension, and not Wayfinder-generated regeneration churn. */
    public function handles(string $file): bool
    {
        $roots = RichterConfig::frontendRoots();

        if ($roots === [] || ! in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), self::EXTENSIONS, strict: true)) {
            return false;
        }

        foreach ($roots as $root) {
            $root = trim($root, '/');

            if ($root === '') {
                continue;
            }

            if (! str_starts_with($file, $root . '/')) {
                continue;
            }

            return array_all(RichterConfig::frontendGeneratedPaths(), fn (string $generated) => ! str_starts_with($file, $root . '/' . trim($generated, '/') . '/'));
        }

        return false;
    }

    /**
     * References are the union of both sides of the diff — a removed endpoint call is still part
     * of what the change touches. Null sources scan as empty (a deleted file has no head side).
     */
    public function resolve(string $file, ?string $headSrc, ?string $baseSrc): ChangedFileSymbols
    {
        $head = $this->scanner->scan($headSrc ?? '');
        $base = $this->scanner->scan($baseSrc ?? '');

        $actions = [...$head['actions'], ...$base['actions']];
        $routeNames = array_values(array_unique([...$head['routeNames'], ...$base['routeNames']]));
        $uris = [];

        foreach ([...$head['uris'], ...$base['uris']] as $literal) {
            $uris[$literal['uri'] . '|' . ($literal['method'] ?? '*')] = $literal;
        }

        $unresolved = $head['unresolved'] || $base['unresolved'];
        $findings = $unresolved ? ['a dynamic route() argument prevents resolving every referenced endpoint'] : [];

        if ($actions === [] && $routeNames === [] && $uris === []) {
            return new ChangedFileSymbols($file, '', [], cosmeticOnly: false, findings: $findings, unresolvedFrontendReferences: $unresolved);
        }

        $indexes = $this->indexes();

        if ($indexes === null) {
            return new ChangedFileSymbols($file, '', [], cosmeticOnly: false, findings: [...$findings, 'the router is unavailable — endpoint references could not be checked'], unresolvedFrontendReferences: true);
        }

        $seeds = [];

        foreach ($actions as $action) {
            $key = $action['method'] === null ? null : "{$action['class']}::{$action['method']}";
            $nodes = $key === null ? ($indexes['byClass'][$action['class']] ?? null) : ($indexes['byAction'][$key] ?? null);

            if ($nodes === null) {
                $unresolved = true;
                $findings[] = 'Wayfinder import references ' . ($key ?? $action['class']) . ' which matches no registered route';

                continue;
            }

            $seeds = [...$seeds, ...$nodes];
        }

        foreach ($routeNames as $name) {
            $seeds = [...$seeds, ...$indexes['byName'][$name] ?? []];
        }

        $seeds = [...$seeds, ...$this->seedsForUris(array_values($uris), $indexes['uriTemplates'])];

        return new ChangedFileSymbols($file, '', [], cosmeticOnly: false, directSeeds: array_values(array_unique($seeds)), findings: $findings, unresolvedFrontendReferences: $unresolved);
    }

    /**
     * The route nodes referenced by URI literals in inline `<script>` blocks — the surface a
     * changed Blade view's `fetch('/api/…')` touches. Script slices only: markup hrefs, form
     * actions and Blade's `route()` helper are navigation/link *generation* (every layout renders
     * dozens), not endpoint calls — scanning them would register every linked route as touched.
     * Alpine attribute handlers are a known miss. Annotation-grade: an unavailable router yields
     * nothing rather than blocking anything.
     *
     * @return list<string>
     */
    public function inlineUriSeeds(?string $headSrc, ?string $baseSrc): array
    {
        $uris = [
            ...$this->scanner->scan($this->scriptSlices($headSrc ?? ''))['uris'],
            ...$this->scanner->scan($this->scriptSlices($baseSrc ?? ''))['uris'],
        ];

        if ($uris === []) {
            return [];
        }

        $indexes = $this->indexes();

        if ($indexes === null) {
            return [];
        }

        return array_values(array_unique($this->seedsForUris($uris, $indexes['uriTemplates'])));
    }

    private function scriptSlices(string $source): string
    {
        preg_match_all('/<script\b[^>]*>(.*?)<\/script>/is', $source, $matches);

        return implode("\n", $matches[1]);
    }

    /**
     * Every route node one frontend source references, across all three reference kinds — the
     * building block for indexing frontend TEST files, where a missed reference only means a test
     * isn't suggested (nothing to flag unresolved).
     *
     * @return list<string>
     */
    public function routeNodesIn(string $source): array
    {
        $scan = $this->scanner->scan($source);

        if ($scan['actions'] === [] && $scan['routeNames'] === [] && $scan['uris'] === []) {
            return [];
        }

        $indexes = $this->indexes();

        if ($indexes === null) {
            return [];
        }

        $seeds = [];

        foreach ($scan['actions'] as $action) {
            $seeds = [
                ...$seeds,
                ...$action['method'] === null
                    ? $indexes['byClass'][$action['class']] ?? []
                    : $indexes['byAction']["{$action['class']}::{$action['method']}"] ?? [],
            ];
        }

        foreach ($scan['routeNames'] as $name) {
            $seeds = [...$seeds, ...$indexes['byName'][$name] ?? []];
        }

        return array_values(array_unique([...$seeds, ...$this->seedsForUris($scan['uris'], $indexes['uriTemplates'])]));
    }

    /**
     * Route-node ids per route name, per controller action, per controller class, and per URI
     * template (each template as a regex with `{param}` segments wildcarded, the same matching
     * {@see TestReferenceIndex} uses) — node ids in the `route::{METHOD}::/{uri}` shape the graph
     * builder derives from Brain's route nodes. Null when no router is booted; the caller then
     * degrades to "couldn't check", never to "touches nothing" ({@see TestReferenceIndex} makes
     * the same tri-state call for the same reason).
     *
     * @return array{byName: array<string, list<string>>, byAction: array<string, list<string>>, byClass: array<string, list<string>>, uriTemplates: list<array{regex: string, nodes: list<string>}>}|null
     */
    private function indexes(): ?array
    {
        if ($this->routerUnavailable) {
            return null;
        }

        if ($this->indexes !== null) {
            return $this->indexes;
        }

        $byName = [];
        $byAction = [];
        $byClass = [];
        $uriTemplates = [];

        try {
            /** @var RoutingRoute $route */
            foreach (Route::getRoutes()->getRoutes() as $route) {
                $nodes = [];

                foreach ($route->methods() as $method) {
                    if (is_string($method) && ! in_array($method, ['HEAD', 'OPTIONS'], strict: true)) {
                        $nodes[] = "route::{$method}::/" . ltrim($route->uri(), '/');
                    }
                }

                if ($nodes === []) {
                    continue;
                }

                $uriTemplates[] = [
                    'regex' => $this->uriTemplateRegex('/' . ltrim($route->uri(), '/')),
                    'nodes' => $nodes,
                ];

                $name = $route->getName();

                if (is_string($name) && $name !== '') {
                    $byName[$name] = [...$byName[$name] ?? [], ...$nodes];
                }

                // Invokable controllers arrive as `Class@__invoke` too — the router normalises
                // string actions at registration, so the `@` form is the only controller shape.
                $action = $route->getActionName();

                if (str_contains($action, '@')) {
                    [$class, $method] = explode('@', $action, 2);
                    $class = ltrim($class, '\\');
                    $byAction["{$class}::{$method}"] = [...$byAction["{$class}::{$method}"] ?? [], ...$nodes];
                    $byClass[$class] = [...$byClass[$class] ?? [], ...$nodes];
                }
            }
        } catch (Throwable) {
            $this->routerUnavailable = true;

            return null;
        }

        return $this->indexes = ['byName' => $byName, 'byAction' => $byAction, 'byClass' => $byClass, 'uriTemplates' => $uriTemplates];
    }

    /**
     * A URI literal is a weak candidate (any root-relative string matches the shape) — the route
     * templates are the filter, and a non-endpoint literal simply matches none of them. A pinned
     * verb then scopes the match to its method's nodes — across every matched template, since GET
     * and POST on one path can be separate registrations. When the verb is served by none of
     * them, the path match stays whole: the tests referencing that URI are method-agnostic, and
     * dropping them would under-select.
     *
     * @param  list<array{uri: string, method: string|null}>  $uris
     * @param  list<array{regex: string, nodes: list<string>}>  $templates
     * @return list<string>
     */
    private function seedsForUris(array $uris, array $templates): array
    {
        $seeds = [];

        foreach ($uris as $literal) {
            $matched = [];

            foreach ($templates as $template) {
                if (preg_match($template['regex'], $literal['uri']) === 1) {
                    $matched = [...$matched, ...$template['nodes']];
                }
            }

            if ($literal['method'] !== null) {
                $verbScoped = array_values(array_filter(
                    $matched,
                    static fn (string $node): bool => str_starts_with($node, 'route::' . strtoupper((string) $literal['method']) . '::'),
                ));
                $matched = $verbScoped === [] ? $matched : $verbScoped;
            }

            $seeds = [...$seeds, ...$matched];
        }

        return $seeds;
    }

    /**
     * A route URI as a regex over concrete request paths: `{param}` wildcards one segment, and
     * `{param?}` is optional together with its leading slash — `/users/{user?}` serves `/users`,
     * so a literal `/users` must match it. Split on the raw parameters first, then quote only the
     * literal parts: quoting first would demand matching `\{`/`\?` escapes inside the pattern.
     */
    private function uriTemplateRegex(string $uri): string
    {
        $parts = preg_split('~(/\{[^}]+\}|\{[^}]+\})~', $uri, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $regex = '';

        foreach ($parts === false ? [] : $parts as $part) {
            $regex .= match (true) {
                // `?` zero-or-more (not `+` one-or-more) inside the group so the whole optional
                // group also matches a bare trailing slash (`/users/`) and, at the route root
                // (`/{locale?}`), the bare `/` itself — both legitimately serve the route.
                str_starts_with($part, '/{') => str_ends_with($part, '?}') ? '(?:/[^/]*)?' : '/[^/]+',
                str_starts_with($part, '{') => str_ends_with($part, '?}') ? '[^/]*' : '[^/]+',
                default => preg_quote($part, '#'),
            };
        }

        return "#^{$regex}$#";
    }
}
