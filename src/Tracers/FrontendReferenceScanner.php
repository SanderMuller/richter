<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tracers;

/**
 * Regex scan of a frontend source (TS/JS/Vue) for the backend endpoints it references: Wayfinder
 * imports — an action module's path encodes the controller FQCN (`actions/App/Http/Controllers/
 * PostController`), a route module's path the route-name segments (`routes/clients/payments`) —
 * and Ziggy `route('name')` calls. Matching anchors on the `actions/` / `routes/` path segment,
 * not the `@/` prefix, so Vite alias configuration stays out of scope. No TypeScript parser:
 * recall is bounded, and a detected-but-unresolvable reference (a dynamic `route()` argument)
 * flips `unresolved` so downstream never mistakes "couldn't see it" for "touches nothing". A
 * dynamic argument first gets one resolution attempt against a same-module `const`/`enum` string
 * constant (a bare identifier or a flat object/enum member access); only what survives that
 * attempt flips the flag. Resolution never guesses: exactly-one-declaration discipline, flat
 * bodies only, `const` only — never `let`/`var`.
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

        [$resolvedNames, $unresolved] = $this->resolveDynamicRouteArguments($source);

        return [
            'actions' => $this->uniqueActions($actions),
            'routeNames' => array_values(array_unique([...$routeNames, ...$ziggy[1], ...$resolvedNames])),
            'uris' => array_values($this->uriCandidates($source)),
            'unresolved' => $unresolved,
        ];
    }

    /**
     * Every dynamic `route(...)` argument gets one resolution attempt against a same-module
     * `const`/`enum` string constant before it is allowed to flip the file-level fail-safe.
     * Anything the resolver cannot pin with certainty (no declaration, `let`/`var`, more than one
     * declaration, a nested object body, an imported constant, …) stays unresolved — resolution
     * never guesses.
     *
     * @return array{0: list<string>, 1: bool} route names recovered from same-module constants,
     *   and whether any dynamic argument stayed unresolvable
     */
    private function resolveDynamicRouteArguments(string $source): array
    {
        // A string literal followed by `+` is concatenation — never resolvable to one constant.
        $unresolved = preg_match('/(?<![\w$])route\s*\(\s*[\'"][^\'"]*[\'"]\s*\+/', $source) === 1;

        // Same first-character discipline as the original detector: anything after `route(` that
        // is not a quote, `)`, or whitespace is a dynamic argument. Capture up to the first `,` or
        // `)` — the name expression, options excluded.
        preg_match_all('/(?<![\w$])route\s*\(\s*([^\'")\s][^),]*)/', $source, $dynamic);

        $resolved = [];

        foreach ($dynamic[1] as $argument) {
            $name = $this->sameModuleConstant(trim($argument), $source);

            if ($name === null) {
                $unresolved = true;

                continue;
            }

            $resolved[] = $name;
        }

        return [$resolved, $unresolved];
    }

    /**
     * Resolves a `route()` argument expression to a string constant declared in the same module,
     * or null when the resolution cannot be made with certainty. Two shapes are readable without a
     * parser:
     *
     * - a bare identifier: exactly one `const NAME = '…'` (optionally typed) declaration in the
     *   source — `let`/`var` never resolve, since a reassignable binding is not the runtime value
     *   with certainty;
     * - a member access (`OBJ.PROP`): exactly one `const`/`enum` declaration of `OBJ` with a
     *   *flat* brace body (no nested braces — a nested body could put `PROP` under a different
     *   sub-object, which would be a guess), and exactly one `PROP` member inside that body.
     *
     * Anything else — a backtick, a nested call, a `+` inside the expression, bracket access — is
     * rejected by the shape checks and resolves to null.
     */
    private function sameModuleConstant(string $expression, string $source): ?string
    {
        if (preg_match('/^[A-Za-z_$][\w$]*$/', $expression) === 1) {
            $name = preg_quote($expression, '/');

            if (preg_match_all('/\bconst\s+' . $name . '\s*(?::[^=\n]+)?=\s*([\'"])((?:(?!\1).)+)\1/', $source, $matches) === 1) {
                return $matches[2][0];
            }

            return null;
        }

        if (preg_match('/^([A-Za-z_$][\w$]*)\.([A-Za-z_$][\w$]*)$/', $expression, $parts) === 1) {
            [, $object, $property] = $parts;
            $objectPattern = preg_quote($object, '/');

            if (preg_match_all('/\b(?:const|enum)\s+' . $objectPattern . '\s*(?::[^={]+)?=?\s*\{([^{}]*)\}/', $source, $bodies) !== 1) {
                return null;
            }

            $propertyPattern = preg_quote($property, '/');

            if (preg_match_all('/\b' . $propertyPattern . '\s*[:=]\s*([\'"])((?:(?!\1).)+)\1/', $bodies[1][0], $members) === 1) {
                return $members[2][0];
            }
        }

        return null;
    }

    /**
     * Every root-relative endpoint candidate, deduplicated per (uri, method). Plain string
     * literals (`axios.get('/api/posts')`) and backtick templates (`fetch(`/posts/${id}`)` —
     * THE parameterised idiom in apps without Ziggy or Wayfinder, each `${…}` collapsing to a
     * one-segment wildcard token); the query/fragment is not part of the route template, and a
     * template left containing whitespace is an HTML string, not a URL. A literal only becomes a
     * candidate in call-argument position — directly inside a call's `(` or after a `,` — so a
     * verb-named call directly around the literal (`.post('/x'`, `put('/x'`) pins the HTTP
     * method; any other shape — `fetch()` options, wrappers — stays null so uncertainty never
     * narrows.
     *
     * Unanchored matching had a real cost — a data/constants/nav-link file or generated route
     * map whose strings happen to match real route templates flooded seeds and, through
     * `richter:affected-tests`, false-selected unrelated backend tests. The call-argument anchor
     * trades two documented recall losses for eliminating that surface: a `/`-leading literal
     * assigned to a variable and fetched later (`const URL = '/x'; fetch(URL)`), and a
     * `{url: '/x'}` options-object property (indistinguishable from any other property value at
     * the regex level). One residual false-positive surface remains, accepted: an array tail —
     * `['/a', '/b']` — still matches its comma-anchored elements, because dropping `,` from the
     * anchor would also lose the real `request(method, url)` second-argument idiom.
     *
     * @return array<string, array{uri: string, method: string|null}>
     */
    private function uriCandidates(string $source): array
    {
        $uris = [];

        preg_match_all('/(?:\b(get|post|put|patch|delete)\s*\(|[(,])\s*[\'"](\/[^\'"\s?#]*)[?#]?[^\'"]*[\'"]/i', $source, $literals, PREG_SET_ORDER);

        foreach ($literals as $literal) {
            $method = $literal[1] === '' ? null : strtolower($literal[1]);
            $uris[$literal[2] . '|' . ($method ?? '')] = ['uri' => $literal[2], 'method' => $method];
        }

        preg_match_all('/(?:\b(get|post|put|patch|delete)\s*\(|[(,])\s*`(\/[^`]*)`/i', $source, $templates, PREG_SET_ORDER);

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
