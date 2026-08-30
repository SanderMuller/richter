# Shortest path between two symbols

`richter:trace` answers "does FROM reach TO, and through which chain?".

```bash
php artisan richter:trace "App\Http\Controllers\PostController" "App\Services\PostPublisher"
php artisan richter:trace PostController PostPublisher        # substrings work too
php artisan richter:trace PostController PostPublisher --json       # machine-readable
php artisan richter:trace PostController PostPublisher --markdown   # PR-pasteable chain
```

The search runs strictly in call direction; swap the arguments to query the reverse. A found path prints as one chain, each arrow labelled with the edge type connecting its two hops:

```text
Path from "PostController" to "App\Services\PostPublisher" (call direction, 1 hop(s)):
  ↳ App\Http\Controllers\PostController::publish →(action-to-service) App\Services\PostPublisher
```

## Depth

`--depth` sets how many hops the search covers (default 6). Raise it when a miss reports a deepest-caller note: that note means the walk ran out of depth, not that no path exists.

## When there is no path

No path is a result, not an error (exit 0). The report then names the deepest caller reached from the TO side within the depth limit, which tells you how far upstream connectivity extends. It is not a pointer toward FROM. When the target has no callers at all, the report says so plainly.

An unresolvable symbol *is* an error. An empty trace would read as "no path", a wrong answer rather than an empty one. The error carries the same nearest-graph-nodes lead [`richter:impact`](10-impact.md#when-a-symbol-matches-nothing) renders.

## `--json`

With `--json`, stdout is `{from, to, resolvedFrom, resolvedTo, found, path}`, plus `furthestReached` (`{node, depth, file?, line?}`) on a miss whose target has callers, or `{"error": "…"}` on failure.
