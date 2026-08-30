# Why Richter?

Richter measures the magnitude of impact of code changes in a Laravel codebase. Like the Richter scale, but for your PHP.

Run `php artisan richter:detect-changes` on a branch and it reports the HTTP and CLI entry points the diff can reach, flags the ones no test references, and names the hazards the change carries. Review then starts from what the change reaches, instead of from a cold diff.

## What it gives you

### Member-level change impact

A one-method change seeds that method in the code graph, not the whole class. The graph covers routes, controllers, jobs, listeners, policies, resources, Blade views, and Eloquent relations, plus [edges a route-anchored analysis misses](16-coverage.md): static calls, facades, container bindings, config-keyed class registries, views rendered outside a route, constant reads, polymorphic overrides, and the classes a method constructs.

### Change hazards

A hazard is a property of the diff saying the change may break something: an authorization guard removed, a rate limit raised, a mass-assignment surface widened, a payload key a consumer still reads. Each carries a tier from 1 to 3. A predicate that cannot read both sides of the comparison stays silent instead of guessing, so every hazard on the report is one richter read in full.

The [risk level](08-risk-levels.md) comes from the worst hazard and how far it reaches. A change carrying no hazard is graded on something else: whether anything would catch a regression in what it does reach.

### Test-coverage prompts

Every reached entry point is tagged `[test-referenced]` or `[⚠ no test references this]`. This is a heuristic prompt rather than a coverage verdict ([tag details](05-detect-changes.md#test-reference-tags)). An entry point whose behaviour you changed with nothing referencing it is a place to add a test.

### Blast radius and traces on demand

Before a refactor, [`richter:impact`](10-impact.md) lists a symbol's callers (what breaks if you change it), its dependencies (what it reaches), and the entry surfaces behind those callers. [`richter:trace`](11-trace.md) answers "how does this even reach that?" with the shortest call chain between two symbols.

### Affected-test selection

[`richter:affected-tests`](12-affected-tests.md) turns the diff's reach into a test selection, with an exit-code contract that fails toward running the full suite whenever the selection cannot be trusted.

### Built for coding agents

Richter registers a local [MCP server](14-mcp-server.md) exposing every analysis read-only, so an agent can work with the graph mid-review without shelling out. The `--markdown` report is ready to post as a pull-request comment.

## Advisory by default

`richter:detect-changes` exits 0. A low or empty result is a signal, not a guarantee of no impact. Opt into a CI gate with `--fail-on`, `--fail-on-hazard` or `--fail-on-unresolved`; see [Gating in CI](09-ci-gating.md).

## How the analysis runs

The analysis is static, built on [Laravel Brain](https://github.com/laramint/laravel-brain), and fast enough to run on every branch. It never executes your application's routes, jobs, or commands.

It does, however, autoload classes from the analyzed checkout, to resolve constants, relation names, and queue interfaces. Autoloading runs a file's top-level code. Treat a checkout you would not `composer install` on as one you should not analyze either.
