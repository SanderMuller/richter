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
        public bool $assertionWeak,
    ) {}

    /**
     * One row per entry point, sorted by plain label — deliberately, so text and markdown agree.
     * The formatters previously each sorted their own decorated label; for a label that is a
     * prefix of another (`…/posts` vs `…/posts/{post}`), markdown's closing backtick used to
     * invert that pair, so plain-label order is a small intended change there, not an oversight.
     *
     * @param  list<string>  $entryPoints
     * @param  array<string, list<array{node: string, via: string, file?: string, line?: int}>>  $paths  keyed by entry-point node; empty when not explaining
     * @param  array<string, array{file: string, line?: int}>  $locations  keyed by entry-point node
     * @param  array<string, SecurityShape>  $security  keyed by entry-point node; routes only
     * @param  array<string, list<string>>  $gates  keyed by entry-point node
     * @param  array<string, list<string>>  $authGates  keyed by entry-point node; contradicting policy gates
     * @param  array<string, list<string>>  $authMiddleware  keyed by entry-point node; contradicting auth middleware
     * @return list<self>
     */
    public static function build(array $entryPoints, array $paths, array $locations, array $security, array $gates, array $authGates, array $authMiddleware, ?TestReferenceIndex $tests): array
    {
        $rows = array_map(static fn (string $node): self => new self(
            node: $node,
            label: NodeLabel::display($node),
            path: $paths[$node] ?? [],
            location: $locations[$node] ?? null,
            testReferenced: $tests?->hasReference($node),
            security: $security[$node] ?? null,
            gates: $gates[$node] ?? [],
            authGates: $authGates[$node] ?? [],
            authMiddleware: $authMiddleware[$node] ?? [],
            assertionWeak: $tests?->referencedWithoutBehaviouralAssertion($node) ?? false,
        ), $entryPoints);

        usort($rows, static fn (self $a, self $b): int => $a->label <=> $b->label);

        return $rows;
    }
}
