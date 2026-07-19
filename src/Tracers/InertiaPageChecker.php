<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tracers;

use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PhpParser\NodeFinder;
use SanderMuller\Richter\Support\AppFiles;
use SanderMuller\Richter\Support\RichterConfig;
use SanderMuller\Richter\Tracers\Concerns\ChecksChangedLineRanges;

/**
 * Notes the Inertia pages a changed source renders — the reverse direction of the frontend
 * bridge: a backend change to a member doing `Inertia::render('Videos/Show')` lands in
 * `resources/js/Pages/Videos/Show.vue`, a file no graph edge reaches. Same locality rule as
 * {@see FeatureGateChecker}: only renders IN the changed members are noted, string-literal
 * component names only. The page file resolves under `richter.frontend.pages_path` and is
 * existence-checked — a missing file is itself a signal (a deleted or renamed page).
 */
final readonly class InertiaPageChecker
{
    use ChecksChangedLineRanges;

    /** The extensions `resolvePageComponent` conventionally resolves, most common first. */
    private const array PAGE_EXTENSIONS = ['vue', 'tsx', 'jsx', 'ts', 'js', 'svelte'];

    /** @param  string|null  $projectRoot  null resolves to the host app's base path at scan time */
    public function __construct(private ?string $projectRoot = null) {}

    /**
     * @param  list<array{int, int}>|null  $lineRanges  restrict to renders whose call starts inside
     *   one of these [start, end] line spans — the CHANGED members — so an untouched sibling
     *   method's render never reads as part of the change; null scans the whole source
     * @return list<string>
     */
    public function findingsFor(string $source, ?array $lineRanges = null): array
    {
        $ast = AppFiles::parseResolved($source);

        if ($ast === null) {
            return [];
        }

        $pages = [];

        foreach (new NodeFinder()->findInstanceOf($ast, CallLike::class) as $call) {
            if ($lineRanges !== null && ! $this->withinRanges($call->getStartLine(), $lineRanges)) {
                continue;
            }

            $page = $this->renderedPage($call);

            if ($page !== null) {
                $pages[$page] = true;
            }
        }

        return $this->findings(array_keys($pages));
    }

    /**
     * The component a call renders: `Inertia::render('X')` (import-resolved facade, or the bare
     * name for unresolvable sources) or the `inertia('X')` helper. A dynamic component name is
     * silently not a finding — same rule as every source checker, literals only.
     */
    private function renderedPage(CallLike $call): ?string
    {
        if ($call instanceof FuncCall && $call->name instanceof Name && $call->name->toString() === 'inertia') {
            return $this->componentName($call->args[0]->value ?? null);
        }

        if (! $call instanceof StaticCall || ! $call->name instanceof Identifier || $call->name->toString() !== 'render' || ! $call->class instanceof Name) {
            return null;
        }

        $class = AppFiles::resolveName($call->class);

        if ($class !== 'Inertia\Inertia' && $class !== 'Inertia') {
            return null;
        }

        return $this->componentName($call->args[0]->value ?? null);
    }

    private function componentName(mixed $argument): ?string
    {
        return $argument instanceof String_ && $argument->value !== '' ? $argument->value : null;
    }

    /**
     * @param  list<string>  $pages
     * @return list<string>
     */
    private function findings(array $pages): array
    {
        sort($pages);

        return array_map(function (string $page): string {
            $file = $this->pageFile($page);

            return $file !== null
                ? "renders Inertia page '{$page}' ({$file}) — that page is part of this change's surface"
                : "renders Inertia page '{$page}' — no page file found under " . RichterConfig::frontendPagesPath();
        }, $pages);
    }

    private function pageFile(string $page): ?string
    {
        $root = $this->projectRoot ?? base_path();
        $pagesPath = trim(RichterConfig::frontendPagesPath(), '/');

        foreach (self::PAGE_EXTENSIONS as $extension) {
            $relative = "{$pagesPath}/{$page}.{$extension}";

            if (is_file("{$root}/{$relative}")) {
                return $relative;
            }
        }

        return null;
    }
}
