# Why Richter?

Richter measures the magnitude of impact of code changes in a Laravel codebase. Like the Richter scale, but for your PHP.

Run `php artisan richter:detect-changes` on a branch and it reports the HTTP and CLI entry points the diff can reach, flags the ones no test references, and attaches a coarse advisory risk level. Review then starts from what the change reaches, instead of from a cold diff.

## What it gives you

**Member-level change impact.** A one-method change seeds that method in the code graph, not the whole class. The graph covers routes, controllers, jobs, listeners, policies, resources, Blade views, and Eloquent relations, plus [edges a route-anchored analysis misses](15-coverage.md): static calls, facades, container bindings, config-keyed class registries, views rendered outside a route, constant reads, polymorphic overrides, and the classes a method constructs.

**Honest degradation.** A change the graph cannot place reads **UNRESOLVED**, never a falsely reassuring "no impact". When *nothing* in the diff could be placed, the risk level says so rather than reading as a measurement. A coverage gap costs reach, but it never causes anything to be reported as unaffected.

**Test-coverage prompts.** Every reached entry point is tagged `[test-referenced]` or `[⚠ no test references this]`. This is a heuristic prompt rather than a coverage verdict ([tag details](04-detect-changes.md#test-reference-tags)). An entry point whose behaviour you changed with nothing referencing it is a place to add a test.

**Blast radius and traces on demand.** Before a refactor, [`richter:impact`](09-impact.md) lists a symbol's callers (what breaks if you change it), its dependencies (what it reaches), and the entry surfaces behind those callers. [`richter:trace`](10-trace.md) answers "how does this even reach that?" with the shortest call chain between two symbols.

**Affected-test selection.** [`richter:affected-tests`](11-affected-tests.md) turns the diff's reach into a test selection, with an exit-code contract that fails toward running the full suite whenever the selection cannot be trusted.

**Built for coding agents.** Richter registers a local [MCP server](13-mcp-server.md) exposing every analysis read-only, so an agent can work with the graph mid-review without shelling out. The `--markdown` report is ready to post as a pull-request comment.

## Advisory by default

`richter:detect-changes` exits 0. A low or empty result is a signal, not a guarantee of no impact. Opt into a CI gate with `--fail-on` / `--fail-on-unresolved`; see [Gating in CI](08-ci-gating.md).

## How the analysis runs

The analysis is static, built on [Laravel Brain](https://github.com/laramint/laravel-brain), and fast enough to run on every branch. It never executes your application's routes, jobs, or commands.

It does, however, autoload classes from the analyzed checkout, to resolve constants, relation names, and queue interfaces. Autoloading runs a file's top-level code. Treat a checkout you would not `composer install` on as one you should not analyze either.
