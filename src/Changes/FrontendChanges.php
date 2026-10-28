<?php declare(strict_types=1);

namespace SanderMuller\Richter\Changes;

use Illuminate\Support\Str;
use SanderMuller\Richter\Analysis\SentFields;
use SanderMuller\Richter\Analysis\TestReferenceIndex;
use SanderMuller\Richter\Support\RichterConfig;
use SanderMuller\Richter\Tracers\FrontendReferenceScanner;

/**
 * Turns a changed frontend file into a {@see ChangedFileSymbols} whose direct seeds are the
 * `route::` node ids of every backend endpoint it references. All reference kinds land uniformly
 * as route nodes: a Wayfinder action import resolves through the router's action index (the route
 * knows its controller), a Wayfinder route import or Ziggy call through the name index. The
 * asymmetry between the two is deliberate — an `actions/App/…` import is unambiguously Wayfinder,
 * so an unmatched one flips the unresolved flag; a `routes/…` import or `route('name')` call
 * collides with frontend-router conventions, so an unmatched name silently isn't a reference,
 * never a guess.
 */
final class FrontendChanges
{
    private const array EXTENSIONS = ['ts', 'tsx', 'js', 'jsx', 'vue'];

    private readonly FrontendReferenceScanner $scanner;

    private ?RouterRouteIndex $routerIndex = null;

    private bool $routerIndexBuilt = false;

    public function __construct()
    {
        $this->scanner = new FrontendReferenceScanner(RichterConfig::frontendHttpCallees());
    }

    /** Whether the file is a scannable frontend source: bridge on, under a configured root, a frontend extension, not a `.d.ts` declaration file, and not generated regeneration churn (a directory, exact file, or `*`-glob). */
    public function handles(string $file): bool
    {
        return $this->rootFor($file) !== null && ! $this->isDeliberatelyIgnored($file);
    }

    /**
     * Whether the file is frontend source this configuration deliberately declines to scan — a
     * `.d.ts` declaration, or generated regeneration churn under `frontend.generated_paths`.
     *
     * Distinct from "not frontend at all", and the distinction is the whole reason this is public:
     * a caller that reports which changed files went unanalysed must stay silent about the ones the
     * user configured away, or the note it prints is loudest exactly where it was asked to be quiet.
     */
    public function isDeliberatelyIgnored(string $file): bool
    {
        $root = $this->rootFor($file);

        if ($root === null) {
            return false;
        }

        // A `.d.ts` reports extension `ts` via pathinfo(), but carries types only — no executable
        // endpoint calls — so scanning it is pure false-positive surface (route-name string-
        // literal-union types); a determined "no impact" for a type-only change is honest, not
        // under-reporting.
        if (str_ends_with(strtolower($file), '.d.ts')) {
            return true;
        }

        return ! array_all(RichterConfig::frontendGeneratedPaths(), static function (string $generated) use ($file, $root): bool {
            $entry = trim($generated, '/');

            // Directory-prefix semantics (the original contract), plus exact files and
            // `*`-globs via Str::is — a generated file directly under a root (ziggy.js) was
            // inexpressible before. Str::is with no wildcard is exact equality; its `*`
            // crosses `/`, so `*.generated.ts` matches at any depth.
            return ! str_starts_with($file, "{$root}/{$entry}/") && ! Str::is("{$root}/{$entry}", $file);
        });
    }

