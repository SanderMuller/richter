<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

use SanderMuller\Richter\Changes\ChangedFileSymbols;
use SanderMuller\Richter\Graph\CodeGraph;
use SanderMuller\Richter\Support\LoadableClass;
use SanderMuller\Richter\Tracers\McpServerTracer;

/**
 * The reasons a half-read MCP server gives a test selection ({@see AffectedTests}).
 *
 * @internal
 */
final class UnreadMcpServers
{
    /**
     * What an MCP server {@see McpServerTracer} only half read costs this selection.
     *
     * A server whose URI could not be read has no `route::` node, so a test that posts to its
     * endpoint cannot be matched. That matters exactly when the change reaches the server.
     *
     * A server whose primitive list could not be read can run a tool the graph draws no edge to. The
     * hidden edge is `server → someTool::handle`, so it can only hide a caller of the change when the
     * change is, or is reached from, a class that can be an MCP primitive. The same scoping an
     * unfollowable dispatch gets ({@see AffectedTests}), and it fails the same way: a
     * class that cannot be loaded counts as one.
     *
     * @param  array{entryPoints: list<string>, selectionOnlyEntryPoints?: list<string>, callers?: list<array{node: string, depth: int, via: string, file?: string, line?: int}>, ...}  $result
     * @param  list<ChangedFileSymbols>  $changed
     * @param  callable(string): ?string  $classOfNode  the class a graph node belongs to, or null for a node that names none
     * @return list<string>
     */
    public static function reasons(CodeGraph $graph, array $result, array $changed, callable $classOfNode): array
    {
        $reasons = [];
        $reached = array_values(array_filter(
            [...$result['entryPoints'], ...$result['selectionOnlyEntryPoints'] ?? []],
            static fn (string $node): bool => str_starts_with($node, McpServerTracer::NODE_PREFIX),
        ));

        $withoutUri = array_values(array_filter($reached, static fn (string $node): bool => $graph->outgoingTargetsOfType([$node], McpServerTracer::UNREAD_URI_EDGE) !== []));

        if ($withoutUri !== []) {
            $reasons[] = 'the change reaches an MCP server whose URL could not be read, so tests that post to it cannot be matched: '
                . implode(', ', array_map(self::mcpServerName(...), $withoutUri));
        }

        if (! $graph->hasEdgeType(McpServerTracer::UNREAD_PRIMITIVES_EDGE)) {
            return $reasons;
        }

        $classes = array_map(static fn (ChangedFileSymbols $file): ?string => $file->fqcn === '' ? null : $file->fqcn, $changed);

        foreach ($result['callers'] ?? [] as $hop) {
            $classes[] = $classOfNode($hop['node']);
        }

        if (array_any($classes, static fn (?string $class): bool => $class !== null && self::canBeMcpPrimitive($class))) {
            $servers = array_map(self::mcpServerName(...), array_filter($graph->nodes(), static fn (string $node): bool => str_starts_with($node, McpServerTracer::UNREAD_PREFIX)));
            sort($servers);

            $reasons[] = 'the tools, resources or prompts of an MCP server could not be read, and one of them can run the change: '
                . implode(', ', array_values(array_unique($servers)));
        }

        return $reasons;
    }

    private static function mcpServerName(string $node): string
    {
        return (string) preg_replace('/^mcp(?:-unread)?::/', '', $node);
    }

    /** Whether a class is, or cannot be shown not to be, a tool, a resource or a prompt. */
    private static function canBeMcpPrimitive(string $class): bool
    {
        return ! LoadableClass::exists($class) || is_subclass_of($class, McpServerTracer::PRIMITIVE);
    }
}
