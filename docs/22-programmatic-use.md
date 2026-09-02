# Using richter from your own code

Every documented surface so far is a command or an MCP tool. If you are building something that needs the analysis itself, you can call the same classes those surfaces call instead of shelling out to Artisan and parsing the output.

This page names the entry points meant for that. All of them are what the commands and the MCP tools use.

## Get a graph

Resolve `GraphCache` from the container rather than constructing it. It is registered as a singleton, so one process builds the graph at most once and reuses it:

```php
use SanderMuller\Richter\Graph\GraphCache;

$graph = app(GraphCache::class)->graph();
```

That is the expensive call. It serves from the on-disk cache when the fingerprint matches and builds when it does not. See [the graph cache](15-graph-cache.md), and [bake an entry at deploy time](15-graph-cache.md#baking-an-entry-at-deploy-time) if this runs anywhere a build would be too slow.

`graph()` takes `fresh: true` to bypass the cache entirely, the same escape hatch `--no-cache` gives on the commands.

## Ask it something

Four questions, four entry points. Each takes the graph and returns an array, in the same shape the matching `--json` document has.

```php
use SanderMuller\Richter\Analysis\ImpactAnalyzer;
use SanderMuller\Richter\Analysis\SymbolLocator;
use SanderMuller\Richter\Analysis\SymbolTracer;

// Where is it? Cheap: it does not walk the graph.
$found = new SymbolLocator($graph)->locateSymbol('App\Models\Post');
$defined = new SymbolLocator($graph)->locateFile('app/Models/Post.php');

// What breaks if this changes?
$impact = new ImpactAnalyzer($graph)->impact('App\Models\Post');

// Does this reach that, and how?
$path = new ImpactAnalyzer($graph)->trace('App\Http\Controllers\PostController', 'App\Services\PostPublisher');

// The same trace without the rest of the analyzer.
$path = new SymbolTracer($graph)->trace('PostController', 'PostPublisher');
```

Reach for `locate` first when you do not already have an exact node id. Both `impact` and `trace` need one, and without `locate` the way to get it is to run `impact` and discard the rest. See [locate](21-locate.md).

For the branch diff rather than a single symbol, `ImpactAnalyzer::detectChanges()` takes the changed-file map and returns the [detect-changes](05-detect-changes.md) document. `AffectedTests::selectForCurrentDiff()` returns the [test selection](12-affected-tests.md), including its `determinable` flag. Read that flag before trusting the list, exactly as the command does.

## Render it

The analyzers return arrays. The formatters turn one into output, and every command and tool goes through them, so what you render matches what `richter:*` prints:

```php
use SanderMuller\Richter\Analysis\ImpactFormatter;   // plain text
use SanderMuller\Richter\Analysis\JsonPresenter;     // the --json document
use SanderMuller\Richter\Analysis\MarkdownFormatter; // GitHub-flavoured markdown

echo ImpactFormatter::impact($impact);
$document = JsonPresenter::impact($impact);
$forAPullRequest = MarkdownFormatter::trace($path);
```

Each of those three carries `impact()`, `trace()`, `locate()` and `detectChanges()`. `JsonPresenter::encode()` is the serializer the `--json` commands use, if you want byte-identical output.

There is a fourth, `HtmlFormatter`, behind `detect-changes --html` and `task-slice`. It renders `detectChanges()` only, and it takes considerably more than a result: the changed-file map, the base ref, an optional gate verdict and editor link. Use it if you want that report; the three above are the ones that take a result and give you a string.

Read `JsonPresenter`'s output when you need a stable shape. An analyzer result also carries working state that the presenters drop on purpose, so its keys are not the documented contract.

## Configuration

`RichterConfig` reads every `config/richter.php` key through one validating accessor per key: `RichterConfig::entryPointRoots()`, `cacheDirectory()`, `hazardsEnabled()`, and so on. Prefer it over `config('richter.…')`: a malformed value throws where you can see it rather than failing later inside the analysis.

## What is supported, and what is not

The classes above are the supported surface. They are the ones the commands and MCP tools use, they are covered by tests as public behaviour, and their shapes are governed by [semver](https://semver.org).

Everything else in `src/` falls into two groups.

Roughly a third of the classes carry an `@internal` marker. They are plumbing shaped by a single caller: tracers, hazard lanes, cache internals. Those change in any release, patch releases included. Some markers sit on individual methods of otherwise-supported classes, such as `GraphCache::warm()` and `CodeGraph::definedFiles()`, so check the method you are calling rather than the class around it.

The rest are unmarked but not named on this page. PHP has no visibility narrower than `public`, so a class is often reachable only because another class in the package needs it. A missing `@internal` tag is not an invitation.

If you need something this page does not name, open an issue. The answer is either to document it here or to mark it `@internal`, and both are better than a consumer depending on a symbol the package did not know was load-bearing.

## Where this runs matters

Building the graph is the dominant cost, and the fingerprint sweep runs on every call in every process. See [what a baked cache buys](15-graph-cache.md#what-a-baked-cache-buys-and-what-it-does-not). Two consequences for a long-lived or request-scoped consumer:

- Resolve `GraphCache` from the container so the in-memory graph is reused across calls in one process.
- Bake an entry at deploy time with [`richter:warm`](15-graph-cache.md#baking-an-entry-at-deploy-time), and gate the deploy on `richter:warm --check` so a silent cache miss cannot turn every request into a full build.
