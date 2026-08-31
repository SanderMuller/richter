# Detecting change impact

`richter:detect-changes` is the main command. It resolves which class members the branch changed, walks the graph, and reports what those changes reach.

```bash
php artisan richter:detect-changes                        # diffs against richter.default_base
php artisan richter:detect-changes --base=origin/develop
php artisan richter:detect-changes --head=HEAD            # the committed tree, ignoring uncommitted work
php artisan richter:detect-changes --explain              # show how each entry point reaches the change
php artisan richter:detect-changes --json                 # machine-readable, for scripting or CI
php artisan richter:detect-changes --markdown             # PR-ready markdown, for descriptions and comments
php artisan richter:detect-changes --html=impact.html     # self-contained visual report (add --open to launch it)
```

## Which diff is analysed

Against the default `HEAD`, the diff is the working tree compared to the merge-base with `--base`. Staged and unstaged edits are included, not just what is committed, so running this before you commit still sees your changes.

`--head=<ref>` analyses that ref's committed tree instead, for a run in a dirty checkout whose uncommitted work is not the subject. `--head=HEAD` resolves to the commit, so it means the last commit rather than the working tree.

The one gap `git diff` cannot close is a brand-new file that was never `git add`-ed: it shows in no diff form. A stderr-only note flags any such untracked file under a watched root, naming the paths rather than the roots. The note never reaches stdout, so `--json` and `--markdown` output stays exactly the report. Under `--head` there is no such note, because a file never added cannot be part of a committed tree.

An untracked **migration** is the exception: it is read from the working tree and analysed, so it raises its hazards like any other migration and is not named in that note. Every other watched root holds files that are normally edited, which a diff sees; a migration is normally a brand-new file, so the gap fell on this lane's whole subject at exactly the moment the migration is newest. It is analysed as what it is. A new file, so everything its `up()` does, against no base. Under `--head` it is skipped with everything else untracked.

`--head` is also how you replay history without losing your own configuration. Checking an old commit out reverts every tracked file, `config/richter.php` among them, so a replayed diff silently runs on package defaults instead of your own `hazards.ignore` and `payload_parity` settings: the same diff, a different report. Pointing `--head` at the commit leaves the working tree alone, and the config with it.

## What the report contains

