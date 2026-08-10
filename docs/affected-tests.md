# `richter:affected-tests` reference

The [README](../README.md#affected-test-selection) covers the commands, the simple runner form, and the exit-code contract. This page documents how the selection is built.

## How tests are selected

The command inverts the test-reference index into a selection: the test files that reference any entry point the diff reaches, plus the tests that import any changed **or reached** class (a unit test of an intermediate caller never touches an entry point). A test naming a Livewire component by string (`Livewire::test('admin.dashboard')`, the `livewire()` helper) counts as referencing `App\Livewire\Admin\Dashboard` via the default naming convention. A `schedule::` entry resolves through the command it runs. Only conventionally-named `*Test.php` files are selected — helpers and fixtures under `tests/` never end up as runner arguments, and an entry point whose only references live in a support trait blocks determination rather than silently dropping the tests using that trait. Selection is reference-based recall, not proof of coverage — reached entry points nothing references contribute nothing, and the report says how many those are.

## Untracked files

An untracked (never `git add`-ed) file under `app/`, `resources/views/`, or a frontend root is one `git diff` cannot see, so it makes the selection **undeterminable** (exit 2) rather than emit a narrowed set that silently omits it — the stderr note still fires, and `git add`-ing the file includes it. The note is stderr-only, never on stdout, so `--plain`/`--json` stay clean.

## `--plain` degradation

In `--plain` mode an undeterminable run prints nothing, so the command-substitution form (`php artisan test $(php artisan richter:affected-tests --plain)`) degrades to the full suite by construction — as does a determined-but-empty selection, which is why the exit-code branch in the README is the precise form.

## Frontend tests

Frontend spec files referencing a touched route surface as an advisory `frontendTests` list for the JS runner — never in `--plain` (which feeds the PHP runner), and never a determinability input. See [Frontend changes](frontend.md).
