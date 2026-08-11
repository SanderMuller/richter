# Middleware group annotation

## Overview

A middleware that routes reach through a **group** is linked to nothing in the graph. Route
middleware is resolved by ALIAS only upstream, so `->middleware('api')` arrives as a bare
`middleware::api` node and the classes inside that group hang off nothing. The class still
self-lists as an entry point — it is under `\Http\Middleware\`, an entry-point namespace — so a
reviewer reads "one entry point: the middleware itself" for a change that runs on every route in
the group.

The failure is a wrongly **sized** answer, not a missing one. This adds the size as a finding, and
deliberately does not add the edges.

## Assumptions

- **The group is not expanded into edges.** Mapping a global group onto every route would make each
  of its members report every route in the app as an entry point. That trade was made before this
  spec and is not reopened here; the note exists precisely because the edges are withheld.
- **The note never moves risk.** Letting a group's routes count toward reach would raise the risk
  level of every middleware edit in every consuming app at once — the same silent shift refused for
  `model-to-policy` and for complexity metrics. Advisory only: never `risk`, `--fail-on`, or
  `affected-tests`. Counting them is a separate decision that needs its own evidence.
- **Only group members matter.** An aliased middleware already reaches its routes;
  `MiddlewareAliases` rewrites `middleware::auth` onto the FQCN and the chain joins up. This lane is
  for the members a group carries and an alias does not.
- **The extraction is Brain's, unused.** `MiddlewareAnalyzer::analyze(string $projectRoot)` already
  reads `$middlewareGroups` from a Laravel 10 Kernel and the `->web(append: [...])` form from a
  Laravel 11+ `bootstrap/app.php`, and returns them on a public `MiddlewareRegistry::$groups`.
  Nothing upstream consumes it: `resolveGroup()` has no callers in the whole package. A path-based,
  route-free entry point — the same shape the facade lane took from `FacadeAnalyzer`.

## 1. Current state

- `GraphBuilder::resolveMiddlewares()` (`vendor/…/GraphBuilder.php:1955`) calls `resolveAlias` and
  never `resolveGroup`, so a group name passes through unchanged into the node id.
- `CodeGraphBuilder::resolveMiddlewareAliases()` rewrites alias nodes onto FQCNs and documents that
  group aliases are deliberately left alone.
- Nothing in `src/` reads `$middlewareGroups`. The only mention is a docblock in
  `PublicWriteAuthCrossCheck` noting that Brain parses groups and never expands them.

## 2. Proposed change

`MiddlewareGroupFindings`, a beside-class in the shape of `EntryPointRootCoverage` and
`PublicWriteAuthCrossCheck` — `ImpactAnalyzer` sits at the PHPStan class-complexity ceiling, so the
work goes next to it and the analyzer gains two lines.

Membership is built lazily, once per run, and only when something asks: the analyzer parses at most
one file, but a diff that touches no middleware should not pay even that. Per group:

1. `new MiddlewareAnalyzer()->analyze($root)->groups` — group name to the entries as written.
2. Each entry resolved to an FQCN: parameters cut at the first colon (`tenant:strict` is one alias
   with an argument), then through richter's own alias map.
3. Routes guarding the group counted off the registered route table (`Route::getRoutes()`), falling back to `route:: → middleware::<group>` edges when the analysed project is not the running application.

```text
! App\Http\Middleware\EnsureTenant runs in middleware group 'api', which guards 142 routes;
  group membership is not drawn as edges, so those routes are not in the reach above
```

## Edge Cases

| Case | Behaviour |
|---|---|
| Member written as `Foo::class` | Resolved directly. |
| Member written as an alias (`'tenant'`) | Resolved through richter's alias map, so it reaches the same FQCN a changed file resolves to. |
| Member written with parameters (`'tenant:strict'`) | Split at the first colon before the lookup. |
| Group no route in the graph references | No note. "Guards 0 routes" sizes nothing and teaches its reader to skip the check. |
| Middleware in two groups | One note each — one fact per line. |
| A group that names another group | Expanded: Laravel resolves a nested group, so the inner group's members run on the outer group's routes and get a note for both. |
| A cycle between two groups | Terminates on a seen-set; each group is expanded once. |
| A name that is both a group and an alias | Skipped. Resolving it one way needs the resolution order the reader does not have, and the wrong choice points the note at the wrong routes. |
| A non-route caller of the group node (controller-level attachment) | Not counted. The note sizes endpoints. |
| The group is applied by a provider looping over route files | Counted. The registered route table is the source, not the graph: those routes draw no `route:: → middleware::<group>` edge, and counting edges under-reported 420 as 36 on a real application. |
| Richter analyses a checkout other than the running application | Falls back to the graph's edge count, which under-counts. The router describes the booted app, and counting a stranger's routes would be worse than counting too few. |
| No Kernel and no `bootstrap/app.php` | No note. |
| Upgraded app that kept an empty Kernel stub beside bootstrap groups | No note. Brain's analyzer takes the Kernel when both exist, where richter's alias reader prefers bootstrap; an empty stub therefore yields no groups. A reach limit, never a wrong one. |
| Unreadable or exotic Kernel | No note. A missing annotation, never a failed report. |

## Implementation

### Phase 1: The lane (Priority: HIGH)

**ID:** lane · **Depends:** none

- [x] `MiddlewareGroupFindings` with lazy membership and the route count.
- [x] Tests — each resolution form, both silences, multi-group, route-only counting.

### Phase 2: Wire it into the report (Priority: HIGH)

**ID:** wiring · **Depends:** lane

- [x] Consulted per changed file in `ImpactAnalyzer::detectChanges()`.
- [x] End-to-end test: the note reaches a real report, and the risk level and entry points are
      identical with the Kernel present and absent.
- [x] README annotation bullet.

## Not in scope

Counting a group's routes toward reach and risk. It is the obvious next question and the answer is
not obvious: a middleware in `web` genuinely affects every web route, so counting them is arguably
correct rather than inflationary — but it would move every consumer's risk output at once, and that
belongs behind the benchmark corpus rather than in this change.
