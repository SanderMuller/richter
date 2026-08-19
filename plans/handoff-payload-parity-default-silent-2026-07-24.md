# Consumer handoff: payload-parity is silent at its own default (2026-07-24)

> **What this is**: a consumer follow-up to
> [handoff-payload-parity-2026-07-23.md](handoff-payload-parity-2026-07-23.md),
> which became **plan 048** and shipped in **v0.14.0** as `PayloadParityChecker`.
> This is a **dogfood result of the shipped feature on the reporting consumer's
> real code** — findings and a proposed metric change, not a plan. Pinned to the
> consumer's checkout at richter `v0.14.0`.
>
> Headline: the checker is **mechanically correct** but **silent at the shipped
> default** (`mirror_threshold => 1.0`) on every real curated Resource, because
> the mirror ratio penalizes derived keys instead of ignoring them. On the
> consumer app it fires on nothing at 1.0, and fires **correctly** at 0.2 —
> including on the exact Resource behind the two real bugs 048 was built for.

## What was verified working

The checker resolves class-constant field names (`self::FIELD` in `$fillable` /
`casts()`), detects the mirroring Resource, and names the omitted field. Proven
by a synthetic probe on the consumer's `App\Models\Question`: a new
`FAKE_PARITY_PROBE = 'fake_parity_probe'` added to the field-constant block,
`$fillable`, and `casts()`, and deliberately **not** added to any Resource. At
`mirror_threshold => 0.2`:

```
! QuestionResource.php mirrors App\Models\Question but does not expose fake_parity_probe added to App\Models\Question
! QuestionPlayerResource.php mirrors App\Models\Question but does not expose fake_parity_probe added to App\Models\Question
```

`QuestionPlayerResource` is the **exact Resource** behind the consumer's
`HPB-5250` and `HPB-5151` — the two "resource field omitted" bugs that motivated
the whole feature. So the mechanism is right and the target is right.

## The problem: 1.0 is unreachable for a real Resource

At the shipped `mirror_threshold => 1.0`, the same probe produces **zero**
findings. The default is dead on arrival for any mature app.

Cause is the metric definition (config comment, `config/richter.php:104-106`):
*"Fraction of a candidate resource's pre-existing fields that must be mirrored
for it to count as a mirror."* The **denominator is every key the Resource
emits**, and derived keys count **against** the ratio. A Resource reaches 1.0
only if it is a **pure passthrough with zero computed/nested/renamed keys** —
which essentially never happens in practice.

Concrete shape from the consumer (`QuestionPlayerResource::toArray()`): ~24
plain `'field' => $this->resource->field` passthrough keys, **plus** a few
derived keys that sink it below 1.0:

```php
'adaptive_learning_subject' => $this->whenLoaded(Question::ADAPTIVE_LEARNING_SUBJECT),
'submit_label_parent'       => $this->resource->video->questionSubmitButtonLabel(),
'subtitle_text_parent'      => $this->resource->video->questionSubtitleText(),
$this->mergeWhen($this->editMode($request), [ /* ~30 more model fields */ ]),
```

This is not an unusual Resource — it is the archetypal one. A model-view
Resource almost always has a handful of `whenLoaded` / parent-derived / nested
keys. Under the current metric each of those is evidence the Resource is *not* a
mirror, when in reality they are exactly the keys a payload-parity check should
**ignore**, not count against.

Net: the check as shipped defends the no-guess principle by never firing. The
"exact mirror" default is exact about the wrong denominator.

## Proposed fix (maintainer's call)

**Exclude non-model keys from the denominator rather than counting them against
the ratio.** Keep the no-guess spirit — resolve only literal string keys whose
value is a plain `$this->resource->field` / `$this->field` model-attribute fetch.
Everything else (`whenLoaded`, `mergeWhen`, `when`, method-call values, nested
Resources, `array_merge`, spreads, renamed keys) is **neither numerator nor
denominator** — it is simply invisible to the check.

Then the metric becomes: *of the keys that resolve to a model attribute, what
fraction of the model's `$fillable`/`casts` set do they cover* — and `1.0`
regains a sane meaning ("this Resource exposes the model's fields as a group, so
a newly added sibling field that is absent is a real omission"). The consumer's
`QuestionPlayerResource` would then qualify at the default and the two real bugs
would surface without lowering any threshold.

This is the answer to **open-question #1 of the 2026-07-23 handoff** ("is a fuzzy
ratio acceptable?"). It turns out the honest fix is **not** a fuzzy ratio at all
— it is a stricter, no-guess denominator. `mirror_threshold` can stay, defaulting
1.0, but measured over model-derived keys only. A lower default becomes
unnecessary rather than a heuristic compromise.

`mergeWhen`-wrapped keys are worth a decision of their own: the consumer's
edit-mode fields (including the two real bug fields) live **inside** a
`mergeWhen`. If those stay invisible, the check still misses `HPB-5250`/`5151`'s
own fields even after the denominator fix. Recognizing a top-level
`mergeWhen([... literal model-field keys ...])` as in-scope keys (still no-guess:
literal keys, model-attribute values) would be needed to actually cover the
motivating bugs. Flag this explicitly — it is the difference between the feature
working on the archetype and only on toy Resources.

## Benchmark note (reconfirmed, now proven)

The 2026-07-23 handoff warned that `expect_signal` fixtures score blast radius,
not detection. Now proven for `expect_finding` too: the consumer's `HPB-5250`
(`252ab63057`) and `HPB-5151` (`3eba65370d`) fix commits touch **only** Resource
files — neither edits `$fillable`/`casts()`. `PayloadParityChecker` triggers on
*"diff adds a field to fillable/casts"*, so it **cannot** fire on a fix diff, at
any threshold. Adding `expect_finding` to those two cases would make the
benchmark **fail**, not pass. Scoring the checker requires replaying the
**bug-introducing** commits (e.g. `HPB-5250`'s field-adding commit `3386e35bb4`),
not the fixes. Any 048 fixture built on a fix commit gives false reassurance.

## What this handoff does not claim

Line references are from the consumer's `v0.14.0` checkout and a live probe, not
a read of the checker source — confirm `PayloadParityChecker`'s actual key
resolution (does it already skip `mergeWhen`, or count it?) before acting. The
denominator diagnosis is inferred from the 1.0-silent / 0.2-fires split plus the
Resource shape; verify against the implementation. No false-positive rate was
measured at lower thresholds — the recommendation deliberately routes around
thresholds rather than picking a lower number.
