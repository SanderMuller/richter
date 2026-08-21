# Gating in CI

`richter:detect-changes` is advisory by default (exit 0), and that is how it is meant to be run. The report tells a reviewer where to look; it does not know whether your change is correct, and a risk level is a coarse signal rather than a verdict. Posting the report on the pull request gets you most of the value with none of the false stops.

Gate only when a specific failure keeps reaching production and you want the build to stop for it. Two opt-in flags do that:

- `--fail-on=<low|medium|high>` exits non-zero when the reported risk is at least that level (see [Risk levels](07-risk-levels.md)).
- `--fail-on-hazard=<1|2|3>` exits non-zero when any [hazard](07-risk-levels.md#hazards) reaches that tier, whatever the level. Blocking a removed guard and blocking a missing test are different policies, so they get a flag each.
- `--fail-on-unresolved` exits non-zero when any changed file is **UNRESOLVED** (changed code the graph cannot place). It works independently of both.

Either flag also fails an un-assessable diff (a broken or invalid base ref) rather than letting it pass as "no impact". Add `--json` and stdout carries a `gate` object alongside the report.

Before turning any of them on, know what you are signing up for.

`--fail-on-hazard=3` is the narrowest useful gate and the one to start with: tier 3 is a guard removed or a disclosure widened, and on a real corpus it fires rarely. `--fail-on` is broader. It blocks on the level, which on an application richter cannot place well reads `medium` for most changes, so `--fail-on=medium` there is close to blocking everything. Neither blocks on correctness: a one-line logic error in a leaf class trips nothing.

A level can also move without your code changing. Reach class and verification state both improve as richter learns to follow more edges, and both push levels UP ([Risk levels](07-risk-levels.md#what-drifts-and-in-which-direction)). Pin the version if a verdict has to stay comparable across a release. `--fail-on-unresolved` trips on any UNRESOLVED changed file, so what it fires on is your app's own coverage: a subsystem the graph cannot place fails every build that touches it until `entry_point_roots` or `root_namespace` covers it ([Troubleshooting](18-troubleshooting.md#a-changed-file-reads-unresolved)).

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

The workflow analyzes the pull request's code, and analysis autoloads classes from that checkout (see [How the analysis runs](01-why-richter.md#how-the-analysis-runs)). For a public repository, keep the trigger on `pull_request` rather than `pull_request_target` with a privileged token, so fork-submitted code runs without access to your secrets.

## Posting the report instead of gating

To comment rather than block, run `--markdown` and post the result as a sticky pull-request comment. The [project setup page](03-project-setup.md#add-the-ci-advisory-comment) carries a prompt that scaffolds that workflow for you.
