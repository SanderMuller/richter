# Task slice

```bash
php artisan richter:task-slice --base=HEAD~1
php artisan richter:task-slice --base=HEAD~1 --head=HEAD
```

One document for work in progress. [`detect-changes`](05-detect-changes.md) answers *what does this
diff reach*; [`affected-tests`](12-affected-tests.md) answers *what should I run*. Neither answers
*what does the work in front of me own* — and on a change that touches a shared class, the first
answer is most of the application.

The slice answers all three at once, from a single graph walk.

## What it emits

| Field | Act on it |
|---|---|
| `kept` | The entry surfaces this task owns |
| `unreferencedKept` | The kept surfaces no test PROVES — unreferenced, or referenced only by a test with no behavioural assertion the scan recognises |
| `hazards` | Contract changes on this diff — worst tier first |
| `findings` | Advisory notes about the changed source itself |
| `verificationFalse` | What the risk level graded and did not find verified, including what it could not check |
| `runImpact` / `runImpactOn` | True when the diff changed something but owns no entry surface. Run [`impact`](10-impact.md) on each class instead of concluding it reaches nothing |
| `affectedTests` / `affectedFrontendTests` | The test selection, unchanged from `affected-tests` |
| `affectedTestsDeterminable` / `affectedTestsReasons` | `false` means run the full suite |
| `droppedHubCount` | How many surfaces folded as hub reach. A count to read, not a list to open |
| `entryPointCount` | How many the diff reaches in total |
| `risk` / `riskCause` / `unresolved` / `lowConfidence` | Unchanged from `detect-changes` |

## Two promises

**The test selection is never narrowed.** A hub list cannot remove a test from `affectedTests`. What
it can do is set `affectedTestsDeterminable` to `false`: the selection was computed for the whole
diff, so once the keep set folds surfaces away it is no longer complete for what the slice reports.
That is strictly more conservative, and it is the only direction this command will ever move a test
decision.

**Nothing here grades the change.** `risk`, the gate and the selection never read the hub list. It is
a project's policy about its own layout, not evidence about its code.

## Configuring hubs

Without [`richter.task_slice`](17-configuration.md) hub paths, nothing folds: `kept` is every surface
the diff reaches and `droppedHubCount` is `0`. See
[Which rows are this task's](05-detect-changes.md#which-rows-are-this-tasks) for the keep rule and why
richter ships no default list.

## Exit codes

`0` on success, `1` when the run failed. The test decision is inside the document, not in the exit
code — read `affectedTestsDeterminable`. Stdout is always one JSON document, errors included, so an
agent never has to tell a report from a failure by its shape.