    /** The configured frontend root a file with a scannable extension sits under, or null. */
    private function rootFor(string $file): ?string
    {
        $roots = RichterConfig::frontendRoots();

        if ($roots === [] || ! in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), self::EXTENSIONS, strict: true)) {
            return null;
        }

        foreach ($roots as $root) {
            $root = trim($root, '/');

            if ($root !== '' && str_starts_with($file, $root . '/')) {
                return $root;
            }
        }

        return null;
    }

    /**
     * References are the union of both sides of the diff — a removed endpoint call is still part
     * of what the change touches. Null sources scan as empty (a deleted file has no head side).
     */
    public function resolve(string $file, ?string $headSrc, ?string $baseSrc): ChangedFileSymbols
    {
        $head = $this->scanner->scan($headSrc ?? '');
        $base = $this->scanner->scan($baseSrc ?? '');

        $actions = [...$head['actions'], ...$base['actions']];
        $routeNames = array_values(array_unique([...$head['routeNames'], ...$base['routeNames']]));
        $uris = [];

        foreach ([...$head['uris'], ...$base['uris']] as $literal) {
            $uris[$literal['uri'] . '|' . ($literal['method'] ?? '*')] = $literal;
        }

        $unresolved = $head['unresolved'] || $base['unresolved'];
        $findings = $unresolved ? ['a dynamic route() argument prevents resolving every referenced endpoint'] : [];

        if ($actions === [] && $routeNames === [] && $uris === []) {
            return new ChangedFileSymbols($file, '', [], cosmeticOnly: false, findings: $findings, unresolvedFrontendReferences: $unresolved);
        }

        $indexes = $this->indexes();

        if ($indexes === null) {
            return new ChangedFileSymbols($file, '', [], cosmeticOnly: false, findings: [...$findings, 'the router is unavailable — endpoint references could not be checked'], unresolvedFrontendReferences: true);
        }

        $seeds = [];

        foreach ($actions as $action) {
            $key = $action['method'] === null ? null : "{$action['class']}::{$action['method']}";
            $nodes = $key === null ? ($indexes->byClass[$action['class']] ?? null) : ($indexes->byAction[$key] ?? null);

            if ($nodes === null) {
                $unresolved = true;
                $findings[] = 'Wayfinder import references ' . ($key ?? $action['class']) . ' which matches no registered route';

                continue;
            }

            $seeds = [...$seeds, ...$nodes];
        }

        foreach ($routeNames as $name) {
            $seeds = [...$seeds, ...$indexes->byName[$name] ?? []];
        }

        $seeds = [...$seeds, ...$this->seedsForUris(array_values($uris), $indexes->uriTemplates)];

        return new ChangedFileSymbols($file, '', [], cosmeticOnly: false, directSeeds: array_values(array_unique($seeds)), findings: $findings, unresolvedFrontendReferences: $unresolved, droppedSentFields: $this->droppedSentFields($headSrc, $baseSrc));
    }

    /** @return list<string> */
    private function droppedSentFields(?string $headSrc, ?string $baseSrc): array
    {
        if ($headSrc === null || $baseSrc === null || SentFields::opaque($headSrc)) {
            return [];
        }

        return array_values(array_diff(SentFields::in($baseSrc), SentFields::in($headSrc)));
    }

    /**
     * The route nodes referenced by URI literals in inline `<script>` blocks — the surface a
     * changed Blade view's `fetch('/api/…')` touches. Script slices only: markup hrefs, form
     * actions and Blade's `route()` helper are navigation/link *generation* (every layout renders
     * dozens), not endpoint calls — scanning them would register every linked route as touched.
     * Alpine attribute handlers are a known miss. Annotation-grade: an unavailable router yields
     * nothing rather than blocking anything.
     *
     * @return list<string>
     */
    public function inlineUriSeeds(?string $headSrc, ?string $baseSrc): array
    {
        $uris = [
            ...$this->scanner->scan($this->scriptSlices($headSrc ?? ''))['uris'],
            ...$this->scanner->scan($this->scriptSlices($baseSrc ?? ''))['uris'],
        ];

        if ($uris === []) {
            return [];
        }

        $indexes = $this->indexes();

        if ($indexes === null) {
            return [];
        }

        return array_values(array_unique($this->seedsForUris($uris, $indexes->uriTemplates)));
    }

    /**
     * The concatenated contents of every inline `<script>` block — the only slice of a
     * Blade view that carries endpoint *calls* rather than link generation. Public: the
     * consumer index reduces whole views through the same extraction.
     */
    public function scriptSlices(string $source): string
    {
        preg_match_all('/<script\b[^>]*>(.*?)<\/script>/is', $source, $matches);

        return implode("\n", $matches[1]);
    }

    /**
     * Every route node one frontend source references, across all three reference kinds — the
     * building block for indexing frontend TEST files, where a missed reference only means a test
     * isn't suggested (nothing to flag unresolved).
     *
     * @return list<string>
     */
    public function routeNodesIn(string $source): array
    {
        $scan = $this->scanner->scan($source);

        if ($scan['actions'] === [] && $scan['routeNames'] === [] && $scan['uris'] === []) {
            return [];
        }

        $indexes = $this->indexes();

        if ($indexes === null) {
            return [];
        }

        $seeds = [];

        foreach ($scan['actions'] as $action) {
            $seeds = [
                ...$seeds,
                ...$action['method'] === null
                    ? $indexes->byClass[$action['class']] ?? []
                    : $indexes->byAction["{$action['class']}::{$action['method']}"] ?? [],
            ];
        }

        foreach ($scan['routeNames'] as $name) {
            $seeds = [...$seeds, ...$indexes->byName[$name] ?? []];
        }

        return array_values(array_unique([...$seeds, ...$this->seedsForUris($scan['uris'], $indexes->uriTemplates)]));
    }

    /**
     * The booted router's route index. Null when no router is booted; the
     * caller then degrades to "couldn't check", never to "touches nothing" ({@see TestReferenceIndex}
     * makes the same tri-state call for the same reason).
     */
    private function indexes(): ?RouterRouteIndex
    {
        if (! $this->routerIndexBuilt) {
            $this->routerIndex = RouterRouteIndex::fromRouter();
            $this->routerIndexBuilt = true;
        }

        return $this->routerIndex;
    }

    /**
     * A URI literal is a weak candidate (any root-relative string matches the shape) — the route
     * templates are the filter, and a non-endpoint literal simply matches none of them. A pinned
     * verb then scopes the match to its method's nodes — across every matched template, since GET
     * and POST on one path can be separate registrations. When the verb is served by none of
     * them, the path match stays whole: the tests referencing that URI are method-agnostic, and
     * dropping them would under-select.
     *
     * @param  list<array{uri: string, method: string|null}>  $uris
     * @param  list<array{regex: string, nodes: list<string>}>  $templates
     * @return list<string>
     */
    private function seedsForUris(array $uris, array $templates): array
    {
        $seeds = [];

        foreach ($uris as $literal) {
            $matched = [];

            foreach ($templates as $template) {
                if (preg_match($template['regex'], $literal['uri']) === 1) {
                    $matched = [...$matched, ...$template['nodes']];
                }
            }

            if ($literal['method'] !== null) {
                $verbScoped = array_values(array_filter(
                    $matched,
                    static fn (string $node): bool => str_starts_with($node, 'route::' . strtoupper((string) $literal['method']) . '::'),
                ));
                $matched = $verbScoped === [] ? $matched : $verbScoped;
            }

            $seeds = [...$seeds, ...$matched];
        }

        return $seeds;
    }
}
