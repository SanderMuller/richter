# Risk levels

The risk level answers one question: what should a reviewer do about this change?

The hazard the change carries decides it. A hazard is an exact, tiered property of the diff. Where a
change carries none, the level is decided by whether anything would catch a regression in what the
change reaches. Breadth still appears in the report, as `Impact`, where it describes the change
instead of grading it.

## The ladder

One decision ladder, evaluated top to bottom. There are no weights to set and nothing to tune.

```
0. Nothing to assess              -> LOW     "no analysable change"
1. A hazard fired                 -> the tier x reach matrix, below
2. Reach is unplaced              -> MEDIUM  "could not place what this reaches"
3. A graded surface is            -> MEDIUM  those surfaces are named
   unreferenced, or could not
   be checked
4. Otherwise                      -> LOW
```

Every level prints its cause. This report renders no bare `MEDIUM` in any format, and `riskCause`
carries the same sentence in `--json` and over MCP.

## Step 0 is not step 2

These two look alike and mean opposite things.

- Step 0 means nothing was analysed: an empty diff, a cosmetic-only diff, an additive-only diff, or a
  brand-new class nothing calls. There is no question to answer.
- Step 2 means something that already existed was analysed and could not be placed. Richter found a
  real change and could not name a single surface it reaches.

Only the second is a warning. Collapsing them would report `medium` for a whitespace commit and trip
`--fail-on=medium` on it.

A real change to a class the graph never charted is step 2, not step 0. Failing to place a change is a
placement failure, and reporting it as "nothing to assess" would be the falsely reassuring answer this
package exists to avoid.

### One addition is not additive

An added member is additive because nothing called it at base. A model's **first** declaration of a
property Eloquent itself reads breaks that premise, so richter treats it as a modification instead.
`protected $table = 'legacy_articles';` redirects every query untouched code already makes; `$perPage`
repaginates existing callers; `$timestamps = false` stops writes they depend on. The same holds for
`$fillable`, `$casts`, `$guarded`, `$hidden`, `$appends`, `$with` and the rest of the properties the
base `Model` declares.

A property has no member node, so the change seeds the class coarsely and
[`richter:affected-tests`](12-affected-tests.md) names it in the low-confidence reason as
`(App\Models\Article::table, property)`. Two cases stay additive: adding a column to a `$fillable` or
`$casts` that already exists, and any property on a class that is not a model.

## Hazards

A hazard is a property of the diff saying the change may break something. Every predicate is exact: a
lane that cannot read both sides of a comparison in full reports nothing rather than guessing, because
a false "authorization removed" is worse than the breadth number it replaced.

| Tier | Hazard | Lane | CWE |
|---|---|---|---|
| 3 | an authorization guard removed | `auth` | CWE-862 |
| 3 | an authentication middleware removed | `auth` | CWE-306 |
| 3 | a guard removed from a route declaration | `auth` | per guard |
| 3 | a guard removed from a middleware group | `auth` | per guard |
| 2 | a rate limit raised | `auth` | CWE-770 |
| 3 | `$hidden` narrowed | `model` | CWE-200 |
| 2 | mass-assignment surface widened (`$fillable`/`$guarded`) | `model` | CWE-915 |
| 2 | a `$casts` value changed on a surviving key | `model` |, |
| 2 | a validation constraint dropped | `boundary` | CWE-20 |
| 2 | a queued job's payload (its constructor signature) changed | `boundary` |, |
| 2 | a public or protected member removed | `contract` |, |
| 2 | a class deleted whole | `contract` |, |
| 2 | a resource key removed while a consumer reads it | `parity` |, |
| 2 | a model field never mirrored to its resource | `parity` |, |
| 2 | a form-request field removed | `parity` |, |
| 2 | a column dropped by a migration | `migration` |, |
| 2 | a table dropped by a migration | `migration` |, |
| 2 | a column renamed by a migration | `migration` |, |
| 1 | a surviving member's signature changed | `contract` |, |

The tiers are fixed and not configurable. A tier is a fact about the change, and a project that could
re-tier one would be grading its own risk before reading it. `cwe` is null wherever no clean mapping
exists. A stretched CWE teaches the reader that the mapping is decorative.