- The entry points the change can reach, each tagged `[test-referenced]` or `[⚠ no test references this]`: routes, commands, jobs, listeners, middleware, and Livewire/Filament/Nova component classes. A Blade-mounted component, a Filament resource, page or widget, and a Nova resource are each a user-facing surface even without a `route::` node.
- Findings in the changed source itself, such as an eager-load or relation string that names no relation on any model. A missing comma between two relation constants is the classic case: `Post::OWNER . User::PROFILE` concatenates to `ownerprofile`, a name Eloquent silently never resolves.
- The [risk level](08-risk-levels.md) (`low` / `medium` / `high`), always with the reason for it.
- The [hazards](08-risk-levels.md#hazards) the change carries, worst tier first.
- Honest degradation. A change that cannot be placed in the graph reads `UNRESOLVED` rather than "no impact", and an unfollowable dispatch makes a queue job read "unknown" rather than "none". A file that resolved to no graph node also echoes the FQCN its path derived to (`app/Services/Inspector.php → App\Services\Inspector`), which is what separates a coverage gap from a wrong root namespace.

```text
Changed files:
  app/Models/Post.php (4 graph nodes)
  app/Services/CategoryImporter.php (0 graph nodes)  (UNRESOLVED: reach for this file could not be fully determined)

Entry points reached: 2 (some changed files could not be fully placed — see UNRESOLVED above)
  - command::categories:sync  (app/Console/Commands/SyncCategories.php)  [test-referenced]
  - route::PATCH::/api/posts/{post}  (routes/api.php:41)  [⚠ no test references this]  [authed]

Related models (association reach — context, not risk): 1
  - App\Models\Category

Related by inheritance, not by a call (trait or override — context, not risk): 3
  - App\Models\Post (uses-trait)
  2 overrides across 1 member name — each declares the member itself:
  - applyFilters — 2 classes: App\Builders\InvoiceBuilder, App\Builders\QuoteBuilder

Findings (in the changed source itself):
  ! app/Models/Post.php: eager-load string 'ownerprofile': segment 'ownerprofile' is not a method on any model — check the relation name (a broken constant concatenation reads exactly like this)

Risk:   MEDIUM (advisory) — no hazard; 2 of 3 reached surfaces have no test referencing them
Impact: 2 entry point(s) · 7 impacted node(s)
```

## When a report of nothing is correct

A report of nothing is a claim about your diff, not only about the code. Richter resolves changes to class members, so an edit that changes a file without changing a member (a comment after the closing brace, a `use` reordering) genuinely seeds nothing and correctly reports nothing. That is the first thing to rule out when a change you expected to light up comes back empty, and it is the most common reason a probe of Richter's own behaviour misleads its author.

A member added to an existing class seeds nothing: nothing called it before, so it can break nothing. A brand-new file is different. The class itself is new, so it seeds on its class node and reports its reach and its own entry surface (a new command, job or listener), marked `[new file]` in the report.

A new class that reaches nothing still reads `low`, because it cannot break behaviour that already runs. One that does reach existing surfaces is graded like any other change. And an addition is not automatically harmless: adding an entry to `$fillable` widens what a mass assignment may write, which is a tier-2 hazard whatever else the diff does.

## `--explain`

With `--explain`, each reached entry point carries the shortest call chain down to the changed code. That is the difference between knowing a change reaches `PATCH /api/posts/{post}` and seeing exactly which controller and service carry it there:

```text
Entry points reached: 1
  - route::PATCH::/api/posts/{post}  [⚠ no test references this]
      ↳ route::PATCH::/api/posts/{post} →(route-to-controller) App\Http\Controllers\PostController::update →(action-to-service) App\Services\PostPublisher::publish
```

`--explain` composes with `--markdown`.

## Test-reference tags

Every reached entry point is tagged `[test-referenced]` or `[⚠ no test references this]`. A referenced entry point whose referencing tests contain no behavioural assertion the scan recognises is tagged `[test-referenced. No behavioural assertion found]`.

These are heuristic prompts rather than coverage verdicts. An entry point whose behaviour you changed with nothing referencing it is a place to add a test; the tag flags a missing reference, not proof the code is untested. The `tests/` scan behind the tags only runs when an entry surface was actually reached.

A `schedule::` surface resolves through the command it runs, so a test driving that command references the scheduled surface too. A schedule reaching no command, a scheduled closure among them, stays "could not be checked" rather than claiming no test drives it.

A route matched by neither its name nor a literal URI in the tests resolves through the class that handles it. A Livewire component, a Filament page, or a Filament resource over two hops. A test importing that class references the route. Only a runnable `*Test.php` file grades the surface referenced: a fixture or a base case importing the same class is a reference, but not coverage, so it never turns the tag green. The same resolution runs in `impact` and in the MCP `impact` tool, so all three report the same route the same way.

## Changed files no lane analyses

Three kinds of changed file carry impact: PHP under `app/`, Blade views, and sources under a configured frontend root. A diff can consist entirely of files that are none of those: a stylesheet, a CI workflow, a lockfile, a `config/` guard, an infrastructure manifest. None of them reach a backend entry point through any lane here, so none affect the reach or the risk level, and a diff of nothing else reports `No changed PHP files under app/ against <base>.`

Route files are read, but only for one thing. `routes/*.php` is compared for the guard middleware a route lost, and `bootstrap/app.php` for the guard a middleware group lost. Both are hazards and both can move the risk level. Neither seeds reach of its own: which routes a file registers is Brain's answer, not this parser's, and a seed built from a URI written there would be a guess about a node id.

Migrations are read the same way and for one thing only. A `database/migrations/*.php` file is compared for the destructive schema operations its `up()` performs, which are hazards. It seeds nothing either: a migration names a table, not a class.

That sentence is accurate about the analyser and misleading about the diff, so the count is named on stderr beside it:

```text
Note: 2 changed file(s) are outside the analysed scope (not PHP under app/, a Blade view, or a configured frontend root) and were not analysed: resources/sass/app.scss, vapor.yml
```

Up to five names are listed, with a count of the rest. Stderr, like the untracked-file note, so `--json` and `--markdown` stdout stay exactly the report. Frontend files the configuration declines to scan are not counted. Generated Wayfinder and Ziggy output under `frontend.generated_paths` and `.d.ts` declarations were silenced on purpose, so counting them would make the note longest on the churn the project already chose to ignore.

## Unplaceable files and the defined-node fallback

Before a file falls through to UNRESOLVED, Richter tries one last lane: the nodes the graph says that file defines. Not every entry surface has a class name to look up. A scheduled task is identified by what it runs and how often, not by a class name, so a change to a legacy `app/Console/Kernel.php` would otherwise be unplaceable despite defining surfaces the graph already knows. The lane reaches only files a lane above already picked up, so it applies within `app/`. A change to `routes/api.php` is read for its guard middleware (see above) and seeds nothing, so it never reaches this lane.

Those surfaces list as touched, but they are never walked and they are not among the surfaces the level grades. A file that declares a surface has not called into it: adding one line to a `$commands` array cannot break the ten commands registered beside it, and rating the edit by everything those ten reach would be breadth dressed up as consequence. The lane runs only when every other lane came up empty, so member-level precision elsewhere is unaffected: a one-method change to a controller still seeds that method, not the class its file also defines.

## Which rows come first

A change that touches one widely-referenced class reaches most of the application, and the report
cannot print all of it: the prose formats show the first fifteen surfaces and count the rest. Those
fifteen used to be whichever surfaces sorted first by name, which on a real feature commit meant
admin panels and list screens while the routes the commit actually added sat below the cut.

Rows are now ordered by how specifically the diff explains them. Each reached surface is attributed
to the changed file with the SMALLEST reach of its own that reaches it: a changed controller that
reaches nine surfaces explains its own route better than a changed model that reaches ninety. The
machine payload carries the same fact as `entryPointAttribution`, so an agent can filter on it
instead of guessing which rows belong to the task.

The `entryPoints` array in `--json` and in the MCP tool is in that same reading order, so a prefix of
it is the prefix a human sees. It carried the raw walk order until 0.62.0, which on a report with
hundreds of surfaces meant the machine list and the printed list could share no prefix at all.

Ordering ranks how specifically the diff explains a surface. It does not rank how much the surface has
to do with the task. A changed Livewire class or a changed enum that reaches a single surface ranks
alongside a command the same commit added, so the first rows are not a checklist of what the feature
owns: deciding that still means dropping the hubs you recognise.

Nothing is hidden or folded. The same surfaces are reported, in a different order, and the count
beside the list is unchanged. A surface no per-file walk explains, such as a changed class that is
itself an entry point or a frontend surface, carries no attribution and sorts last, never dropped.

`richter:impact` analyses a single symbol, so it has no per-file attribution to make: its rows keep
the plain-name order they have always had, in its `--json` payload as well as in its report.

## Which rows are this task's

Ordering answers "what should I read first". A second question — "which of these does the work in
front of me own?" — needs something the analysis cannot derive. Two measured applications produced no
rule for it, so richter asks:

```php
'task_slice' => [
    'hub_paths' => ['app/Providers/AppServiceProvider.php'],
    'hub_path_prefixes' => ['app/Admin/'],
],
```

A surface reached only through one of those files is fan-out rather than a surface this change owns,
and the reports fold it under that cause: `1 surface reached only through app/Models/Article.php,
which this project lists as a hub — context, not surfaces this change forgot`. The machine payload
carries the same split as `entryPointKeepSet`.

Three rules keep this honest:

- **Both lists empty means off.** Every surface is kept and nothing folds. Richter ships no default
  hub list and infers none — a guessed fold presented as a finding is worse than a long list.
- **A surface whose own file is in the diff is kept**, even when that file sits under a hub prefix.
  You edited that class; you did not merely touch the hub behind it.
- **A surface with no attribution is kept.** Nothing explains it, and hiding what the walk could not
  classify is the wrong direction to fail — the routes a changed frontend file references are never
  attributed, so a rule that dropped the unexplained would drop what a frontend change owns.

The count beside the list stays the full total, and `entryPoints` still carries every surface. Nothing
is removed; a fold changes which rows are inline.

## Entry surfaces reached only by association

An entry point in the main list is something that calls the changed code. A surface connected to it only through a model relation is not: it is associated with the change, and nothing there runs the changed code. Those are reported separately:

```text
Entry surfaces reached only by association (context, not callers): 6
```

The distinction matters most on a model change, where an Eloquent chain can walk from the changed model to admin screens all over the application. Listing those beside real callers made the reached count read far higher than the change warranted and buried the routes that do run the code.

The demoted set is narrow: `model-relationship` and `model-to-policy`, the two edges that associate rather than invoke, plus `config-registry-fanout`, a registry read whose key names no single class, so the surfaces behind it are the same for every class its file names. `override` and an enumerated `config-registry` read are over-approximated calls (the dispatch is real, only the target is uncertain), so a surface behind one stays in the main list. An `override` counts as a call only where a call reaches it: the walk refuses to follow one out of a node it arrived at through type structure alone, so a change to one class in a wide interface hierarchy does not report every sibling implementor. Association surfaces are not graded by the level either, which matches how the impacted total has always treated them.

## More annotation

The report carries more advisory annotation (security exposure, Pennant gates, middleware group membership), none of which feeds the risk level or a `--fail-on` gate. Payload parity is the exception and no longer sits here: its three checks are tier-2 hazards, so they do move the level. See [Report annotations](06-report-annotations.md).
