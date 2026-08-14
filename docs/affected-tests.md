# `richter:affected-tests` reference

The [README](../README.md#affected-test-selection) covers the commands, the simple runner form, and the exit-code contract. This page documents how the selection is built.

## How tests are selected

The command inverts the test-reference index into a selection: the test files that reference any entry point the diff reaches, plus the tests that import any changed **or reached** class (a unit test of an intermediate caller never touches an entry point). A test naming a Livewire component by string (`Livewire::test('admin.dashboard')`, the `livewire()` helper) counts as referencing `App\Livewire\Admin\Dashboard` via the default naming convention. A `schedule::` entry resolves through the command it runs. Only conventionally-named `*Test.php` files are selected; helpers and fixtures under `tests/` never end up as runner arguments, and an entry point whose only references live in a support trait blocks determination rather than silently dropping the tests using that trait. Selection is reference-based recall, not proof of coverage: reached entry points nothing references contribute nothing, and the report says how many those are.

## Unfollowable dispatches

A dispatch whose target cannot be seen statically (a variable or a factory call) hides a
`dispatcher → job::handle` edge, so it makes the selection undeterminable whenever a possible dispatch
target sits in the change's reach. The reason names every such site as
`file:line (Dispatcher::method)`:

```
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
assert that the hidden edge is harmless, and a wrong assertion under-selects tests — the one direction
this command's exit-code contract exists to prevent. The remedy the named site makes possible is to
restructure the dispatch into a form the tracer can follow (a literal `Job::dispatch()`, or a
`new Job(...)` the tracer can see), which fixes the gap rather than silencing it. A project that
genuinely needs a dynamic dispatch keeps running its full suite, correctly.

Two shapes look unfollowable and are not counted, because neither hides anything:

- An inline closure. `dispatch(function () { … })` queues the closure itself, and its body sits in the
  same source the tracers already read, so its work already appears as edges out of the dispatching
  member. There is no target to name. The same holds for a closure inside a `Bus::chain([...])`.
- A string argument. `$this->dispatch('some-event')` is not a job dispatch. `DispatchesJobs::dispatch()`
  takes a job *object*, so a string can never be one. The common case is a Livewire component emitting
  a browser event, which has no queue involvement. A constant the dispatching class itself declares as
  a string (`$this->dispatch(self::SOME_EVENT)`) counts as well — in any declaration form, including a
  typed one and several names in one `const` statement:

  ```php
  public const string
      SUBTITLE_CHANGED = 'subtitle-changed',
      SUBTITLE_DELETED = 'subtitle-deleted';
  ```

  Anything further does not: a constant inherited from a parent, one read off another class, or a
  `static::` reference whose value a subclass can replace. Each of those would need what this pass
  cannot see, and guessing risks dropping a genuine unfollowable dispatch — so they stay listed.

## Untracked files

An untracked (never `git add`-ed) file under `app/`, `resources/views/`, or a frontend root is one `git diff` cannot see, so it makes the selection **undeterminable** (exit 2) rather than emit a narrowed set that silently omits it. The stderr note still fires, and `git add`-ing the file includes it. The note is stderr-only, never on stdout, so `--plain`/`--json` stay clean.

## `--plain` degradation

In `--plain` mode an undeterminable run prints nothing, so the command-substitution form (`php artisan test $(php artisan richter:affected-tests --plain)`) degrades to the full suite by construction, as does a determined-but-empty selection, which is why the exit-code branch in the README is the precise form.

## Frontend tests

Frontend spec files referencing a touched route surface as an advisory `frontendTests` list for the JS runner, never in `--plain` (which feeds the PHP runner), and never a determinability input. See [Frontend changes](frontend.md).
