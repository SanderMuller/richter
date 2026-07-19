<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tracers;

/**
 * Regex scan of a frontend source (TS/JS/Vue) for the backend endpoints it references: Wayfinder
 * imports — an action module's path encodes the controller FQCN (`actions/App/Http/Controllers/
 * PostController`), a route module's path the route-name segments (`routes/clients/payments`) —
 * and Ziggy `route('name')` calls. Matching anchors on the `actions/` / `routes/` path segment,
 * not the `@/` prefix, so Vite alias configuration stays out of scope. No TypeScript parser:
 * recall is bounded, and a detected-but-unresolvable reference (a dynamic `route()` argument)
 * flips `unresolved` so downstream never mistakes "couldn't see it" for "touches nothing".
 */
final class FrontendReferenceScanner
{
    /**
     * What a template-literal interpolation collapses to before route-template matching: a
     * single-segment wildcard-ish token, mirroring the `{param}` → one-segment assumption the
     * templates themselves make.
     */
    public const string INTERPOLATION = '*';

    /**
     * @return array{actions: list<array{class: string, method: string|null}>, routeNames: list<string>, uris: list<array{uri: string, method: string|null}>, unresolved: bool}
     */
    public function scan(string $source): array
    {
        $actions = [];
        $routeNames = [];

        preg_match_all('/import\s+(?:type\s+)?([\w\s{},*$]+?)\s*from\s*[\'"]([^\'"]+)[\'"]/', $source, $imports, PREG_SET_ORDER);

        foreach ($imports as $import) {
            [, $clause, $module] = $import;

            // Two-plus path segments after actions/ — Wayfinder mirrors the controller namespace,
            // so a single-segment `actions/foo` is some other module, never a Wayfinder one. An
            // optional trailing extension (`.ts`) is allowed — a bundler-resolved specifier omits
            // it, but an explicit one is still unambiguously Wayfinder.
            if (preg_match('#(?:^|/)actions/((?:[A-Za-z_]\w*/)+[A-Za-z_]\w*)(?:\.\w+)?$#', $module, $matches) === 1) {
                $class = str_replace('/', '\\', $matches[1]);

                foreach ($this->clauseImports($clause) as $name) {
                    $actions[] = ['class' => $class, 'method' => $name];
                }

                continue;
            }

            // Same trailing-extension allowance as the actions branch above; a bare `routes.ts`
            // module (no `/` segment) also matches here — self-gated by the route-name map like
            // the existing `routes/` collision, since a name that matches nothing is dropped.
            if (preg_match('#(?:^|/)routes(?:/([A-Za-z0-9_/-]+))?(?:\.\w+)?$#', $module, $matches) === 1) {
                $prefix = isset($matches[1]) ? str_replace('/', '.', $matches[1]) . '.' : '';

                foreach ($this->clauseImports($clause) as $name) {
                    // A default/namespace import of a routes module carries no leaf name to derive
                    // a route name from — and `routes/` collides with frontend-router conventions,
                    // so an underivable import is silently not a reference, never a guess.
                    if ($name !== null) {
                        $routeNames[] = $prefix . $name;
                    }
                }
            }
        }

        preg_match_all('/(?<![\w$])route\s*\(\s*[\'"]([^\'"]+)[\'"]/', $source, $ziggy);

        return [
            'actions' => $this->uniqueActions($actions),
            'routeNames' => array_values(array_unique([...$routeNames, ...$ziggy[1]])),
            'uris' => array_values($this->uriCandidates($source)),
            // `route(` followed by anything but a string literal or `)` is a dynamic argument —
            // a template literal or variable the scan cannot resolve. Ziggy's argless `route()`
            // fluent form is not dynamic. A string literal immediately followed by `+` is a
            // concatenated name (`route('videos.' + action)`) — the captured partial name matches
            // nothing and would silently drop, so this is flagged as a second dynamic shape.
            'unresolved' => preg_match('/(?<![\w$])route\s*\(\s*[^\'")\s]/', $source) === 1
                || preg_match('/(?<![\w$])route\s*\(\s*[\'"][^\'"]*[\'"]\s*\+/', $source) === 1,
        ];
    }

    /**
     * Every root-relative endpoint candidate, deduplicated per (uri, method). Plain string
     * literals (`axios.get('/api/videos')`) and backtick templates (`fetch(`/videos/${id}`)` —
     * THE parameterised idiom in apps without Ziggy or Wayfinder, each `${…}` collapsing to a
     * one-segment wildcard token); the query/fragment is not part of the route template, and a
     * template left containing whitespace is an HTML string, not a URL. A verb-named call
     * directly around the literal (`.post('/x'`, `put('/x'`) pins the HTTP method; any other
     * shape — `fetch()` options, wrappers — stays null so uncertainty never narrows.
     *
     * @return array<string, array{uri: string, method: string|null}>
     */
    private function uriCandidates(string $source): array
    {
        $uris = [];

        preg_match_all('/(?:\b(get|post|put|patch|delete)\s*\(\s*)?[\'"](\/[^\'"\s?#]*)[?#]?[^\'"]*[\'"]/i', $source, $literals, PREG_SET_ORDER);

        foreach ($literals as $literal) {
            $method = $literal[1] === '' ? null : strtolower($literal[1]);
            $uris[$literal[2] . '|' . ($method ?? '')] = ['uri' => $literal[2], 'method' => $method];
        }

        preg_match_all('/(?:\b(get|post|put|patch|delete)\s*\(\s*)?`(\/[^`]*)`/i', $source, $templates, PREG_SET_ORDER);

        foreach ($templates as $template) {
            $uri = (string) preg_replace('/\$\{[^}]*\}/', self::INTERPOLATION, $template[2]);
            $uri = (string) preg_replace('/[?#].*/s', '', $uri);

            if (preg_match('/\s/', $uri) === 1) {
                continue;
            }

            $method = $template[1] === '' ? null : strtolower($template[1]);
            $uris[$uri . '|' . ($method ?? '')] = ['uri' => $uri, 'method' => $method];
        }

        return $uris;
    }

    /**
     * The imported names of one import clause: each named member (the original left of `as`,
     * `type` markers stripped), plus null once when a default or `* as` import is present —
     * null means "the whole module".
     *
     * @return list<string|null>
     */
    private function clauseImports(string $clause): array
    {
        $names = [];

        if (preg_match('/\{([^}]*)}/', $clause, $braced) === 1) {
            foreach (explode(',', $braced[1]) as $member) {
                $member = (string) preg_replace('/^type\s+/', '', trim($member));
                $split = preg_split('/\s+as\s+/', $member);
                $original = trim($split === false ? $member : ($split[0] ?? ''));

                if ($original !== '') {
                    $names[] = $original;
                }
            }
        }

        $outside = trim((string) preg_replace('/\{[^}]*}/', '', $clause), " \t\n\r,");

        if ($outside !== '') {
            $names[] = null;
        }

        return $names;
    }

    /**
     * @param  list<array{class: string, method: string|null}>  $actions
     * @return list<array{class: string, method: string|null}>
     */
    private function uniqueActions(array $actions): array
    {
        $unique = [];

        foreach ($actions as $action) {
            $unique[$action['class'] . '::' . ($action['method'] ?? '*')] = $action;
        }

        return array_values($unique);
    }
}
