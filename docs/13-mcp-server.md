# MCP server

When [`laravel/mcp`](https://github.com/laravel/mcp) is installed, Richter registers a local MCP server named `richter`. A coding agent can then triage changes without shelling out to Artisan.

## Tools

Four read-only tools:

| Tool | What it returns |
|---|---|
| `impact` | Blast radius plus reached entry surfaces of a symbol. |
| `trace` | Shortest call-direction path between two symbols. |
| `detect-changes` | Advisory impact of the current branch diff. |
| `affected-tests` | The test selection the diff warrants. |

For `affected-tests`, `determinable: false` means run the full suite. Every non-determinable cause returns that shape with its reasons, never a tool error.

Every tool returns MCP structured content in the same shape as the CLI `--json` output, so an agent can branch on fields instead of parsing prose. Because the MCP session holds the graph cache in memory, repeated tool calls in one review do not rebuild the graph.

## Resources

Three read-only resources cover orientation without a tool call:

| Resource | URI | Content |
|---|---|---|
| Entry points | `richter://graph/entry-points` | Every statically-known entry surface (routes, commands, schedules, Livewire/Filament/Nova components) with kind and `file:line` where known. |
| Graph stats | `richter://graph/stats` | Node and edge counts by edge type, plus the honesty flags (`hasUnparseableFiles`, `hasUnresolvedDispatches`) and `unresolvedDispatchSites`, which names each unfollowable dispatch by file, line and dispatching member. |
| Config | `richter://config` | The effective analysis configuration: base ref, root namespace, entry-point roots, dispatch helpers, feature-gate wrappers, payload-parity settings, the frontend bridge, cache and parallel switches. |

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

The `/richter-setup` skill registers this for you if you ask it to; see [Set up your project](03-project-setup.md).
