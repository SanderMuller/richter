# Request-field parity

## Overview

The parity family covers the response side twice — a model field never mirrored into its resource,
and a resource key removed while a consumer still reads it — and the request side not at all. A
field dropped from a form request's `rules()` stops being validated and stops appearing in
`validated()`, so a frontend that still sends it now sends it into nothing. No validation error, no
value, no report anywhere.

This adds the third lane: a removed `rules()` field, flagged when a frontend file that consumes one
of the routes the request validates still sends it.

## Assumptions

- **Nothing here comes from Laravel Brain, despite appearances.** Brain has a
  `ValidationRulesExtractor` that reads `rules()`, and it was the original reason to build this
  lane. It cannot serve it: both of its entry points (`hasNonAbstractRulesMethod`,
  `extractFromFile`) are `is_file()`-gated, and this lane needs the BASE side of a diff, which
  exists only as a git blob. `PhpFileParser::parseCode()` exists but the extractor does not use it,
  and its own walk is private. A source-string entry point upstream would make the parser here
  replaceable later; until then, the parse is richter's own.
- **The trigger is a removal, never an addition.** A field ADDED to `rules()` is usually the
  backend half of a change whose frontend half is in the same branch, and flagging it would fire on
  every ordinary feature. Only the removal has the silent-failure shape.
- **The gate is the existing `payload_parity` one.** This is the same family, the CLI flag already
  reads `--no-payload-parity`, and a second switch would double the surface for one category of
  advisory. The ignore list takes `RequestFqcn::field` beside the resource and model forms.
- **Route reach comes from Brain.** Brain's `wireActionFormRequests()` links an action to
  `Fqcn::validated` when the action or its constructor type-hints a class with a concrete `rules()`.
  That pass is route-anchored, which for once is exactly right: the lane only means anything for a
  request behind an endpoint a frontend calls.

## 1. Current state

`ParityFindings` dispatches two lanes off a changed file — `addedModelFields` to the model lane,
`removedResourceKeys` to the consumer lane. A changed form request triggers neither. Nothing in
`src/` reads `rules()`.

## 2. Proposed change

**`ArrayReturnKeys`** — the enumeration `ResourceKeyParser` already did, lifted out and given the
method name as a parameter. Both contract parsers ask one question of a different method: the keys
of the array a named method returns, or null when they cannot be vouched for. `ResourceKeyParser`
keeps its two-mode public API and delegates; nothing about its behaviour changes.

**`RequestFieldParser`** — `diffFor()` over `rules()`, strict-only. There is no historical caller
wanting the lenient reading, and the lane exists to diff two sides, which is exactly where an
unkeyed item or a base-side class constant would fabricate a removal.

**`FrontendConsumerLane`** — what the two consumer-facing lanes share: the routes upstream of a
class, the files consuming them, their scannable content, the ignore forms, the rename hint. Both
checkers become the one thing that differs plus a message.

**`RequestFieldParityChecker`** — send-shaped matching, where the response lane is access-shaped:

| Shape | Example |
|---|---|
| Object-literal key | `{ subtitle: v }`, `{ 'subtitle': v }`, `{ subtitle }` |
| FormData / URLSearchParams | `body.append('subtitle', v)`, `.set('subtitle', v)` |
| Bracket write | `payload['subtitle'] = v` |
| Dotted assignment | `form.subtitle = v` |

The object-literal pattern is the one the response lane names as its own false-positive class — a
destructure of a response and an object literal being built are the same tokens. Here it is the
primary signal. The two lanes therefore match separately rather than sharing a predicate, and the
residual cost is the mirror image: a file that both posts to and reads from an endpoint can match
on a field it only reads.

## Edge Cases

| Case | Behaviour |
|---|---|
| `rules()` builds its array up (`$rules = […]; if (…) …; return $rules;`) | Nothing — not enumerable. A reach limit, never a wrong finding. |
| A spread or a class-constant key in `rules()` | Nothing — same abort as the resource parser. |
| Two `rules()` methods in one file | Nothing — no single method to read. |
| A dotted rule key (`items.*.name`, `address.city`) | Parsed verbatim, matches no consumer. Its segments appear separately in a payload and matching the last one would fire on every unrelated `name`. |
| A field ADDED to `rules()` | No finding; feeds the rename hint only. |
| A brand-new form request | Nothing — no consumer sends a field that never shipped. |
| A `rules()` outside `app/Http/Requests/` | Nothing — the path is the convention, and a `rules()` elsewhere is not addressed by a payload. |
| The consumer only mentions the field name in a label or comparison | Nothing — no send shape. |
| Blade consumer | Scanned on its `<script>` slices only; server-side PHP building the same key is not a send. |
| The form request reaches no route | Nothing — no endpoint, no consumer. |
| `--no-payload-parity` / `payload_parity.enabled = false` | All three lanes off together. |

## Implementation

### Phase 1: Parse the contract (Priority: HIGH)

**ID:** parse · **Depends:** none

- [x] `ArrayReturnKeys` extracted from `ResourceKeyParser`, which delegates to it unchanged.
- [x] `RequestFieldParser` with `diffFor()` / `fieldsOf()` / `isRequestPath()`.
- [x] Tests — the enumerable case, and every abort: built-up array, spread, constant key, new file,
      wrong path, two methods, dotted key passed through verbatim.

### Phase 2: Match the consumers (Priority: HIGH)

**ID:** match · **Depends:** parse

- [x] `FrontendConsumerLane` extracted; `FrontendConsumerParityChecker` rebuilt on it.
- [x] `RequestFieldParityChecker` with the four send shapes.
- [x] Tests — one per send shape, the silent cases, both ignore forms, Blade slices, no-route.

### Phase 3: Wire it in (Priority: HIGH)

**ID:** wiring · **Depends:** match

- [x] `ChangedFileSymbols` + `ChangedSymbols` carry the field diff.
- [x] `ParityFindings` gains the third lane; `ImpactAnalyzer` destructures three.
- [x] Config comment and README.

## Coverage boundary

The lane is pinned at unit level end to end — parse, match, the `ChangedSymbols` carry, and the
`ParityFindings` dispatch each fail under a mutation of the code they cover. There is no
whole-command test like the resource lane's, because that one rides fixture wiring this lane does
not have: the fixture project has no form request with a concrete `rules()` behind a POST route,
and adding one means editing `routes/web.php`, which several route- and entry-point-counting tests
assert over. Worth adding when the fixture next needs a POST endpoint for another reason.
