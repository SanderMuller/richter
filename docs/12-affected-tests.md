# Affected-test selection

`richter:affected-tests` turns the diff's reach into a test selection.

```bash
php artisan richter:affected-tests                        # human-readable selection
php artisan richter:affected-tests --base=origin/develop
php artisan richter:affected-tests --head=HEAD            # select against the committed tree
php artisan richter:affected-tests --json                 # {base, determinable, reasons, tests, testsTotal, testsShare, testsExcluded, frontendTests, unreferencedEntryPoints, unresolvedDispatchSites}
php artisan test $(php artisan richter:affected-tests --plain)   # simple form: coarse but safe
```

It selects the test files that reference any entry point the diff reaches, plus the tests that import any changed or reached class, plus any test file the diff itself touched. That last one needs no graph reasoning and gets none, since `tests/` is outside every tree the analysis reads. It diffs the same way [`detect-changes`](05-detect-changes.md#which-diff-is-analysed) does, so staged and unstaged edits are included. Selection is reference-based recall, not proof of coverage.

## The exit-code contract

It fails safe, and the exit code is the contract:

| Exit | Meaning |
|---|---|
| `0` | Selection determined (possibly empty). |
| `2` | **Not determinable: run the full suite.** The tests the run did name are still printed, under a heading that calls them a floor rather than the selection, and `--plain` stdout stays empty so a shell fallback still runs everything. Any UNRESOLVED file, low-confidence seed, an unparseable app file, an unfollowable dispatch *that a possible dispatch target in the change's reach could hide*, an uncheckable entry point, or an untracked relevant file `git diff` cannot see trips this. The reasons are printed (text) or carried in `reasons` (JSON). An unfollowable dispatch names each site as `file:line (Dispatcher::method)` so it can be restructured rather than only lived with, though [some shapes cannot be](#unfollowable-dispatches); a low-confidence seed names each member it could not pin as `file (Class::member, kind)`, and the kind is the part that says what to do, a `property` or a `class declaration` has no member node by design, so the verdict is right and there is nothing to restructure. |
| `1` | Usage or unexpected error. |

The simple form only ever errs toward running more: both an undetermined selection and a determined-but-empty one leave `$(…)` empty, and an argument-less runner executes the full suite.

To also skip the run when the selection is determined and empty, branch on the exit code:

```bash
tests=$(php artisan richter:affected-tests --plain); status=$?
if [ "$status" -eq 0 ] && [ -z "$tests" ]; then echo "No affected tests."
elif [ "$status" -eq 0 ]; then php artisan test $tests
else php artisan test; fi   # exit 2: not determinable, run the full suite
```

## How tests are selected

The command inverts the test-reference index into a selection: the test files that reference any entry point the diff reaches, plus the tests that import any changed **or reached** class (a unit test of an intermediate caller never touches an entry point). A test naming a Livewire component by string (`Livewire::test('admin.dashboard')`, the `livewire()` helper) counts as referencing `App\Livewire\Admin\Dashboard` via the default naming convention. A `schedule::` entry resolves through the command it runs, and a route matched by neither its name nor a literal URI resolves through the class handling it, so a test importing a Livewire component or a Filament page selects the route driven by it. Only conventionally-named `*Test.php` files are selected; helpers and fixtures under `tests/` never end up as runner arguments, and an entry point whose only references live in a support trait blocks determination rather than silently dropping the tests using that trait. Selection is reference-based recall, not proof of coverage: reached entry points nothing references contribute nothing, and the report says how many those are.

## Unfollowable dispatches

A dispatch whose target cannot be seen statically (a variable or a factory call) hides a
`dispatcher → job::handle` edge, so it makes the selection undeterminable whenever a possible dispatch
target sits in the change's reach. The reason names every such site as
`file:line (Dispatcher::method)`:

```text
the graph contains job dispatches that could not be followed:
app/Jobs/Fanout.php:88 (App\Jobs\Fanout::handle), app/Services/Importer.php:12 (App\Services\Importer::run)
```

The site is the dispatch statement, so two opaque items of one `Bus::chain([...])` are one place to
look rather than two. The rendered reason caps at 15 with an `… and N more` tail. `--json` carries the same
sites in full under `unresolvedDispatchSites` (`{file, line, dispatcher}`), so a script tracking them
does not have to read them out of the sentence, and does not lose the ones past the cap. It lists what
blocked *this* selection and is empty when nothing did; for every unfollowable dispatch in the project
regardless of the diff, read the `richter://graph/stats` MCP resource.

There is deliberately **no way to acknowledge a site and have it stop blocking**. Suppressing it would
assert that the hidden edge is harmless, and a wrong assertion under-selects tests, the one direction
this command's exit-code contract exists to prevent. The remedy the named site makes possible is to
restructure the dispatch into a form the tracer can follow (a literal `Job::dispatch()`, or a
`new Job(...)` the tracer can see), which fixes the gap rather than silencing it. A project that
genuinely needs a dynamic dispatch keeps running its full suite, correctly.

**Some sites cannot be cleared from the application side, and it is worth knowing which before you
start.** A named constructor on the job's own class, `dispatch(SendInvoice::forOrder($order))`, is
listed even though the receiver names the job, because nothing in this pass proves that method returns
an instance of its own class (the edge *is* drawn; only the site stays). Rewriting the call site does
not help: any form that keeps the factory keeps the site. Since one site is enough to make every run
report `not determinable`, a project whose remaining sites are all of that kind has a floor it cannot
reach past, and restructuring the others buys nothing until the last one goes. Check the list for these
before planning that work.

Five shapes look unfollowable and are not counted, because none of them hides anything:

- An inline closure. `dispatch(function () { … })` queues the closure itself, and its body sits in the
  same source the tracers already read, so its work already appears as edges out of the dispatching
  member. There is no target to name. The same holds for a closure inside a `Bus::chain([...])`.
- A job the dispatching method built itself. `$job = new SomeJob(...); dispatch($job);` names its target
  one line up, and the graph already carries the edge, so there is nothing hidden and nothing to
  restructure. The bar for this is deliberately high: the method must write that variable exactly once,
  at the top level, before the dispatch. A conditional assignment, a reassignment, a `foreach` binding,
  a reference alias, a by-reference capture, a parameter of the same name, or a dynamic write anywhere
  in the method all leave the site listed, because the value reaching the dispatch is then no longer
  provable.
- A batch of mapped jobs. `Bus::batch($items->map(fn ($i) => new SendInvoice($i))->all())` proves its
  own contents: `map()` returns one item per input item, so the callable's return type is the item
  type whatever the source collection held, and the graph already carries the edge from the `new`
  inside the callable. Every call between the `map` and the dispatch has to be one that cannot change
  what the items are (`filter`, `values`, `sort`, `all` and the like); `pluck`, `flatMap` and
  `toArray` can, so they keep the site. The callable has to be written out at the call site and has to
  return in one place: an arrow function, or a closure whose whole body is a single `return` of a `new`
  dispatch target. A callable that returns a job on one branch and something else on another proves
  nothing about the batch, and one that can fall through returns `null` for some items.

  The chain does not have to be written at the dispatch: a batch of any size names its collection,
  because the code between the map and the dispatch needs it. `$jobs = $items->map(...);` then
  `Bus::batch($jobs->all())` resolves. The bar is the locally-built job's, and one guard stricter. The
  method must write that name exactly once, at the top level, before the dispatch, so a binding under
  an `if`, a rebinding, a `foreach` binding or a dynamic write anywhere keeps the site. It must also
  mention the name nowhere else: a collection is an object, and `$jobs->push($other)` changes what the
  batch holds without writing the name, so any other mention keeps the site as well: a mutator call, or
  a pass to a helper that could keep the handle. A method that reaches its own locals by name rather
  than by writing them proves nothing here either: `compact()` and `get_defined_vars()` hand the
  collection out with no mention to count, and `extract()` and `eval()` can replace it outright.

  The receiver is the one thing this does not check. It reads method names, not types, so a class of
  your own that spells `map()` and `all()` with different semantics is believed. Typing the receiver
  needs the inference the relation lane uses, and it is not wired here.

- An array the dispatching code filled itself. `$chain = []; $chain[] = new FirstJob(...); $chain[] =
  new SecondJob(...); Bus::chain($chain)->dispatch();` names every job right there, so the graph already
  carries the edges and there is nothing to restructure. Unlike the single local above, an append inside
  an `if` is fine: the claim is what the array *contains*, and a branch either appends a named job or
  appends nothing. Appending an inline closure is fine too, for the same reason a closure inside an
  inline `Bus::chain([...])` is, so a chain built only from closures resolves to no jobs and no site.

  **The array does not have to start empty.** `$chain = [new FirstJob(...)];` followed by appends reads as
  well, when every element of that first literal passes the same test an append passes. A key on an
  element is fine (`$chain[] =` appends past the highest integer key, so it cannot collide with one) but
  a spread is not, because `[...$others]` brings in contents from elsewhere.

  **It also does not have to sit in the method body.** Building the follow-up work inside `->then()` or
  `->finally()` is how queued work is normally sequenced, so the accumulator and its dispatch naturally
  end up in a closure together, and a closure body is read as its own scope. What it may NOT do is reach
  in from outside: a name arriving through `use ($chain)`, `use (&$chain)`, or a parameter keeps the site.
  A by-value capture is a second name for the array, so appends inside say nothing about what the outer
  name holds, and a by-reference capture is a mutation this proof cannot bound. For the same reason, a
  dispatch is only ever resolved against an accumulator its own immediate scope owns, never one from an
  enclosing scope, which the closure could not see without capturing it anyway.

  The bar is otherwise absolute, and it has to be, because `$chain[] = …` assigns to an array element
  rather than to the variable: the write counting that guards the shapes above cannot see an append at
  all, so leaning on it would accept a method that also mutates the array some other way. Instead every
  single mention of the name in the method must be one of three things. The `= []` that starts it, the
  left side of an append whose value is a `new` dispatch target or an inline closure, or the read that
  dispatches it. One mention that is none of those keeps the site, whatever it does. That is what makes
  `array_push($chain, $x)`, a keyed write like `$chain[0] = $x`, starting from a non-empty literal,
  starting inside a branch, a wholesale reassignment, a second read, and a scope handed out by
  `compact()` all keep the site without needing to be listed as special cases.

- A string argument. `$this->dispatch('some-event')` is not a job dispatch. `DispatchesJobs::dispatch()`
  takes a job *object*, so a string can never be one. The common case is a Livewire component emitting
  a browser event, which has no queue involvement. A constant the dispatching class itself declares as
  a string (`$this->dispatch(self::SOME_EVENT)`) counts as well. A typed constant works, and so does
  one of several names declared in a single `const` statement:

  ```php
  public const string
      SUBTITLE_CHANGED = 'subtitle-changed',
      SUBTITLE_DELETED = 'subtitle-deleted';
  ```

  Anything further does not: a constant inherited from a parent, one read off another class, or a
  `static::` reference whose value a subclass can replace. Each of those would need what this pass
  cannot see, and guessing risks dropping a genuine unfollowable dispatch, so they stay listed.

## Tests the runner cannot execute

Richter reads nothing from `phpunit.xml`. It scans the whole `tests/` tree, so a Dusk case is discovered like any other test — and a selection that names one hands `php artisan test` a file that dies in `DuskTestCase`, because passing explicit paths bypasses your testsuite definitions. One consumer measured 482 failures from a change that broke nothing.

Name those paths, and the selection stops including them:

```php
// config/richter.php
'tests' => [
    'unrunnable_paths' => ['tests/Browser/*'],
],
```

Globs are relative to the project root, and `*` crosses `/`, so `tests/Browser/*` covers the whole tree under it. The default is empty: an unconfigured project gets the selection it got before, byte for byte.

**Name the key for what the runner cannot execute, not for phpunit's `<exclude>` entries.** Those are different sets. A suite may exclude a directory that another suite runs — an `Api` directory excluded from `Backend` and run under `API` is the common shape — and excluding it here would drop tests that do run. That is under-selection, which is the failure this command exists to prevent. The question this key answers is narrower: can `php artisan test <path>` execute the file at all?

**An excluded test is still coverage.** The exclusion applies to the selection only, never to test discovery. A route covered only by a browser test keeps its `[test-referenced]` annotation in [`detect-changes`](05-detect-changes.md) and [`impact`](10-impact.md), and still feeds the [risk level](08-risk-levels.md). Only the list of files to run drops it. A Dusk test the diff itself touched is dropped too: it is in the diff and it still cannot run here.

## How large is the selection?

A selection can cover most of the suite and still report `determinable: true` with no reasons — correct by the contract, and useless to a caller who cannot see it. Three fields say how large it is:

| Field | Meaning |
|---|---|
| `testsTotal` | Runnable test files in the **suite**, after removing the unrunnable paths above |
| `testsShare` | `tests` divided by `testsTotal`, two decimals; `0.0` when the suite has no runnable files. JSON has one number type, so a whole value arrives without its fraction — `1.0` reads as `1` |
| `testsExcluded` | Files **this run** dropped as unrunnable |

`testsTotal` describes the suite and `testsExcluded` describes this run, so adding them together means nothing. Both sides of the share come from the same side of the exclusion filter — a numerator taken before it over a denominator taken after it would overstate every selection.

The prose report prints the same thing under the count. It is not printed in `--plain`, which stays one test path per line: that output feeds `php artisan test $(…)`, where an extra line arrives as a file argument.

**The share never withdraws a selection.** A selection covering most of the suite is large, not untrustworthy, and `determinable` already answers the second question — any reason it carries tells the caller to run everything. There is no threshold here, because the number that decides whether a selective run is worth it depends on your suite's wall-clock cost, which only you know. Branch on `testsShare` in your own wrapper with your own number.

## Untracked files

An untracked (never `git add`-ed) file under a watched root is one `git diff` cannot see, so it makes the selection **undeterminable** (exit 2) rather than emit a narrowed set that silently omits it. That includes an untracked migration, which `detect-changes` does analyse: this command stays conservative because its contract is a test SELECTION, and the safe direction is the full suite. The stderr note still fires, and `git add`-ing the file includes it. The note is stderr-only, never on stdout, so `--plain`/`--json` stay clean.

## `--plain` degradation

In `--plain` mode an undeterminable run prints nothing, so the command-substitution form (`php artisan test $(php artisan richter:affected-tests --plain)`) degrades to the full suite by construction, as does a determined-but-empty selection, which is why the exit-code branch above is the precise form.

## Frontend tests

Frontend spec files referencing a touched route surface as an advisory `frontendTests` list for the JS runner, never in `--plain` (which feeds the PHP runner), and never a determinability input. See [Frontend changes](13-frontend.md).

A spec the diff itself changed is added to that list too, the way a changed PHP test is added to `tests` — otherwise a spec added by the diff is invisible while a PHP test in the same position is named. This one works on the path alone, so a spec outside every configured frontend path still counts, and a deleted spec is dropped rather than handed to a runner.

**The reference axis is endpoint-only by design.** A spec is suggested when it references a route the diff reaches. A spec for a pure function — a formatter, a threshold calculation — references no endpoint, so it is out of scope for that axis rather than missed. If the diff changes it, the seeding above names it; if the diff does not, nothing will.
