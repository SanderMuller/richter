<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tracers;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use SanderMuller\Richter\Graph\CodeGraphBuilder;
use SanderMuller\Richter\Support\AppFiles;

/**
 * A `laravel/mcp` server as an entry surface.
 *
 * `Mcp::web('/mcp', OrderServer::class)` registers a route with a closure action, and the package
 * calls `handle()` on each tool, resource and prompt the server class lists. Neither step is a call
 * in application code, so a tool's `handle()` had no caller and reached no entry point: a change
 * behind a tool reported no surface, and a test that posts to the endpoint was never selected.
 *
 * Two edges close that:
 *
 * - `route::POST::{uri}` to `mcp::{server}`, for a web registration whose URI can be read;
 * - `mcp::{server}` to `{primitive}::handle`, for every class in the server's `$tools`,
 *   `$resources` and `$prompts`.
 *
 * Static, like every other tracer: it reads the route files ({@see McpRegistrations}) and the
 * server classes {@see CodeGraphBuilder} already parsed, and loads no class.
 *
 * What it cannot read it says, as an edge to a `mcp-unread::` node, so that a selection can tell a
 * server it read from one it only half read:
 *
 * - `mcp-uri-unread`: the URI is not a literal under literal prefixes. The route node would carry
 *   the wrong URI, so none is drawn, and a test that posts to the endpoint cannot be matched.
 * - `mcp-primitives-unread`: a list holds something other than `X::class`, the server overrides
 *   `boot()`, or the class is outside the scanned tree. The server can then run a primitive this
 *   never saw.
 *
 * @internal
 */
final class McpServerTracer
{
    public const string NODE_PREFIX = 'mcp::';

    public const string UNREAD_PREFIX = 'mcp-unread::';

    public const string ROUTE_EDGE = 'mcp-route-to-server';

    public const string PRIMITIVE_EDGE = 'mcp-server-to-primitive';

    public const string UNREAD_URI_EDGE = 'mcp-uri-unread';

    public const string UNREAD_PRIMITIVES_EDGE = 'mcp-primitives-unread';

    /** The class a tool, a resource and a prompt all extend. */
    public const string PRIMITIVE = 'Laravel\\Mcp\\Server\\Primitive';

    private const string BASE_SERVER = 'Laravel\\Mcp\\Server';

    private const array LISTS = ['tools', 'resources', 'prompts'];

    /** @var array<string, array<string, list<string>|null>> class => list name => its `X::class` items, or null when one is something else */
    private array $lists = [];

    /** A superset gate: a file that names no MCP class can declare no server and no registration. */
    public static function mayMatch(string $source): bool
    {
        return str_contains($source, 'Mcp');
    }

    /** The server class an `mcp::` node names, or null for any other node. */
    public static function serverOf(string $node): ?string
    {
        return str_starts_with($node, self::NODE_PREFIX) ? substr($node, strlen(self::NODE_PREFIX)) : null;
    }

    /**
     * Record the primitive lists every class in one file declares. Any class, not only a known
     * server: whether a class IS one is only known once the route files are read.
     *
     * @param  list<ClassLike>  $classLikes
     */
    public function collect(array $classLikes): void
    {
        foreach ($classLikes as $classLike) {
            $fqcn = $classLike instanceof Class_ ? $classLike->namespacedName?->toString() : null;

            if ($fqcn === null) {
                continue;
            }

            foreach ($classLike->getProperties() as $property) {
                foreach ($property->props as $prop) {
                    if (in_array($prop->name->toString(), self::LISTS, strict: true)) {
                        $this->lists[$fqcn][$prop->name->toString()] = $this->classesIn($prop->default);
                    }
                }
            }
        }
    }

    /**
     * @param  array<string, array{parent: string|null, declared: list<string>}>  $inheritance  every scanned class
     * @return list<array{source: string, target: string, type: string}>
     */
    public function edges(string $projectRoot, array $inheritance): array
    {
        $edges = [];

        foreach (McpRegistrations::in($projectRoot) as $server => $uris) {
            $node = self::NODE_PREFIX . $server;
            $primitives = $this->primitivesOf($server, $inheritance);

            foreach ($primitives ?? [] as $primitive) {
                $edges[] = ['source' => $node, 'target' => "{$primitive}::handle", 'type' => self::PRIMITIVE_EDGE];
            }

            if ($primitives === null) {
                $edges[] = ['source' => $node, 'target' => self::UNREAD_PREFIX . $server, 'type' => self::UNREAD_PRIMITIVES_EDGE];
            }

            foreach ($uris as $uri) {
                $edges[] = $uri === null
                    ? ['source' => $node, 'target' => self::UNREAD_PREFIX . $server, 'type' => self::UNREAD_URI_EDGE]
                    : ['source' => "route::POST::{$uri}", 'target' => $node, 'type' => self::ROUTE_EDGE];
            }
        }

        return AppFiles::dedupeEdges($edges, byType: true);
    }

    /**
     * Every primitive the server lists, its own and inherited, or null when the lists cannot be read
     * in full.
     *
     * A list a subclass declares REPLACES the parent's, as any property default does, so the nearest
     * declaration of each list wins. The walk ends at the package's base server. It ends in null at
     * any other class this never scanned, and at a `boot()` override, which is where a server
     * registers primitives in code.
     *
     * @param  array<string, array{parent: string|null, declared: list<string>}>  $inheritance
     * @return list<string>|null
     */
    private function primitivesOf(string $server, array $inheritance): ?array
    {
        $found = [];
        $seen = [];

        for ($class = $server; $class !== self::BASE_SERVER; $class = $inheritance[$class]['parent']) {
            if (! isset($inheritance[$class]['parent']) || isset($seen[$class]) || in_array('boot', $inheritance[$class]['declared'], strict: true)) {
                return null;
            }

            $seen[$class] = true;

            foreach ($this->lists[$class] ?? [] as $list => $classes) {
                if ($classes === null) {
                    return null;
                }

                $found[$list] ??= $classes;
            }
        }

        return array_values(array_unique(array_merge(...array_values($found))));
    }

    /**
     * The `X::class` items of a list's default, or null when the default is anything else: no
     * array, a spread, a string, a call. Each of those can name a class this does not see.
     *
     * @return list<string>|null
     */
    private function classesIn(?Expr $default): ?array
    {
        if (! $default instanceof Array_) {
            return null;
        }

        $classes = [];

        foreach ($default->items as $item) {
            $class = self::classConstant($item->unpack ? null : $item->value);

            if ($class === null) {
                return null;
            }

            $classes[] = $class;
        }

        return $classes;
    }

    /** The class an `X::class` expression names, or null when the expression is anything else. */
    public static function classConstant(?Expr $value): ?string
    {
        return $value instanceof ClassConstFetch
            && $value->class instanceof Name
            && $value->name instanceof Identifier
            && $value->name->toLowerString() === 'class'
                ? AppFiles::resolveName($value->class)
                : null;
    }
}
