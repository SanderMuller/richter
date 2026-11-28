<?php declare(strict_types=1);

namespace SanderMuller\Richter\Changes;

use PhpParser\Node\AttributeGroup;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassConst;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\EnumCase;
use PhpParser\Node\Stmt\Property;
use PhpParser\NodeFinder;
use PhpParser\PrettyPrinter\Standard;
use SanderMuller\Richter\Support\AppFiles;
use SanderMuller\Richter\Support\LoadableClass;
use SanderMuller\Richter\Tracers\McpServerTracer;

/**
 * A class-level edit that only changes `laravel/mcp` metadata on a tool, resource or prompt.
 *
 * `#[Description]`, `#[Name]`, `#[Uri]` and the rest are read by the server when it lists the
 * primitive or calls it. The server reaches the primitive through `handle()`
 * ({@see McpServerTracer}), so an edit to that metadata changes what `handle()`'s callers get and
 * nothing else. Pinning it there keeps the server, its route and the tests behind them in the
 * selection, where a coarse class seed would withdraw the selection at low confidence.
 *
 * @internal
 */
final class McpMetadataChange
{
    private const string ATTRIBUTES = 'Laravel\\Mcp\\Server\\Attributes\\';

    private const array PRIMITIVES = ['Laravel\\Mcp\\Server\\Tool', 'Laravel\\Mcp\\Server\\Resource', 'Laravel\\Mcp\\Server\\Prompt'];

    /**
     * The changed members with `handle()` among them, standing for the class-level edit. Null when
     * anything else at class level changed too, or the class is not a primitive that declares its
     * own `handle()`.
     *
     * The declarations are compared as code, with the metadata attributes and the members taken
     * out. Members are diffed on their own, so only the class header and its non-member statements,
     * such as a trait `use`, are left to differ.
     *
     * @param  list<MemberChange>  $members
     * @return list<MemberChange>|null
     */
    public static function pinnedTo(array $members, string $headSrc, string $baseSrc): ?array
    {
        $head = self::declaration($headSrc);
        $base = self::declaration($baseSrc);

        if (! $head instanceof Class_ || ! $base instanceof Class_ || ! $head->getMethod('handle') instanceof ClassMethod || ! self::isPrimitive($head)) {
            return null;
        }

        $printer = new Standard();

        if ($printer->prettyPrint([self::header($head)]) !== $printer->prettyPrint([self::header($base)])) {
            return null;
        }

        $listed = array_any($members, static fn (MemberChange $member): bool => $member->kind === MemberChange::KIND_METHOD && strtolower($member->name) === 'handle');

        return $listed ? $members : [...$members, new MemberChange('handle', MemberChange::KIND_METHOD, MemberChange::CHANGE_MODIFIED, resolvable: true)];
    }

    /** The one class-like the source declares, or null for none, several, or a parse failure. */
    private static function declaration(string $source): ?ClassLike
    {
        $classes = new NodeFinder()->findInstanceOf(AppFiles::parseResolved($source) ?? [], ClassLike::class);

        return count($classes) === 1 ? $classes[0] : null;
    }

    /**
     * The parent decides it. A class that cannot be loaded cannot be shown to be a primitive, so
     * only a direct `laravel/mcp` parent counts then.
     */
    private static function isPrimitive(Class_ $class): bool
    {
        if (! $class->extends instanceof Name) {
            return false;
        }

        $parent = AppFiles::resolveName($class->extends);

        return in_array($parent, self::PRIMITIVES, strict: true)
            || (LoadableClass::exists($parent) && is_subclass_of($parent, McpServerTracer::PRIMITIVE));
    }

    /** The declaration without its comments, its members, and the attribute groups that hold only metadata. */
    private static function header(Class_ $class): Class_
    {
        $header = clone $class;
        $header->setAttribute('comments', []);
        $header->attrGroups = array_values(array_filter($class->attrGroups, static fn (AttributeGroup $group): bool => ! self::isMetadata($group)));
        $header->stmts = array_values(array_filter(
            $class->stmts,
            static fn (Stmt $stmt): bool => ! $stmt instanceof ClassMethod && ! $stmt instanceof Property && ! $stmt instanceof ClassConst && ! $stmt instanceof EnumCase,
        ));

        return $header;
    }

    /** Whether every attribute in the group is a `laravel/mcp` one, read by its resolved name. */
    private static function isMetadata(AttributeGroup $group): bool
    {
        foreach ($group->attrs as $attribute) {
            if (! str_starts_with(AppFiles::resolveName($attribute->name), self::ATTRIBUTES)) {
                return false;
            }
        }

        return $group->attrs !== [];
    }
}
