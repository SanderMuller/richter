# Scoring accuracy against replayable history

```bash
php artisan richter:benchmark
php artisan richter:benchmark --case=TICKET-123
php artisan richter:benchmark:add abc1234
php artisan richter:benchmark:add abc1234 --control
```

Replays historical fix commits (configured in `richter.benchmark_cases`) through the report: bug fixtures must resolve and reach an entry point; benign controls cap the risk a harmless change may report. Run it after changing the graph or tracers. A control flipping green→red is a regression in trustworthiness.

`richter:benchmark:add` scaffolds a case from a historical fix commit: it dry-runs the commit through the same replay, reports what it would score today, and prints a paste-ready `benchmark_cases` entry. It never edits the config file. Two flags fill in fields you would otherwise edit by hand: `--key=<key>` sets the case key instead of deriving it from the commit, and `--expect-finding=<substring>` records a substring the replay's findings must contain.

`max_hazard_tier` (0-3, default 3) caps the worst [hazard](08-risk-levels.md#hazards) a case may produce; `0` allows none. Set it wherever you know what a change should and should not carry. It catches what `max_risk` cannot, because the tier x reach matrix maps a tier-2 at `gated`, a tier-1 at `public-write` and a hazard-free change with unverified reach all onto `medium`. A case sitting honestly at `medium` will stay green while a false hazard appears beneath it, and only a tier-3 false positive is caught by a level cap at all, since tier 3 is `high` everywhere.

`expect_finding` matches a finding OR a hazard's evidence or member. A fixture pins that the report says the thing; which section carries it is richter's business, and it has moved before: the payload-parity checks were findings until they became tier-2 hazards.

**Set `max_risk` on signal fixtures too, not only on controls.** It is checked on every case, but it
defaults to `high`, so a signal fixture that never sets it can never trip the cap, and a level that
is wrong for the right reach then passes green. A signal fixture you expect to read `medium` should
say `'max_risk' => 'medium'`, which is what turns the corpus into a check on the LEVEL rather than
only on reach.

A level-model change re-baselines controls by design. When the risk model itself changes, a control's `max_risk` is measuring a different thing than the day it was captured. Re-grade the cap rather than reading the red as a regression.

Under the 0.40 model, the controls that need raising from `low` to `medium` are the ones richter **cannot place**: "could not place what this reaches" is `medium`, not evidence of safety. A control whose change is purely additive is unaffected: it is ladder step 0, "nothing to assess", and still reports `low`.

With `--control` it refuses to scaffold anything when the replay already reports HIGH. A control caps the risk a harmless change may report, and HIGH is the top of the scale, so the cap would assert nothing and the case would pass forever. Either the change is not harmless (capture it as a signal by dropping `--control`) or the corpus needs a lower-reach commit for that control. The same trap is why you should never resolve a red control by re-capturing it.

Each case in `config/richter.php`:

```php
'benchmark_cases' => [
    [
        'key' => 'TICKET-123',                 // label, and the --case selector
        'fix_commit' => 'abc1234',             // commit whose diff is replayed through the report
        'bug_class' => 'background-job change (data not copied on duplication)',
        'expect_signal' => true,               // bug fixture: must resolve and reach an entry point
        'max_risk' => 'high',                  // caps the risk a control (expect_signal: false) may report
        'expect_finding' => 'eager-load',      // optional: an advisory finding must contain this substring
    ],
],
```