A removed guard middleware carries the CWE for the guard it names, not one CWE for all of them: `auth`
and `password.confirm` are CWE-306, `signed` is CWE-345, `throttle` is CWE-770, and every
authorization guard is CWE-862. Reporting a lost rate limit as missing authentication would be the
stretched mapping the paragraph above warns about.

Route files are read route by route. `routes/*.php` declares no class, so the lanes above cannot reach
it. Its own reader compares each route's effective guard set on both sides: the middleware written on
the route, plus every enclosing `Route::middleware(...)->group()` and
`Route::group(['middleware' => ...], ...)` wrapper, minus its own `->withoutMiddleware()`. Wrapping
existing routes in a guarded group is the commonest edit these files see, and a file-wide comparison
would report every one of those routes as newly unguarded while the group above them says otherwise.
A route the head no longer declares raises nothing. Deleting a route leaves nobody able to reach it
unguarded.

Routes are lined up by verb, URI and action together. Two endpoints mounted at `'/'` under their own
`prefix()` groups are one key without the action, and their guards would then be read as one set: a
guard deleted from one of them would read as still present because the other still has it. Repointing
a route at another controller and renaming its URI each change one part of that key, so an unmatched
route is offered its verb and URI, then its action, and pairs on either where it names exactly one
unmatched route on each side.

The hazard resolves through the route's action where the file names one, so the entry points reaching
it answer for it. A closure route has no action, so the route's own node id stands in. It matches an
entry point whenever the declared URI is the registered one, and grades `no-known-path` when a group
prefix made it something else.

Migrations are read for the destructive operations their `up()` performs: a dropped column, a dropped
table, a renamed column. Laravel's shorthand helpers are read as the column drops they are, so
`dropTimestamps()`, `dropSoftDeletes()`, `dropRememberToken()` and `dropMorphs()` each report the
columns the framework removes for them. Each is tier 2 whether or not anything still names the column. Losing the data
is the break, and richter cannot see the rows.

Only `up()` is read. A conventional `down()` reverses `up()`, so it holds a `dropColumn` for every
column `up()` adds, and reading the whole file would report a destructive operation on every migration
ever written. The comparison is the head's operations minus the base's, so a new migration reports
everything it does and a migration edited for an unrelated reason does not re-report what it already
held.

Deleting a migration raises nothing. Head minus base leaves an empty head with nothing to report, and
rolling an unrun migration back out of a branch is routine. Whether it already ran against a real
database is not something richter can see.

The hazard is named for the model that owns the table, so the entry points reaching that model answer
for it. The table comes from the model the way Eloquent derives it: an explicit `$table` wins, and
otherwise the snake-cased plural of the class name. A `$table` on a project base model is inherited, so the nearest declaration in the parent chain
answers. A property declared with no value sets nothing (Eloquent reads `$this->table ?? convention`) so a
base declaring `protected $table;` as a placeholder leaves its subclasses on the convention, and a
subclass declaring it that way falls to its own convention rather than inheriting the parent's table. An abstract base model claims no table of its own. Two models claiming one table resolve to
neither, and a class this can prove is not an Eloquent model owns no table at all, so a
helper parked under `app/Models` cannot claim one. A base class the scan cannot see is accepted rather
than refused, since a base model outside `app/Models` is an ordinary layout.
A table no model claims keeps its own name and grades `no-known-path`, which is honest, richter
cannot see what reaches it.

A dropped or renamed column is then checked against what still names it, and the hazard says where.
Two surfaces are read: the owning model's own `$fillable`/`$casts`, and the `toArray()` keys of the
resources that belong to that model and mirror it. The mirror gate matters: one controller may touch
several models and return several resources, so a resource carrying a key of that name is not evidence
on its own. A resource match means the resource still carries a key of that
name, not that it reads the column, and the evidence says so. This is evidence only. It never moves
the tier or the reach, and a surface richter cannot read is skipped rather than guessed at, because
the hazard has already fired and a missed reference only under-informs.

`hazards.ignore` silences a migration hazard by table and column (`posts.subtitle`), and the table on
its own (`posts`) silences every hazard on it, column drops included. That is how a framework table, a queue table or a pivot is quietened, rather than
richter curating a list of table names to skip.

A guard leaves a route in two directions. It can leave the route, and it can leave the group the route
runs in. Removing `auth` from the `web` group unguards every route in that group at once. So
`bootstrap/app.php` and a Laravel 10 `app/Http/Kernel.php` are compared per group, and a guard gone
from one is a tier-3 hazard named for the group (`middleware group 'web'`), silenced with
`hazards.ignore` under `middleware-group:web`.

