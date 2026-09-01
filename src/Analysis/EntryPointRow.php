<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

use SanderMuller\Richter\Graph\NodeMetadata;

/**
 * The facts one reached entry point carries — node, plain label, defining location, test-reference
 * tri-state, security exposure/issues, Pennant gates, and the explain-path chain — sorted and ready
 * for a formatter to decorate. {@see ImpactFormatter} and {@see MarkdownFormatter} previously each
 * ran their own copy of this traversal, differing only in how a row is drawn (brackets vs badges);
 * this class owns the one copy of the facts and their ordering, never the decoration.
 *
 * @internal
 * @phpstan-import-type SecurityShape from NodeMetadata
 */
final readonly class EntryPointRow
{
    /**
     * @param  list<array{node: string, via: string, file?: string, line?: int}>  $path  the explain chain from this entry point down to the changed symbol; empty when not explaining or when this entry point has no path (a self-listed entry class)
     * @param  array{file: string, line?: int}|null  $location  this entry point's defining location, when known
     * @param  bool|null  $testReferenced  {@see TestReferenceIndex::hasReference()}'s tri-state: null means "couldn't check", never rendered as unreferenced
     * @param  SecurityShape|null  $security  Brain's exposure/issues annotation; routes only
     * @param  list<string>  $gates  Pennant flags gating this route; empty when ungated
     * @param  list<string>  $authMiddleware  auth middleware applied to the route that Brain's
     *   name-based match missed (a subclass of a framework auth middleware) — evidence against a
     *   PUBLIC_WRITE finding, from {@see PublicWriteAuthCrossCheck::authMiddlewareByEntryPoint()}
     * @param  list<string>  $authGates  `App\Policies\*` classes richter's own `authorizes` edges show
     *   this route's reach gates on — evidence that contradicts a Brain `PUBLIC_WRITE` finding; empty
     *   unless the route carries a `PUBLIC_WRITE` issue and a gate is found in reach
     * @param  list<array{middleware: string, group: string|null}>  $runtimeGuards  guards the booted
     *   router proves on this route ({@see RuntimeRouterGuards}) — runtime evidence beside Brain's
     *   finding, with the named middleware group each guard arrived through (null = applied directly)
     * @param  bool  $assertionWeak  {@see TestReferenceIndex::referencedWithoutBehaviouralAssertion()}; false whenever it cannot be graded true, never a tri-state
     */
    private function __construct(
        public string $node,
        public string $label,
        public array $path,
        public ?array $location,
        public ?bool $testReferenced,
        public ?array $security,
        public array $gates,
        public array $authGates,
        public array $authMiddleware,
        public array $runtimeGuards,
        public bool $assertionWeak,
    ) {}

    /**
     * The runtime guards as plain display labels — `FQCN (via middleware group 'web')` /
     * `FQCN (applied directly)` — so each formatter only wraps and escapes, keeping the branchy
     * part in one place.
     *
     * @return list<string>
     */
    public function runtimeGuardLabels(): array
    {
        return array_map(
            static fn (array $guard): string => $guard['middleware']
                . ($guard['group'] === null ? ' (applied directly)' : " (via middleware group '{$guard['group']}')"),
            $this->runtimeGuards,
        );
    }

    /**
     * One row per entry point, in the order every formatter renders and cuts at its cap.
     *
     * With an ATTRIBUTION map the order is how specifically the diff explains each surface
     * ({@see EntryPointAttribution::order()}, shared with the machine payload) — the rows a reader
     * meets first are then the ones the change is about, rather than the ones whose names sort early.
     * Without one — every `richter:impact` call, which analyses a single symbol and has no per-file
     * attribution to make — the order is the plain label, exactly as before.
     *
     * Plain label, not the decorated one: the formatters previously each sorted their own decorated
     * label; for a label that is a prefix of another (`…/posts` vs `…/posts/{post}`), markdown's
     * closing backtick used to invert that pair.
     *
     * @param  list<string>  $entryPoints
     * @param  array<string, list<array{node: string, via: string, file?: string, line?: int}>>  $paths  keyed by entry-point node; empty when not explaining
     * @param  array<string, array{file: string, line?: int}>  $locations  keyed by entry-point node
     * @param  array<string, SecurityShape>  $security  keyed by entry-point node; routes only
     * @param  array<string, list<string>>  $gates  keyed by entry-point node
     * @param  array<string, list<string>>  $authGates  keyed by entry-point node; contradicting policy gates
     * @param  array<string, list<string>>  $authMiddleware  keyed by entry-point node; contradicting auth middleware
     * @param  array<string, array{via: string, ownReach: int}>  $attribution  keyed by entry-point node; empty when the caller has none to give
     * @param  array<string, list<array{middleware: string, group: string|null}>>  $runtimeGuards  keyed by entry-point node; runtime-proven guards
     * @return list<self>
     */
    public static function build(array $entryPoints, array $paths, array $locations, array $security, array $gates, array $authGates, array $authMiddleware, ?TestReferenceIndex $tests, array $attribution = [], array $runtimeGuards = []): array
    {
        return array_map(static fn (string $node): self => new self(
            node: $node,
            label: NodeLabel::display($node),
            path: $paths[$node] ?? [],
            location: $locations[$node] ?? null,
            testReferenced: $tests?->hasReference($node),
            security: $security[$node] ?? null,
            gates: $gates[$node] ?? [],
            authGates: $authGates[$node] ?? [],
            authMiddleware: $authMiddleware[$node] ?? [],
            runtimeGuards: $runtimeGuards[$node] ?? [],
            assertionWeak: $tests?->referencedWithoutBehaviouralAssertion($node) ?? false,
        ), EntryPointAttribution::order($entryPoints, $attribution));
    }
}
