# Where a symbol or file is

`richter:locate` answers "where is this?" and nothing else. No blast-radius walk, no second symbol — the orientation step before [`richter:impact`](10-impact.md) and [`richter:trace`](11-trace.md), which both need an exact node id.

```bash
php artisan richter:locate --symbol="App\Models\Post"
php artisan richter:locate --symbol=Post              # substrings work too
php artisan richter:locate --file=app/Models/Post.php # what does this file define?
php artisan richter:locate --symbol=Post --json       # machine-readable
php artisan richter:locate --symbol=Post --markdown   # PR-pasteable list
```

Pass exactly one of `--symbol` and `--file`. Both, or neither, is a usage error — and it is reported before the graph is built, so a mistyped flag never costs you a scan.

```text
2 node(s) matching "Post":
  [member] App\Models\Post::publish — app/Models/Post.php:48
  [model] model::App\Models\Post — app/Models/Post.php:12
```

The node id prints verbatim, prefix and all. That id is what you pass to `impact` or `trace`, so trimming it would break the next call. `kind` is the bracketed label beside it.

## What it finds, and what it does not

`locate` matches **node ids, not source text**. `Post` matches `App\Models\Post` at identifier boundaries — never `PostContainer`, never `SuperPost`. It finds a *symbol*; it cannot find a *behaviour*. For "where is this implemented", use your editor's search.

`--file` lists the nodes the graph pins to that exact file. The graph lists a file only when it defines a node carrying an edge, so a file whose symbols nothing reaches shows nothing — the same honesty rule the UNRESOLVED coverage state exists for.

## Paths are matched exactly

`--file` is matched against the graph's own file keys, twice: first exactly as you typed it, then again after stripping a leading `./` and the project root. So a project-relative path works, a `./`-prefixed one works, and an absolute path works whenever the graph holds that path — which it does for a file the build recorded absolute.

Trying the input unchanged first is what makes that last case work. Normalising first would rewrite an absolute path into a relative one the graph does not hold, turning a hit into a miss.

Repeated separators, `..` segments and backslash separators are not resolved. That looks strict, and it is deliberate. Resolving path forms inconsistently — a `/private/var` against a `/var`, a symlinked project root — makes a file that *is* in the graph look absent, and that miss is indistinguishable from a real one.

## When nothing matches

A miss is a result, not an error (exit 0). Unlike `richter:trace`, an empty `locate` has no misleading second reading: "nothing named X, nearest are Y and Z" answers the question that was asked.

A symbol miss carries the same nearest-graph-nodes lead [`richter:impact`](10-impact.md#when-a-symbol-matches-nothing) renders, or — when nothing in the graph even resembles the symbol — how many nodes were scanned, which separates a typo from an empty graph.

A file miss offers a known path sharing the file name, when there is one:

```text
No graph nodes are defined in "app/Modles/Post.php".
The graph knows app/Models/Post.php, which has the same file name.
```

When no file name matches, it reports how many files the graph can answer for instead — the file lane's own denominator, which separates a wrong path from a graph that pins no files at all. Both leads reach the `--json` document too (`suggestions`, or `graphFileCount`), so a script sees the same lead a reader does.

## `kind` is absent when richter cannot prove it

`kind` labels what an id addresses: a vocabulary prefix (`route`, `model`, `command`, …), `class`, or `member`. It is **omitted rather than guessed**. Laravel Brain owns the node vocabulary, and some id shapes are genuinely ambiguous — `A::m` could be a global-namespace class member or a prefix richter does not know. An absent `kind` means "richter cannot prove it", never "this has no kind".

## `--limit`

Omit it and the document is complete. Pass a number to cap the list; `total` always carries the uncapped count, and `bounded` says whether anything was held back.

The MCP `locate` tool caps at 15 by default, because a tool response lands in an agent's context window. The command does not: a script has a disk. [The MCP page](14-mcp-server.md) covers that split.

## `--json`

With `--json`, stdout is `{query, by, total, bounded, matches}` — plus `limit` when you passed one, and exactly one lead on a miss: `suggestions`, or `graphNodeCount` (symbol lane), or `graphFileCount` (file lane). Every optional field is **absent** rather than null, so a consumer never has to distinguish the two. Each match carries `node`, and `kind`, `file` and `line` when they are known.

On failure, stdout is `{"error": "…"}` and the exit code is 1.