That comparison is shape-aware where the arrivals are not. Only two shapes are read: the Kernel's
`$middlewareGroups` array, and the `withMiddleware` calls (`web(append: [...])`, `api(remove: [...])`,
`appendToGroup('name', [...])`). An unrecognised shape produces a finding instead of a hazard. A group
lists its members as `::class` far more often than as an alias, so the framework guard classes map
onto the aliases they stand for, and swapping one for the other reads as the refactor it is. An
application's own guard class resolves through the alias map the project registers: `$middlewareAliases`
on a legacy Kernel, or `$middleware->alias([...])` in `bootstrap/app.php`. A project that writes
`'auth' => Authenticate::class` has said which class is its `auth` guard, so a route naming that class
and a route naming the string are the same guard. That is declared intent rather than an inference from
an `extends` clause. A class two guard aliases both name is skipped rather than resolved one way, and a
class the project registers under no guard alias still draws nothing.

The guard vocabulary is the framework's own (`auth`, `verified`, `signed`, `password.confirm`, `can`
and `throttle`) plus the guards the common packages ship: `role`, `permission` and
`role_or_permission` from spatie/laravel-permission, and `client`, `scope`, `scopes`, `ability` and
`abilities` from Passport and Sanctum. A middleware outside that list draws nothing, whoever wrote it.

**A parameter is not always part of the guard.** `can:update,post` and `role:admin` are compared with
their parameters, because the parameter names what is being authorized: `can:view,post` is a different
check and `role:editor` admits different people. `auth` is compared by its alias alone, because there
the parameter picks a driver rather than deciding who gets through, so switching `auth` to
`auth:sanctum` reports nothing. Where the parameter is a set rather than a position it is sorted
first, so reordering `abilities:read,write` reports nothing either. The packages disagree on the
separator, so each is read its own way: spatie's roles are pipe-separated and take an optional guard
name after a comma, and only the pipes are sorted. `can` keeps its order entirely, because its
parameters name an ability and then a model.

**A rate limit gets its own reading**, because its two directions are not the same thing. Dropping
`throttle:` altogether is a removed guard at tier 3. Raising the limit is a weakened constraint at tier
2, reported with both values (`the rate limit on the GET /search route rose from throttle:60,1 to
throttle:120,1`). Tightening it reports nothing at all. So does a limit the reader cannot compare: a
named limiter such as `throttle:api` keeps its rate in a `RateLimiter::for()` closure that nothing here
follows, and guessing in either direction would be worse than silence. Where a surface carries several
throttles, the strictest one is the limit, so a raised limit beside a tighter one reports nothing, and
one unreadable rate makes the whole set unreadable. Two limits counting over different windows are not
compared at all: `throttle:100,60` allows a burst of a hundred in one minute where `throttle:2,1`
allows two, so the averages rank them the wrong way round.

A guard can be written three ways and all three read the same: the alias (`'throttle:30,1'`), the class
(`ThrottleRequests::class`), and the static call that builds one (`ThrottleRequests::with(30, 1)`). The
call's arguments become the parameter only where each is a plain scalar written in place; a named
argument, or a limiter named by something the reader cannot evaluate
(`ThrottleRequests::using(Limiter::Guest)`), answers the bare guard instead. That is present-or-absent:
a removal is still tier 3, and swapping one limiter for another says nothing. Before this split, every one of
those edits reported the same tier-3 "the middleware is gone".

A guard that moved is not a guard that was removed. Authorization migrates: a controller's
`authorize()` becomes a form request's, a policy becomes a gate. Every removal predicate fires only
when the removed thing is not added somewhere else in the same diff.

The lanes do not double-report, and a removal is reported once. A removed public policy method is the
`auth` lane's at tier 3, because it is a guard and not only a contract, and never also a tier-2
contract break. A queued class's changed constructor is the `boundary` lane's at tier 2, never also a
tier-1 signature change. A private member is no one's contract: its removal draws nothing, and a
signature change is skipped while the member stays private on both sides. A class the diff deletes
whole is reported once (`the class is gone`) rather than once per member, and a deleted policy class
is the `auth` lane's, once at tier 3 with every ability it held. A guard can also be gutted without
being removed: a policy method or form request `authorize()` whose body becomes exactly `return
true;` is a tier-3 hazard even though the member survives.

