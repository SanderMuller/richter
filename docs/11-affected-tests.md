# Affected-test selection

`richter:affected-tests` turns the diff's reach into a test selection.

```bash
php artisan richter:affected-tests                        # human-readable selection
php artisan richter:affected-tests --base=origin/develop
php artisan richter:affected-tests --head=HEAD            # select against the committed tree
php artisan richter:affected-tests --json                 # {base, determinable, reasons, tests, frontendTests, unreferencedEntryPoints, unresolvedDispatchSites}
php artisan test $(php artisan richter:affected-tests --plain)   # simple form: coarse but safe
```

It selects the test files that reference any entry point the diff reaches, plus the tests that import any changed or reached class. It diffs the same way [`detect-changes`](04-detect-changes.md#which-diff-is-analysed) does, so staged and unstaged edits are included. Selection is reference-based recall, not proof of coverage.

## The exit-code contract

It fails safe, and the exit code is the contract:

| Exit | Meaning |
|---|---|
| `0` | Selection determined (possibly empty). |
| `2` | **Not determinable: run the full suite.** Any UNRESOLVED file, low-confidence seed, an unparseable app file, an unfollowable dispatch *that a possible dispatch target in the change's reach could hide*, an uncheckable entry point, or an untracked relevant file `git diff` cannot see trips this. The reasons are printed (text) or carried in `reasons` (JSON), and an unfollowable dispatch names each site as `file:line (Dispatcher::method)` so it can be restructured rather than only lived with, though [some shapes cannot be](#unfollowable-dispatches). |
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

The command inverts the test-reference index into a selection: the test files that reference any entry point the diff reaches, plus the tests that import any changed **or reached** class (a unit test of an intermediate caller never touches an entry point). A test naming a Livewire component by string (`Livewire::test('admin.dashboard')`, the `livewire()` helper) counts as referencing `App\Livewire\Admin\Dashboard` via the default naming convention. A `schedule::` entry resolves through the command it runs. Only conventionally-named `*Test.php` files are selected; helpers and fixtures under `tests/` never end up as runner arguments, and an entry point whose only references live in a support trait blocks determination rather than silently dropping the tests using that trait. Selection is reference-based recall, not proof of coverage: reached entry points nothing references contribute nothing, and the report says how many those are.

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

Three shapes look unfollowable and are not counted, because none of them hides anything:

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

## Untracked files

An untracked (never `git add`-ed) file under `app/`, `resources/views/`, or a frontend root is one `git diff` cannot see, so it makes the selection **undeterminable** (exit 2) rather than emit a narrowed set that silently omits it. The stderr note still fires, and `git add`-ing the file includes it. The note is stderr-only, never on stdout, so `--plain`/`--json` stay clean.

## `--plain` degradation

In `--plain` mode an undeterminable run prints nothing, so the command-substitution form (`php artisan test $(php artisan richter:affected-tests --plain)`) degrades to the full suite by construction, as does a determined-but-empty selection, which is why the exit-code branch above is the precise form.

## Frontend tests

Frontend spec files referencing a touched route surface as an advisory `frontendTests` list for the JS runner, never in `--plain` (which feeds the PHP runner), and never a determinability input. See [Frontend changes](12-frontend.md).
