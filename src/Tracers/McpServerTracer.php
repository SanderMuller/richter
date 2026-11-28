<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tracers;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use SanderMuller\Richter\Graph\CodeGraphBuilder;
use SanderMuller\Richter\Support\AppFiles;
use Symfony\Component\Finder\Finder;

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
 * Static, like every other tracer: it reads the route files and the server classes
 * {@see CodeGraphBuilder} already parsed, and loads no class.
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

    private const string FACADE = 'Laravel\\Mcp\\Facades\\Mcp';

    private const string BASE_SERVER = 'Laravel\\Mcp\\Server';

    /** The file `laravel/mcp` loads itself, outside every route group. */
    private const string OWN_ROUTES = 'routes/ai.php';

    private const array LISTS = ['tools', 'resources', 'prompts'];

    /** @var array<string, array<string, list<string>|null>> class => list name => its `X::class` items, or null when one is something else */
    private array $lists = [];

    /** A superset gate: a file that names no MCP class can declare no server and no registration. */
    public static function mayMatch(string $source): bool
    {
        return str_contains($source, 'Mcp');
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
                        $this->lists[$fqcn][$prop->name->toString()] = self::classesIn($prop->default);
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

        foreach ($this->registrations($projectRoot) as $server => $uris) {
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
    private static function classesIn(?Expr $default): ?array
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

    private static function classConstant(?Expr $value): ?string
    {
        return $value instanceof ClassConstFetch
            && $value->class instanceof Name
            && $value->name instanceof Identifier
            && $value->name->toLowerString() === 'class'
                ? AppFiles::resolveName($value->class)
                : null;
    }

    /**
     * Server class => the URIs it is served at. A null URI is a web registration whose URI could
     * not be read, and a server with no URI at all is a local one (`Mcp::local`).
     *
     * `routes/ai.php` is read with an empty prefix, because the package loads it outside every
     * group. A registration in any other route file gets no URI: the group that file is loaded in
     * is decided in a provider or in `bootstrap/app.php`, which this does not read.
     *
     * @return array<string, list<string|null>>
     */
    private function registrations(string $projectRoot): array
    {
        $routes = "{$projectRoot}/routes";

        if (! is_dir($routes)) {
            return [];
        }

        $found = [];

        foreach (Finder::create()->files()->in($routes)->name('*.php')->sortByName() as $file) {
            $source = $file->getContents();
            $ast = self::mayMatch($source) ? AppFiles::parseResolved($source) : null;

            if ($ast !== null) {
                $own = substr($file->getPathname(), strlen($projectRoot) + 1) === self::OWN_ROUTES;
                $this->read($ast, $own ? '' : null, $found);
            }
        }

        ksort($found);

        return array_map(static fn (array $uris): array => array_values(array_unique($uris, SORT_REGULAR)), $found);
    }

    /**
     * @param  array<array-key, mixed>|Node|null  $node
     * @param  string|null  $prefix  the URI prefix in force here, or null when it cannot be read
     * @param  array<string, list<string|null>>  $found
     */
    private function read(array|Node|null $node, ?string $prefix, array &$found): void
    {
        if (is_array($node)) {
            foreach ($node as $child) {
                if (is_array($child) || $child instanceof Node) {
                    $this->read($child, $prefix, $found);
                }
            }

            return;
        }

        if (! $node instanceof Node) {
            return;
        }

        if ($node instanceof MethodCall || $node instanceof StaticCall) {
            // `Mcp::web(...)->middleware(...)`: the registration is the root of its own chain, and a
            // `->prefix()` on the route it returns moves the URI.
            $root = self::chainRoot($node);

            if ($root instanceof StaticCall && $this->register($root, in_array('prefix', self::chainNames($node), strict: true) ? null : $prefix, $found)) {
                return;
            }

            if ($node->name instanceof Identifier && $node->name->toString() === 'group') {
                $inner = self::prefixInside($node, $prefix);

                foreach ($node->getArgs() as $arg) {
                    $this->read($arg->value instanceof Closure || $arg->value instanceof ArrowFunction ? $arg->value->getStmts() ?? [$arg->value->expr ?? null] : null, $inner, $found);
                }

                return;
            }
        }

        // Any other closure runs when something this does not read decides, under a prefix it may set.
        if ($node instanceof Closure || $node instanceof ArrowFunction) {
            $prefix = null;
        }

        foreach ($node->getSubNodeNames() as $name) {
            $child = $node->{$name};

            if (is_array($child) || $child instanceof Node) {
                $this->read($child, $prefix, $found);
            }
        }
    }

    /**
     * Record one `Mcp::web()` or `Mcp::local()` call. False when the call is neither.
     *
     * @param  array<string, list<string|null>>  $found
     */
    private function register(StaticCall $call, ?string $prefix, array &$found): bool
    {
        if (! $call->class instanceof Name
            || ! $call->name instanceof Identifier
            || $call->isFirstClassCallable()
            || AppFiles::resolveName($call->class) !== self::FACADE
            || ! in_array($call->name->toString(), ['web', 'local'], strict: true)
        ) {
            return false;
        }

        $args = $call->getArgs();
        // A server named by anything but `X::class` is a server this cannot name at all. It stays in
        // the graph under one shared name, so the unread marker still says that one exists.
        $server = self::classConstant(($args[1] ?? null)?->value) ?? '(unknown)';
        $found[$server] ??= [];

        if ($call->name->toString() === 'web') {
            $route = ($args[0] ?? null)?->value;
            $found[$server][] = $prefix === null || ! $route instanceof String_
                ? null
                : '/' . trim(trim($prefix, '/') . '/' . trim($route->value, '/'), '/');
        }

        return true;
    }

    /** The call a fluent chain starts from: `Mcp::web(...)` under `->middleware(...)->name(...)`. */
    private static function chainRoot(Expr $call): Expr
    {
        while ($call instanceof MethodCall) {
            $call = $call->var;
        }

        return $call;
    }

    /** @return list<string> the method names of a fluent chain, outermost first */
    private static function chainNames(Expr $call): array
    {
        $names = [];

        for (; $call instanceof MethodCall || $call instanceof StaticCall; $call = $call instanceof MethodCall ? $call->var : null) {
            $names[] = $call->name instanceof Identifier ? $call->name->toString() : '';
        }

        return $names;
    }

    /**
     * The prefix in force inside a `->group(...)` call, or null when it cannot be read.
     *
     * Read from the two places a group takes one: a `prefix('x')` call earlier in the same chain, and
     * a `'prefix' => 'x'` entry in an attribute array. One literal prefix is read. A second `prefix()`
     * in the chain, a prefix that is not a string literal, and an attribute array that is not written
     * out all answer null.
     */
    private static function prefixInside(MethodCall|StaticCall $group, ?string $outer): ?string
    {
        if ($outer === null) {
            return null;
        }

        $added = [];

        for ($call = $group instanceof MethodCall ? $group->var : null; $call instanceof MethodCall || $call instanceof StaticCall; $call = $call instanceof MethodCall ? $call->var : null) {
            if (! $call->name instanceof Identifier) {
                return null;
            }

            if ($call->name->toString() === 'prefix') {
                $value = ($call->getArgs()[0] ?? null)?->value;

                if (! $value instanceof String_) {
                    return null;
                }

                $added[] = $value->value;
            }
        }

        foreach ($group->getArgs() as $arg) {
            if ($arg->value instanceof Closure || $arg->value instanceof ArrowFunction) {
                continue;
            }

            if (! $arg->value instanceof Array_) {
                return null;
            }

            foreach ($arg->value->items as $item) {
                if ($item->unpack || ! $item->key instanceof String_) {
                    return null;
                }

                if ($item->key->value === 'prefix') {
                    if (! $item->value instanceof String_) {
                        return null;
                    }

                    $added[] = $item->value->value;
                }
            }
        }

        return match (count($added)) {
            0 => $outer,
            1 => trim($outer, '/') . '/' . trim($added[0], '/'),
            default => null,
        };
    }
}
