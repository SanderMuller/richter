## Anonymize Fixtures, Docs, and Specs

This is a **public, open-source repository**. Test fixtures, the rule
`CodeSample` heredocs in `src/`, the snippets in `README.md`, and the spec files
in `specs/` are all world-readable on GitHub — and `src/` plus the README also
ship in the Composer dist archive. `tests/` and `specs/` (and the AI/config
dirs) are `export-ignore`d in `.gitattributes`, so they stay out of the
archive, but that only trims the dist; it hides nothing on GitHub. Doc examples
and specs are the easy things to forget precisely because they are not
"fixtures" — they leak just the same.

Every example — fixture, `CodeSample`, doc snippet, or spec — must be
**synthetic**. Never copy proprietary application code — from hihaho or any
consumer/dogfooding codebase — into one. Reconstruct the smallest generic
example that demonstrates the rule, then strip every domain detail not needed
to make the point.

This keeps internal domain models, naming, business terms, and logic out of a
public artifact, and it makes for better examples: the transformation stands
out instead of being buried in incidental domain noise.

## Anonymize these

- **Class and namespace names** — use framework-conventional placeholders
  (`App\Http\Requests\StorePostRequest`, `App\Models\Article`). Don't reach for
  the product's real domain entities.
- **Variable, property, and method names** that carry domain meaning.
- **String literals** — validation field names, route paths, table and column
  names, config keys, labels, messages. Invent neutral values; never paste a
  real schema column or form field key.
- **Business terminology and comments** lifted from real code.
- **Logic and control flow** that mirrors a real implementation.

## Keep these — they are not leaks

- **Framework and vendor public symbols** (`Illuminate\…`, `FluentRule`,
  `HasFluentRules`, `FormRequest`, `Rule`, `Validator`). The rule usually has to
  match these to fire, and they are public API.
- **Generic example nouns** — `User`, `Post`, `Order`, `Article`, `Comment`.
- **The convention the rule enforces** (string-rule → fluent conversions,
  trait insertion, `each()` folding). That is the package's public contract,
  not proprietary.

## Specs leak provenance, not just code

A spec in `specs/` rarely contains a real schema column — its leak vector is
**provenance metadata** describing where the work came from. Scrub all of it:

- **Internal PR / issue / ticket numbers** ("modelled on PR #1234",
  "ABC-123"). Describe the *change* generically ("a manual validation cleanup")
  instead of citing the source. (Don't reference a real PR number here either —
  these examples are deliberately fake.)
- **Employee names, handles, and authorship** of the originating work.
- **Real domain method / class names** copied from the source change, even in
  prose. Use the same neutral placeholders the spec's code examples use.
- **Dogfooding / consumer-app references** ("from the hihaho app", file/line
  counts of a private PR).

State *what* the rule does and *why*, never *which internal change or person*
it came from.

## Rule of thumb

An example should read like a generic framework tutorial snippet, not like a
slice of one company's application. If a reader could tell which product it
came from, anonymize further — prefer a neutral noun (`Article`, `Order`) over
an actual product entity.

## When adding or editing a fixture, doc example, or spec

1. Keep only the framework symbols the rule matches against.
2. Replace real names and strings with neutral equivalents.
3. For a fixture, run the test to confirm the rule still fires
   (`vendor/bin/pest path/to/RuleTest.php`); for a `CodeSample` or README
   snippet, keep it consistent with the rule it documents; for a spec, strip
   provenance (see "Specs leak provenance" above).
4. Before committing, scan the diff for product names, real table/column
   names, domain jargon, and internal PR/ticket/person references — across
   `specs/` and `README.md` too, not only `tests/` and `src/`.
