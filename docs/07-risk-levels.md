# Risk levels

Risk is a coarse, advisory signal, deliberately simple so `--fail-on` stays predictable.

| Level | Condition (defaults, see `risk_thresholds`) |
|---|---|
| `high` | ≥ 3 entry points reached, **or** ≥ 20 impacted nodes |
| `medium` | ≥ 1 entry point reached, ≥ 5 impacted nodes, **or** the diff changes an entry-point class (job, listener, command, observer, middleware, or a Livewire/Filament/Nova component) |
| `low` | everything else |

## What does not count

Association edges (model relationships, trait usage, `declares`) are reach and context, not risk. They never count toward the impacted-node total, so touching a hub model or trait cannot saturate a change to `high` on breadth alone.

An uncounted surface is still reported. The two decisions are separate. A surface reached *only* through an association edge is listed under **Entry surfaces reached only by association**, and the classes that run a changed member without calling it (trait users, override implementors) under **Runs this code without calling it**. Over-approximated *calls* (`override`, `config-registry`) stay in the main entry-point list, since the dispatch is real there and only the target is uncertain.

## Calibrating the thresholds

The thresholds are configurable (`risk_thresholds` in `config/richter.php`). The defaults were calibrated on small-to-mid applications. On a large codebase a routine change reaches thousands of nodes, `impacted >= 20` is met by everything, and a level that is always `high` carries no signal.

**Move the `high` bar before the `medium` one.** Look at how the levels are decided: raising the `high` thresholds leaves the `medium` test untouched, so the most it can do is move a change from `high` down to `medium`. Raising `medium` is the only edit that can push something all the way to `low`, the level a reviewer skips.

Whether that costs you a real defect depends on where your bug fixes actually land, and impacted counts measure graph reach rather than how big a change is, so a one-line fix in a widely called method can outrank a broad but shallow one. Do not assume the ordering: if you keep a [benchmark corpus](17-benchmark.md), run it before and after, since that is the only check that tells you whether a calibration still surfaces the defects you tuned it to catch. (A diff that touches an entry-point class stays `medium` regardless of either bar.)

Calibrate against the report's `scoredEntryPoints` / `scoredImpacted` rather than the counts printed beside them. Those two come apart wherever a surface joined the entry-point list after the level was scored, or a low-confidence `high` was re-scored on the precise subset, and the report names them whenever they differ.

## The low-confidence cap

A separate guard covers low confidence. When a changed member cannot be pinned to a graph node and only a coarse class-level seed is available, a resulting `high` is capped to `medium` (`coarseCapApplied`). A low-confidence estimate should not drive the top level on its own.

## Thresholds are absolute

The thresholds are absolute, not relative to your repo. That is what keeps `--fail-on` predictable, and it has a consequence worth knowing before you gate CI on it.

Every release that teaches Richter to follow more edges raises the impacted-node count for the same diff, so a change that sat under `≥ 20` can cross it on an upgrade with nothing in your application having changed. Treat a level shift right after a version bump as a coverage change first and a code change second, and pin the version in CI if you need a `--fail-on` verdict to stay comparable across a release. The counts move upward over time by design: an under-reported blast radius is the failure this package exists to prevent.

## The counts the risk level was decided on

`risk` is not always decided against the counts printed beside it, and two things pull them apart.
The entry-point list gains surfaces **after** the level is scored: a changed class that is itself an
entry surface self-lists, and a changed frontend file contributes the routes it references. And every
low-confidence `high` is re-scored against the precisely-seeded part of the change alone.

**`coarseCapApplied` does not tell you whether that rescore ran.** It reports one thing only: whether
the rescore *downgraded* the level. A low-confidence `high` that the rescore confirms as `high` stays
`high` with the flag `false`, and still reports the rescore's counts, so `coarseCapApplied: false`
never means "the printed counts are the scored ones". The scored counts themselves are the answer to
that; they are what the two keys below exist for.

`scoredEntryPoints` and `scoredImpacted` carry the counts the level was actually measured against.
`scoredEntryPoints` describes a subset of the entry points listed: either the set before self-listed
and frontend surfaces are appended, or, on a low-confidence rescore, the precisely-seeded part of it. It
should therefore not exceed the printed count; a report where it does is a bug worth sending in, not a
documented shape. (One such report was sent in, and was one: narrowing the seeds for the rescore made a
co-changed entry surface stop being a seed, and a walk reports every node except its own seeds, so the
surface read as *reached* by the half of the change that was still being walked. The rescore is now told
which nodes the change owns, and counts none of them as reach.) `scoredImpacted` is measured the same
way but carries no subset relation to the printed `impacted`: the rescore walks a different seed set,
so it is whatever that walk reached.
They are present on every report and equal the printed counts whenever nothing pulled them apart; the
text, markdown and HTML reports name them only when they differ, since repeating identical numbers
teaches a reader to skip the line.

**Calibrate `risk_thresholds` against these rather than the printed counts.** Where the two diverge, the printed ones can be an order of magnitude larger, so
a threshold pair tuned on them sits far above where the level is actually decided, and since the
divergence is widest on the broadest diffs, that inverts the ordering rather than merely loosening it.
