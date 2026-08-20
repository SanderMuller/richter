# Output formats

Beyond the default text report, `richter:detect-changes` writes three other formats. `--markdown` is for pull requests, `--html` is for a visual read, and `--json` is the machine contract.

## `--markdown` and `--html` output

With `--markdown`, the report renders as GitHub-flavoured markdown: a risk badge up front, changed files as a table, entry points as a review checklist with their file:line, test tags and exposure badges, and long lists collapsed into `<details>` instead of truncated. The result is ready to paste into (or post onto) a pull request. `--markdown --explain` composes.

With `--html=<path>`, the report is written as ONE self-contained HTML file (every style and script inline, nothing fetched), so it opens offline straight from `file://` and travels as a CI artifact you can link from a pull request. It has five tabs: Overview (a Files / Impacted / Depth / Risk stat row, the reached entry points, and what to focus on), Graph (the blast radius as concentric rings, one per BFS depth), Paths (how each entry point reaches the change), Changes (the member-level diff, naming the member that drove a low-confidence verdict), and Advisory (findings, test references, and the gate). `--open` launches it in the default browser afterwards; a failing opener is a warning, never a failed run.

Every `file:line` in the report is a clickable editor link. `richter.editor` reads the same env chain debugbar and Ignition do (`CODE_EDITOR`, then `DEBUGBAR_EDITOR`, then `IGNITION_EDITOR`) and, like debugbar, defaults to `phpstorm`, so an existing setup needs no new variable. Supported: `phpstorm`, `idea`, `vscode`, `vscode-insiders`, `vscode-remote`, `vscodium`, `sublime`, `textmate`, `emacs`, `macvim`, `atom`, `nova`, `netbeans`, `xdebug`. Set it to `null` to keep the file references plain text, worth doing for a shared CI artifact, since a link embeds an absolute local path that only opens on the machine that generated the report.

`--html` cannot be combined with `--json` or `--markdown`. It replaces the text report on stdout but never touches the gate: `--html --fail-on=medium` still exits non-zero exactly when the gate trips. The diagram is capped at 300 nodes and says so in the report when it caps; the counts above it are never capped. Note that the HTML is a **rendering surface, not a contract**: its markup is free to change in any release. `--json` remains the semver-governed machine output.

## `--json` output

With `--json`, stdout is a single JSON document (the full, uncapped report) with these top-level keys, or `{"error": "…"}` if the diff can't be resolved:

| Key | Type | Meaning |
|---|---|---|
| `base` | string | the ref the diff was taken against |
| `changed` | object | `{file: graph-node count}` per changed file |
| `coverage` | object | `{file: "analyzed" \| "unresolved"}` per changed file |
| `entryPoints` | string[] | entry-point nodes the change reaches through calls, plus the two sets appended after that walk: a changed class that is itself an entry surface (self-listed), and the routes a changed frontend file references. Both are appended after the risk level is scored, which is what `scoredEntryPoints` accounts for |
| `associationEntryPoints` | string[] | entry surfaces connected only by an association edge (`model-relationship` or `model-to-policy`); associated with the change, not callers of it, and excluded from `risk` |
| `entryPointPaths` | object | per reached entry point, the shortest call chain down to the changed code as `{node, via, file?, line?}` hops; a self-listed entry class carries no chain |
| `entryPointLocations` | object | per entry point, its defining `{file, line?}` (project-relative), when known |
| `entryPointSecurity` | object | per reached route, Brain's security surface `{exposure, riskLevel, issues[]}` (advisory annotation, routes only, never an input to `risk` or the gate); a Livewire/Filament/Nova/queue entry point has no key here at all, meaning "not classified," never "public" |
| `entryPointGates` | object | per reached route, the Pennant feature flags gating it (advisory annotation, never an input to `risk` or the gate) |
| `entryPointTestReferences` | object | per reached entry point, `"referenced"` / `"referenced-no-behavioural-assertion"` / `"unreferenced"`; an entry point whose reference state cannot be determined is omitted from the map (advisory annotation, never an input to `risk`, the gate, or `affected-tests` selection) |
| `impacted` | int | count of risk-bearing nodes reached |
| `relatedModels` | string[] | models reached only via association edges (context, not risk) |
| `traitAndOverrideReach` | string[] | classes that run a changed member without calling it, meaning trait users and override implementors (context, not risk; the report prints these under "Runs this code without calling it") |
| `risk` | string | `"low"` / `"medium"` / `"high"` |
| `lowConfidence` | bool | a changed member couldn't be pinned, so part of the estimate is coarse |
| `coarseCapApplied` | bool | a low-confidence `high` was capped to `medium`. Whether the cap *downgraded*, never whether the rescore ran; `false` on a confirmed `high` that was still re-scored, so it says nothing about the printed counts being the scored ones |
| `scoredEntryPoints` | int | entry points the `risk` level was decided on; see [Risk levels](07-risk-levels.md#the-counts-the-risk-level-was-decided-on) |
| `scoredImpacted` | int | risk-bearing nodes the `risk` level was decided on; see [Risk levels](07-risk-levels.md#the-counts-the-risk-level-was-decided-on) |
| `findings` | string[] | source-level findings |
| `unresolved` | bool | any changed file is UNRESOLVED |
| `gate` | object | present only under a `--fail-on*` flag (see [Gating in CI](08-ci-gating.md)) |
