# Getting started

The common case: you are on a branch and want to know what the change reaches before you open the pull request.

```bash
php artisan richter:detect-changes
```

That diffs the working tree against the configured base and prints a report: the entry points the change can reach, which of them no test references, and an advisory risk level.

```text
Changed files:
  app/Models/Post.php (4 graph nodes)

Entry points reached: 2
  - command::categories:sync  (app/Console/Commands/SyncCategories.php)  [test-referenced]
  - route::PATCH::/api/posts/{post}  (routes/api.php:41)  [⚠ no test references this]  [authed]

Risk:   MEDIUM (advisory) — no hazard; 1 of 2 reached surfaces have no test referencing them
Impact: 2 entry point(s) · 7 impacted node(s)
```

The line worth acting on is `no test reference`: a route your change reaches that no test exercises. Everything else is context for the review.

Two flags carry the rest of the daily use:

```bash
php artisan richter:detect-changes --explain    # how each entry point reaches the change
php artisan richter:detect-changes --markdown   # paste-ready for the pull request
```

**The report is advisory.** The command exits 0 whatever it finds, and an empty result means the graph placed no reach, not that there is none. Gating is [opt-in](09-ci-gating.md).

## Next

- [Set up your project](04-project-setup.md): the config that makes the analysis accurate for your app. Do this once, early — the defaults do not know your subsystems.
- [Detecting change impact](05-detect-changes.md): which diff is analysed, and reading the report in full.
- [Risk levels](08-risk-levels.md): how a hazard and its reach become a level.
