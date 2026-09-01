# Personas — who consumes richter's output

A persona earns its place only when it changes what richter must output. No
demographics. Each persona carries a **feared failure** — the thing that, if it
happens, makes the tool worse than not running it — and **obligations** the
output must meet for that persona.

Three axes. Tag a story with the axes that bite it.

- **C** — consumer: who or what reads the output.
- **W** — workflow moment: when in the development cycle the run happens.
- **H** — symbol shape modifier: leaf or hub. Orthogonal; it scales the output
  size, so it decides which stories stress the output contract.

---

## C — Consumers

### C1 — Agent-only

A coding agent calls the MCP tools mid-task. The human reads the agent's
conclusions, never richter's raw output. The output lands in a bounded context
window next to everything else the agent holds.

**Feared failure:** the report displaces the agent's working context, or the
agent reads a bounded list as the complete answer. Both produce a confident
wrong conclusion the human then trusts.

**Obligations:**

- Responses bounded in size, or explicitly marked where they are not complete.
- Machine-branchable fields (`determinable`, `unresolved`, counts) so the agent
  branches on data, never on prose.
- Absence stays honest: an empty section means "not found", never "safe".
- Repeated calls in one session stay cheap (the MCP session holds the graph in
  memory).

### C2 — Hybrid

A developer drives an agent and also runs the CLI directly. They read the
`--markdown` or HTML report themselves while the agent reads the structured
content. Two readers, one change.

**Feared failure:** the agent's view and the human's view diverge — different
caps, different vocabulary, different facts — so the pair debates two different
reports.

**Obligations:**

- One vocabulary across formats: the JSON keys, the prose labels, and the MCP
  structured content name the same things the same way.
- One reading order across formats: `entryPoints` in the machine payload is in
  the same most-specifically-explained-first order the prose renders, so a
  prefix of the array is the prefix the human sees.
- A cap applied to one format never changes what another format asserts. A
  capped list says it is capped.
- The CLI and the MCP tool answer the same question with the same analysis.

### C3 — CLI-only

A developer with no agent. Terminal output, HTML report, markdown for a PR
comment. Reads with human attention: top of the output first, scrolling costs
focus.

**Feared failure:** the one finding that matters — a removed guard, an
unreferenced surface — scrolls past inside a hub change's hundreds of lines.

**Obligations:**

- Worst first: hazards ordered by tier, risk cause named at the top.
- Breadth lists capped in prose, with an honest "and N more" tail.
- Drill-down on demand (`--explain`, `richter:trace`) instead of everything at
  once.
- Every named symbol carries its `file:line`, so the reader never greps for
  what the report names.

### C4 — CI machine

A pipeline. Headless, no reader at run time. Consumes exit codes and `--json`
stdout; humans see only the downstream effect (a blocked merge, a posted
comment, a test selection).

**Feared failure:** a wrong verdict. A gate passes a tier-3 hazard, a test
selection silently omits a test, or a truncated payload breaks a parser. There
is no human in the loop to catch it.

**Obligations:**

- `--json` stdout is one complete document — full, uncapped, stable keys. A
  script that stores or post-processes the report gets everything.
- Exit codes are the contract and fail safe: an un-assessable diff fails the
  gate, an undeterminable selection means the full suite.
- A selection is never narrowed; the only conservative direction is toward
  running more.
- Verdicts stay comparable across runs of the same version.
- Only `--json` is the machine contract. The text, markdown, and HTML formats
  are rendering surfaces whose wording may change in any release — a pipeline
  never parses them. A value a pipeline needs but `--json` lacks is a payload
  addition to request, not a sentence to parse.

### C5 — Adopter / tuner

The person (or agent following `/richter-setup`) who configures richter for a
project and keeps the configuration true as the app evolves: `root_namespace`,
`entry_point_roots`, hub paths, frontend roots, hazard ignores, benchmark
cases. Their channel is different from every other persona's: **stderr**
warnings, `--profile` output, troubleshooting leads, and benchmark verdicts —
the report body stays clean for C1–C4.

**Feared failure:** a misconfiguration silently under-reports. A wrong root
namespace makes every file UNRESOLVED; a missing `entry_point_roots` entry
makes a whole subsystem invisible; an unregistered HTTP wrapper hides frontend
references. The report then looks calm on exactly the changes it cannot see.

**Obligations:**

- Every degradation names its diagnosis: an UNRESOLVED file echoes the FQCN
  its path derived to, an unreached `app/` directory is noted from five
  classes up, a scoped-rebuild refusal names the precondition that refused.
- Diagnostics stay on stderr so `--json`, `--plain`, and `--markdown` stdout
  never carry them.
- Config that changes what the build reads is part of the cache fingerprint —
  a tuning change is never served a stale graph.
- Accuracy is pinnable: `richter:benchmark` replays the project's own
  historical fixes, so an upgrade that regresses reach or reports a false
  hazard turns a case red instead of drifting silently.

---

## W — Workflow moments

| ID | Moment | The question the run answers |
|----|--------|------------------------------|
| **W0** | Adoption / tuning | "Does richter see this app correctly?" — setup, config drift, an UNRESOLVED diagnosis, pinning accuracy across upgrades. |
| **W1** | Planning | "What would this feature touch?" — before any code changes. |
| **W2** | Mid-build | "What does the work in front of me own, and what is still unproven?" |
| **W3** | Refactoring | "What breaks if I change this symbol?" — before touching it. |
| **W4** | Pre-PR | "Is this branch ready?" — final self-check before requesting review. |
| **W5** | Review | A reviewer orients on someone else's diff from the posted report. |
| **W6** | CI | The pipeline gates, selects tests, or posts the advisory comment. |
| **W7** | Exploration | "How does this codebase hang together?" — onboarding, debugging, curiosity. |

---

## H — Symbol shape modifier

- **H-leaf** — the change or query touches symbols with few callers. Reports
  are small. Every consumer can take the full output.
- **H-hub** — the change or query touches a widely-used symbol (a base model, a
  shared service, a trait). Callers, entry points, and path maps fan out to
  hundreds of entries. This is where output-size obligations are tested; a
  contract that only works on H-leaf is not a contract.

Tag a story `H-hub` when its outcome changes under fan-out. `task-slice`'s hub
folding (`droppedHubCount`) and the prose list caps exist because of this
modifier.