The ability is what is compared, not the call shape, so `Gate::denies('publish')` rewritten as
`$user->cannot('publish')` draws nothing. A policy constant counts as an ability. A project following
Laravel's own convention writes `can(PostPolicy::UPDATE)` everywhere and no string abilities at all,
so a comparison keyed on literals alone would leave this defence switched off for the entire
codebase. A removed policy method is named both ways, by its own name and by any constant in its
class whose value is that name, because a caller may spell it either way.

## Reach, and the matrix

Each hazard carries its own reach class, not the diff's. There are four states: two findings and two
admissions.

| State | | Meaning |
|---|---|---|
| `public-write` | finding | a route Brain marks `PUBLIC_WRITE`, with no guard richter can point at, reaches the hazardous member |
| `gated` | finding | every reaching entry point shows a guard: Brain classifies it `authed`, `admin` or `internal`, or the cross-check correlated a policy or auth middleware to it |
| `no-guard-found` | admission | it is reached, and no guard is visible on at least one of the routes that reach it |
| `no-known-path` | admission | no reaching entry point was found |

|  | `public-write` | `gated` | `no-guard-found` | `no-known-path` |
|---|---|---|---|---|
| **tier 3** | HIGH | HIGH | HIGH | HIGH |
| **tier 2** | HIGH | MEDIUM | MEDIUM | MEDIUM |
| **tier 1** | MEDIUM | MEDIUM | MEDIUM | LOW |

`gated` has to be earned, and every reaching entry point has to earn it. One route with no visible
guard is the way in, so a set where the others are guarded still grades `no-guard-found`. Averaging
would hide the surface that matters.

`no-guard-found` scores as `gated` does. An admission must move the level in neither direction.
Raising it would report HIGH across every application whose surfaces Brain cannot classify, such as a
Livewire or Filament codebase, which punishes a coverage gap as though it were a security one.
Lowering it would read absence of evidence as evidence. What the two states change is what the report
says, and that is the reason to tell them apart: a `command::` node, an unclassified Filament page and
a genuinely authenticated route are three different situations, and only one of them has a guard.

A route with no security entry at all is not gated by this test. Absence of classification is absence
of evidence, the same reason a missing entry never reads as "public" either.

`no-known-path` does not mean `internal-only`. Proving a member internal means proving a negative on a
graph that under-approximates by design. A member with no known path is unmeasured, not unreachable,
which is why tier 3 is HIGH everywhere. Capping it would silence tier 3 on the applications where
reach is hardest to resolve.

A removed member has no node in the head graph, so no path can reach it. Its reach comes from its
declaring class instead, the same stand-in the coarse-seed lane already makes for a change the graph
cannot pin.

### A reach class beside zero counts is not a contradiction

Two questions get answered separately, and the report prints both. The entry-point and impacted
counts are the diff's walk. The reach class is the hazard's own, and where the member is in no chain
the reach lane resolves it from the callers of the member's declaring class. A second query, which
answers whether or not the walk found anything.

So the two can disagree, and the shape looks like this:

```
Entry points reached: 0

Hazards (1):
  ! [tier 2 model CWE-915] App\Models\Order::$fillable — $fillable gained `status`
      reach: no-guard-found (via its class)

Risk:   MEDIUM (advisory) — tier 2 `model` hazard on App\Models\Order::$fillable, reach no-guard-found
Impact: 0 entry point(s) · 0 impacted node(s)
```

Nothing here is inconsistent. An addition-only `$fillable` edit is additive, so it seeds no walk and
the counts stay at zero; the hazard rides alongside it, and its reach comes from the model's own
callers. `no-guard-found` therefore says something specific: a surface reaching this model **was**
found, and no guard was visible on at least one of them. It is not the "nothing was found" answer:
that one is `no-known-path`, and richter grades the two apart. A model no surface reaches at all,
graded on the same diff, reports `no-known-path` with the same zero counts.

The `(via its class)` suffix marks exactly this case, and it belongs to the prose. The
`reach` field in `--json` and in MCP structured content never carries it. That value stays one of
the four states, so a consumer matching on them keeps working. Over MCP the text block is the same
prose the terminal prints, so the suffix does appear there.

