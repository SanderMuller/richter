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

    /** @var array{byName: array<string, list<string>>, byAction: array<string, list<string>>, byClass: array<string, list<string>>}|null */
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

            if ($root === '' || ! str_starts_with($file, $root . '/')) {
                continue;
            }

            foreach (RichterConfig::frontendGeneratedPaths() as $generated) {
                if (str_starts_with($file, $root . '/' . trim($generated, '/') . '/')) {
                    return false;
                }
            }

            return true;
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
        $unresolved = $head['unresolved'] || $base['unresolved'];
        $findings = $unresolved ? ['a dynamic route() argument prevents resolving every referenced endpoint'] : [];

        if ($actions === [] && $routeNames === []) {
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

        return new ChangedFileSymbols($file, '', [], cosmeticOnly: false, directSeeds: array_values(array_unique($seeds)), findings: $findings, unresolvedFrontendReferences: $unresolved);
    }

    /**
     * Route-node ids per route name, per controller action, and per controller class — the same
     * `route::{METHOD}::/{uri}` id shape the graph builder derives from Brain's route nodes. Null
     * when no router is booted; the caller then degrades to "couldn't check", never to "touches
     * nothing" ({@see TestReferenceIndex} makes the same tri-state
     * call for the same reason).
     *
     * @return array{byName: array<string, list<string>>, byAction: array<string, list<string>>, byClass: array<string, list<string>>}|null
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

        return $this->indexes = ['byName' => $byName, 'byAction' => $byAction, 'byClass' => $byClass];
    }
}
