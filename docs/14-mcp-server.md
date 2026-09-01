# MCP server

When [`laravel/mcp`](https://github.com/laravel/mcp) is installed, Richter registers a local MCP server named `richter`. A coding agent can then triage changes without shelling out to Artisan.

## Tools

Six read-only tools:

| Tool | What it returns |
|---|---|
| `locate` | Where a symbol or file is, with no walk — the node id `impact` and `trace` need. |
| `impact` | Blast radius plus reached entry surfaces of a symbol. |
| `trace` | Shortest call-direction path between two symbols. |
| `detect-changes` | Advisory impact of the current branch diff. |
| `affected-tests` | The test selection the diff warrants. |
| `task-slice` | The surfaces this task owns, with the hazards, findings and tests that go with them. |

`detect-changes` takes `base` and `head`. `head` defaults to `HEAD`, which includes the uncommitted
working tree; naming a commit analyses that committed state instead. An agent working mid-feature
against a task parent needs it — the CLI has had `--head` since the option existed, and without the
argument the tool could only ever read a range ending at the working tree.

For `affected-tests`, `determinable: false` means run the full suite. Every non-determinable cause returns that shape with its reasons, never a tool error.

Every tool returns MCP structured content in the same vocabulary and shapes as the CLI `--json` output, so an agent can branch on fields instead of parsing prose. Because the MCP session holds the graph cache in memory, repeated tool calls in one review do not rebuild the graph.

### Bounded by default

`impact` and `detect-changes` cap their structured content: the breadth arrays (callers,
dependencies, entry points, association surfaces, and their kin) cut at 15 entries in their
existing order, and the per-entry maps restrict to the entry points still shown. A full document
on a hub symbol runs to megabytes — far past what an agent can carry beside its task — so the
default response keeps the nearest hops and the first surfaces, which are the rows a reviewer
reads first anyway.

The bound is honest: `bounded` is `true` whenever anything was held back, and every capped array
carries its full count in a `…Total` field (`callersTotal`, `entryPointsTotal`, and so on;
`entryPointKeepSet` carries `keptTotal` inside the object). An empty section still means "not
found", never "safe" — the cap changes how much is listed, not what is claimed. `hazards`,
`verification`, and the risk verdict are never capped.

Two optional arguments drill down:

- `full: true` returns the uncapped lists and maps. The response still carries the
  `bounded`/`…Total` fields, so it is the CLI `--json` document plus those fields, not
  byte-identical to it.
- `entries: [...]` names entry points (copied from a previous response) to keep visible past the
  cap, in `entryPoints` and in every per-entry map. Unknown names are ignored; `full` wins when
  both are passed.

`locate` bounds itself the same way, with one argument instead of two: `limit` defaults to 15,
`total` carries the uncapped count, and `bounded` says whether anything was held back. Raise
`limit` to `total` for the rest. The `richter:locate` command applies **no** default cap — a
script has a disk, not a context window — so its `--json` document is complete unless you pass
`--limit`. See [`richter:locate`](21-locate.md).

The CLI `--json` output is unaffected: it remains the complete, uncapped machine contract — a
script has a disk, not a context window.

## Resources

Three read-only resources cover orientation without a tool call:

| Resource | URI | Content |
|---|---|---|
| Entry points | `richter://graph/entry-points` | Every statically-known entry surface (routes, commands, schedules, Livewire/Filament/Nova components) with kind and `file:line` where known. |
| Graph stats | `richter://graph/stats` | Node and edge counts by edge type, plus the honesty flags (`hasUnparseableFiles`, `hasUnresolvedDispatches`) and `unresolvedDispatchSites`, which names each unfollowable dispatch by file, line and dispatching member. |
| Config | `richter://config` | The effective analysis configuration: base ref, root namespace, entry-point roots, dispatch helpers, feature-gate wrappers, payload-parity settings, the frontend bridge, whether the cache is enabled, and the parallel switch. |

## Supported versions

The supported range is `laravel/mcp` `^0.8||^0.9`. `composer.json` carries a matching `conflict` entry, so an unvalidated release fails at resolution time rather than at boot.

## Registering the server

Point Claude Code, Cursor, or any MCP client at the Artisan entry point, for example in `.mcp.json`:

```json
{
    "mcpServers": {
        "richter": {
            "command": "php",
            "args": ["artisan", "mcp:start", "richter"]
        }
    }
}
```

The `/richter-setup` skill registers this for you if you ask it to; see [Set up your project](04-project-setup.md).