The condition the suffix reports is that **this member** sits in no chain the walk recorded. Zero
counts, as above, is the sharpest case rather than the only one: a diff that also changes a
resolvable controller reports entry points and impacted nodes for that controller, while a hazard on
an unrelated service member still earns the suffix. In every case it says the same thing. The entry
points the report lists are not this hazard's evidence.

The class it names is the declaring class for a member (`Order::$fillable` resolves through `Order`)
and the class itself where the hazard is class-level. A `migration` hazard is named for the model
owning the table and a `contract` hazard can name a deleted class, and neither has a declaring class
to point at, so the suffix says "via its class" rather than claiming one.

The same reading applies to a hazard graded beside a list of entry points that all look guarded. The
hazard's reach is its declaring class's whole caller set, which is usually wider than the surfaces
the diff itself lists, so one unguarded caller outside that list is enough to earn
`no-guard-found`, `gated` requires every reaching entry point to show a guard.

## Verification, when there is no hazard

For a change carrying no hazard, the question becomes whether anything would catch a regression.
Richter grades every surface the level looks at, and `verification` in `--json` names each one.

The graded set is not the printed entry-point list:

| Group | Graded? | Why |
|---|---|---|
| the entry points the change reaches | yes | the surfaces it actually reaches |
| a changed class that reached none of them | yes, on its own import | otherwise a change richter cannot place has no road to `low` at all |
| a frontend file's routes | no | the backend behaviour behind them did not change |
| registry and association surfaces | no | one change to a registered class would otherwise reach every admin page behind the registry |

The class fallback is per class, not per diff: one changed class reaching routes says nothing about a
sibling in the same diff that reaches nothing.

Only a runnable test file (`*Test.php`) counts as a reference. Richter indexes every PHP file under
`tests/`, fixtures and base cases included, and letting one of those grade a surface "referenced" would
open a false `low`.

Two states count as unverified rather than verified:

- A reference state that could not be checked. A miss while the router was unavailable means the check
  never ran. Reading it as "not unreferenced" would open `low` on a surface nothing checked.
- No `tests/` directory at all. Every surface grades unreferenced and the level reads `medium`. That
  reading is intended.

The weak-assertion sub-tag counts as verified. Its grader collapses every uncertainty to plain
"referenced" by design; building the level on the weaker reading would invert that discipline. The tag
still prints on the row.

## What drifts, and in which direction

A tier is a fact about the diff. It never moves when richter learns to follow more edges, which is the
reason to score on it.

Reach class and verification state both move, and they move upward. A release that draws new edges can lift a
hazard from `no-known-path` to `public-write`, or reach a newly-visible surface that no test
references, and raise a level with nothing in your application having changed. Treat a level shift
right after a version bump as a coverage change first and a code change second, and pin the version in
CI if you need a `--fail-on` verdict to stay comparable across a release.

## Gating CI

Three flags, because blocking a removed guard, blocking a missing test, and blocking code richter
could not read are three different policies.

| Flag | Gates |
|---|---|
| `--fail-on=<level>` | the level |
| `--fail-on-hazard=<tier>` | hazards alone, whatever the level |
| `--fail-on-unresolved` | coverage, independently of both |

Coverage never floors the level. An UNRESOLVED file is its own gate, so a test-referenced change whose
dispatcher could not be followed still reports the level it earned, with the unresolved file named in
its own finding.

`--no-hazards` skips the hazard lanes. It changes the level, not only which section prints: the ladder
then falls through step 1 and decides on verification alone.

It does not silence the three parity lanes, which keep their own `payload_parity.enabled` key and
`--no-payload-parity` flag. Turning both off is what leaves `hazards` empty.

## Upgrading from the threshold model

`risk_thresholds` is retired and no longer read. The key is accepted and ignored for one release so an
upgrade does not fail on it; remove it.

The counts it graded are still reported, under `Impact`. They no longer decide anything, so there is
nothing left to calibrate. An absolute bar pinned to a count moved whenever richter learned to follow
more edges.

`scoredEntryPoints`, `scoredImpacted` and `coarseCapApplied` are gone from the report and from `--json`.
They existed to name the counts the level was scored on, and nothing is scored on counts any more.
`lowConfidence` remains: it describes the seeding, not the scoring.

If you keep a [benchmark corpus](18-benchmark.md), a control capping at `max_risk: low` needs
re-grading to `medium`, because a benign change richter cannot place now reports `medium` by design.
