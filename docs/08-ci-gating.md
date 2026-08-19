# Gating in CI

`richter:detect-changes` is advisory by default (exit 0). Two opt-in flags turn it into a gate:

- `--fail-on=<low|medium|high>` exits non-zero when the reported risk is at least that level (see [Risk levels](07-risk-levels.md)).
- `--fail-on-unresolved` exits non-zero when any changed file is **UNRESOLVED** (changed code the graph cannot place). It works independently of the risk threshold.

Either flag also fails an un-assessable diff (a broken or invalid base ref) rather than letting it pass as "no impact". Add `--json` and stdout carries a `gate` object alongside the report.

## A pull-request check

This surfaces the blast radius and fails on high-risk or unplaceable changes:

```yaml
name: Impact
on: pull_request

jobs:
  richter:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
        with:
          fetch-depth: 0   # detect-changes diffs against the base ref, so it must be in history
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.4'
      - run: composer install --no-interaction --prefer-dist
      - run: cp .env.example .env && php artisan key:generate   # detect-changes boots the app to build the graph
      - run: php artisan richter:detect-changes --base=${{ github.event.pull_request.base.sha }} --fail-on=high --fail-on-unresolved
```

No GitHub Action ships with the package. `detect-changes` is a plain Artisan command, so wire it into whatever pipeline you already run.

> **Note:** `detect-changes` runs `php artisan`, so it boots your Laravel application to build the graph. The job needs whatever booting the app normally requires: typically an `.env` (`cp .env.example .env`) and an `APP_KEY` (`php artisan key:generate`), as above. Without them the command fails to boot before it can analyse anything.

## Fork safety

The workflow analyzes the pull request's code, and analysis autoloads classes from that checkout (see [How the analysis runs](01-why-richter.md#how-the-analysis-runs)). For a public repository, keep the trigger on `pull_request` — never `pull_request_target` with a privileged token — so fork-submitted code runs without access to your secrets.

## Posting the report instead of gating

To comment rather than block, run `--markdown` and post the result as a sticky pull-request comment. The [project setup page](03-project-setup.md#add-the-ci-advisory-comment) carries a prompt that scaffolds that workflow for you.
