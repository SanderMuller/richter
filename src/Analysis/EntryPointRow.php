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
final class EntryPointRow
{
    /**
     * @param  list<array{node: string, via: string, file?: string, line?: int}>  $path  the explain chain from this entry point down to the changed symbol; empty when not explaining or when this entry point has no path (a self-listed entry class)
     * @param  array{file: string, line?: int}|null  $location  this entry point's defining location, when known
     * @param  bool|null  $testReferenced  {@see TestReferenceIndex::hasReference()}'s tri-state: null means "couldn't check", never rendered as unreferenced
     * @param  SecurityShape|null  $security  Brain's exposure/issues annotation; routes only
     * @param  list<string>  $gates  Pennant flags gating this route; empty when ungated
     */
    private function __construct(
        public readonly string $node,
        public readonly string $label,
        public readonly array $path,
        public readonly ?array $location,
        public readonly ?bool $testReferenced,
        public readonly ?array $security,
        public readonly array $gates,
    ) {}

    /**
     * One row per entry point, sorted by plain label. Both formatters previously sorted their own
     * decorated label instead; since decoration is always appended after the label, sorting the
     * plain label first yields the same order (a node name is never a prefix of another node's
     * decorated label).
     *
     * @param  list<string>  $entryPoints
     * @param  array<string, list<array{node: string, via: string, file?: string, line?: int}>>  $paths  keyed by entry-point node; empty when not explaining
     * @param  array<string, array{file: string, line?: int}>  $locations  keyed by entry-point node
     * @param  array<string, SecurityShape>  $security  keyed by entry-point node; routes only
     * @param  array<string, list<string>>  $gates  keyed by entry-point node
     * @return list<self>
     */
    public static function build(array $entryPoints, array $paths, array $locations, array $security, array $gates, ?TestReferenceIndex $tests): array
    {
        $rows = array_map(static fn (string $node): self => new self(
            node: $node,
            label: self::entryLabel($node),
            path: $paths[$node] ?? [],
            location: $locations[$node] ?? null,
            testReferenced: $tests?->hasReference($node),
            security: $security[$node] ?? null,
            gates: $gates[$node] ?? [],
        ), $entryPoints);

        usort($rows, static fn (self $a, self $b): int => $a->label <=> $b->label);

        return $rows;
    }

    /**
     * A console-command entry-point node carries its whole `$signature`
     * (`command::foo {--opt : desc}`); show just the command name. Routes/schedules are unaffected.
     */
    private static function entryLabel(string $node): string
    {
        return str_starts_with($node, 'command::') ? explode(' ', $node, 2)[0] : $node;
    }
}
