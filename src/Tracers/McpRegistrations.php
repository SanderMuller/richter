<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tracers;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use SanderMuller\Richter\Support\AppFiles;
use Symfony\Component\Finder\Finder;

/**
 * The `Mcp::web()` and `Mcp::local()` calls in a project's route files, for {@see McpServerTracer}.
 *
 * A web registration's URI is the route argument under the prefixes of the groups around it, so the
 * walk carries the prefix in force: it changes at a `->group(function () { … })`, and it is unknown
 * inside any other closure, which runs when something this does not read decides.
 *
 * `routes/ai.php` starts with an empty prefix, because the package loads that file outside every
 * group. Any other route file starts unknown: the group it is loaded in is decided in a provider or
 * in `bootstrap/app.php`.
 *
 * @internal
 */
final class McpRegistrations extends NodeVisitorAbstract
{
    private const string FACADE = 'Laravel\\Mcp\\Facades\\Mcp';

    /** The file `laravel/mcp` loads itself, outside every route group. */
    private const string OWN_ROUTES = 'routes/ai.php';

    /** @var array<string, list<string|null>> server class => its URIs; null is a URI that could not be read */
    private array $found = [];

    /** @var non-empty-list<string|null> the prefix in force, innermost last; null is unknown */
    private array $prefixes;

    /** @var array<int, string|null> a group's closure => the prefix inside it */
    private array $groupClosures = [];

    /** @var array<int, true> a registration whose returned route a later `->prefix()` moves */
    private array $moved = [];

    private function __construct(?string $prefix)
    {
        $this->prefixes = [$prefix];
    }

    /**
     * Server class => the URIs it is served at. A null URI is a web registration whose URI could
     * not be read, and a server with no URI at all is a local one.
     *
     * @return array<string, list<string|null>>
     */
    public static function in(string $projectRoot): array
    {
        $routes = "{$projectRoot}/routes";
        $found = [];

        if (! is_dir($routes)) {
            return [];
        }

        foreach (Finder::create()->files()->in($routes)->name('*.php')->sortByName() as $file) {
            $source = $file->getContents();
            $ast = McpServerTracer::mayMatch($source) ? AppFiles::parseResolved($source) : null;

            if ($ast === null) {
                continue;
            }

            $reader = new self(substr($file->getPathname(), strlen($projectRoot) + 1) === self::OWN_ROUTES ? '' : null);
            new NodeTraverser($reader)->traverse($ast);

            foreach ($reader->found as $server => $uris) {
                $found[$server] = [...$found[$server] ?? [], ...$uris];
            }
        }

        ksort($found);

        return array_map(static fn (array $uris): array => array_values(array_unique($uris, SORT_REGULAR)), $found);
    }

    public function enterNode(Node $node): null
    {
        if ($node instanceof MethodCall || $node instanceof StaticCall) {
            $this->readCall($node);
        }

        if ($node instanceof Closure || $node instanceof ArrowFunction) {
            $id = spl_object_id($node);
            $this->prefixes[] = $this->groupClosures[$id] ?? null;
        }

        return null;
    }

    public function leaveNode(Node $node): null
    {
        if (($node instanceof Closure || $node instanceof ArrowFunction) && count($this->prefixes) > 1) {
            array_pop($this->prefixes);
        }

        return null;
    }

    private function readCall(MethodCall|StaticCall $call): void
    {
        $name = $call->name instanceof Identifier ? $call->name->toString() : null;

        // `Mcp::web(...)->prefix('api')`: the outer call is entered first, so the registration under
        // it is marked before it is read.
        if ($name === 'prefix' && $call instanceof MethodCall) {
            $this->moved[spl_object_id($this->chainRoot($call))] = true;
        }

        if ($name === 'group') {
            $inner = $this->prefixInside($call, end($this->prefixes));

            foreach ($call->getArgs() as $arg) {
                $this->groupClosures[spl_object_id($arg->value)] = $inner;
            }
        }

        if ($call instanceof StaticCall && in_array($name, ['web', 'local'], strict: true)) {
            $this->register($call, $name);
        }
    }

    private function register(StaticCall $call, string $kind): void
    {
        if (! $call->class instanceof Name || $call->isFirstClassCallable() || AppFiles::resolveName($call->class) !== self::FACADE) {
            return;
        }

        $args = $call->getArgs();
        // A server named by anything but `X::class` is a server this cannot name at all. It stays in
        // the graph under one shared name, so the unread marker still says that one exists.
        $server = McpServerTracer::classConstant(($args[1] ?? null)?->value) ?? '(unknown)';
        $this->found[$server] ??= [];

        if ($kind !== 'web') {
            return;
        }

        $route = ($args[0] ?? null)?->value;
        $prefix = isset($this->moved[spl_object_id($call)]) ? null : end($this->prefixes);

        $this->found[$server][] = $prefix === null || ! $route instanceof String_
            ? null
            : '/' . trim(trim($prefix, '/') . '/' . trim($route->value, '/'), '/');
    }

    /** The call a fluent chain starts from: `Mcp::web(...)` under `->middleware(...)->prefix(...)`. */
    private function chainRoot(Expr $call): Expr
    {
        while ($call instanceof MethodCall) {
            $call = $call->var;
        }

        return $call;
    }

    /**
     * The prefix in force inside a `->group(...)` call, or null when it cannot be read.
     *
     * A group takes a prefix in two places: a `prefix('x')` call earlier in the same chain, and a
     * `'prefix' => 'x'` entry in an attribute array. One literal prefix is read. A second one, a
     * prefix that is not a string literal, and an attribute array that is not written out all
     * answer null.
     */
    private function prefixInside(MethodCall|StaticCall $group, ?string $outer): ?string
    {
        $chained = $this->chainedPrefixes($group);
        $attributes = $this->attributePrefixes($group);

        if ($outer === null || $chained === null || $attributes === null) {
            return null;
        }

        return match (count($chained) + count($attributes)) {
            0 => $outer,
            1 => trim($outer, '/') . '/' . trim([...$chained, ...$attributes][0], '/'),
            default => null,
        };
    }

    /** @return list<string>|null the literal `prefix('x')` calls before the group in its chain */
    private function chainedPrefixes(MethodCall|StaticCall $group): ?array
    {
        $prefixes = [];

        for ($call = $group instanceof MethodCall ? $group->var : null; $call instanceof MethodCall || $call instanceof StaticCall; $call = $call instanceof MethodCall ? $call->var : null) {
            if (! $call->name instanceof Identifier) {
                return null;
            }

            if ($call->name->toString() !== 'prefix') {
                continue;
            }

            $value = ($call->getArgs()[0] ?? null)?->value;

            if (! $value instanceof String_) {
                return null;
            }

            $prefixes[] = $value->value;
        }

        return $prefixes;
    }

    /** @return list<string>|null the literal `'prefix' => 'x'` entries of the group's attribute array */
    private function attributePrefixes(MethodCall|StaticCall $group): ?array
    {
        $prefixes = [];

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

                if ($item->key->value !== 'prefix') {
                    continue;
                }

                if (! $item->value instanceof String_) {
                    return null;
                }

                $prefixes[] = $item->value->value;
            }
        }

        return $prefixes;
    }
}
