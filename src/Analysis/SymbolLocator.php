<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

use InvalidArgumentException;
use SanderMuller\Richter\Graph\CodeGraph;
use SanderMuller\Richter\Graph\NodeMetadata;
use SanderMuller\Richter\Graph\NodeNormalizer;
use SanderMuller\Richter\Support\ScopedRebuild;

/**
 * Where a symbol or a file is, and nothing else. The orientation step that precedes
 * {@see ImpactAnalyzer::impact()} and {@see SymbolTracer}: both need an exact node id, and a caller
 * who does not have one has to guess it or pay for a blast-radius walk to discover it.
 *
 * Two lanes, one document shape. The symbol lane reads {@see CodeGraph::nodesContaining()}; the file
 * lane reads {@see CodeGraph::nodesDefinedIn()}. Neither walks an edge, so the cost is the graph
 * load the caller has already paid for.
 *
 * A miss is DATA here, not an error — unlike {@see SymbolTracer}, which raises one because an empty
 * trace would read as "no path". "Nothing named X; nearest are Y and Z" is a real answer to "where
 * is X", so it comes back as a result carrying its own lead.
 *
 * @phpstan-type LocateMatch array{node: string, kind?: string, file?: string, line?: int}
 * @phpstan-type LocateResult array{query: string, by: 'symbol'|'file', total: int, limit?: int, bounded: bool, matches: list<LocateMatch>, suggestions?: list<string>, graphNodeCount?: int, graphFileCount?: int}
 */
final readonly class SymbolLocator
{
    /**
     * The node-id prefixes richter's own `src/` matches against. Laravel Brain owns the vocabulary
     * and may mint others; those stay unlabelled rather than guessed at.
     *
     * @internal deliberately an under-approximation. A prefix missing here costs one absent `kind`
     *   and nothing else, because nothing branches on `kind` — so this list needs no synchronisation
     *   process, and staleness degrades to silence rather than to a wrong label.
     */
    private const array KNOWN_PREFIXES = [
        'action', 'command', 'controller', 'middleware', 'model', 'route', 'schedule', 'view',
    ];

    public function __construct(private CodeGraph $graph) {}

    /**
     * @param  int|null  $limit  cap the match list; null returns every match
     * @return LocateResult
     */
    public function locateSymbol(string $symbol, ?int $limit = null): array
    {
        $query = $this->require($symbol, 'symbol');

        // Every existing resolution site drops the leading separator the same way
        // ({@see SymbolTracer::resolveOrFail()}); an FQCN pasted from a `use` statement carries one.
        $nodes = $this->graph->nodesContaining(ltrim($query, '\\'));

        return $this->document($query, 'symbol', $nodes, $limit, $nodes === [] ? $this->symbolLead($query) : []);
    }

    /**
     * @param  int|null  $limit  cap the match list; null returns every match
     * @return LocateResult
     */
    public function locateFile(string $file, ?int $limit = null): array
    {
        $query = $this->require($file, 'file');
        $nodes = $this->nodesForFile($query);

        return $this->document($query, 'file', $nodes, $limit, $nodes === [] ? $this->fileLead($query) : []);
    }

    /**
     * The nodes a file defines, probing the input EXACTLY before normalising anything.
     *
     * That order is the whole point. {@see NodeMetadata::relativeFile()}
     * strips a project-root prefix and otherwise keeps a path verbatim, so the graph holds an
     * absolute key whenever the root was empty or the file sat outside it. Normalising first would
     * rewrite such an input into a relative form the graph does not hold, turning a hit into a miss;
     * probing as-passed first cannot, and costs one lookup into an index already built.
     *
     * @return list<string>
     */
    private function nodesForFile(string $file): array
    {
        $nodes = $this->graph->nodesDefinedIn($file);

        if ($nodes !== []) {
            return $nodes;
        }

        $candidate = $this->normalisePath($file);

        return $candidate === $file ? [] : $this->graph->nodesDefinedIn($candidate);
    }

    /**
     * The narrow, deliberate grammar: a leading `./` and an absolute project-root prefix, nothing
     * more. Repeated separators, `..` segments and backslash separators are left alone and miss.
     *
     * `realpath()` is refused on purpose — {@see ScopedRebuild} records
     * what inconsistent path resolution costs: a `/private/var` against a `/var`, or a symlinked
     * root, makes a present file look absent, and that miss is indistinguishable from a real one.
     */
    private function normalisePath(string $file): string
    {
        // Same strip, same reason, as BladeViews::viewNameFromPath().
        $candidate = str_starts_with($file, './') ? substr($file, 2) : $file;
        $root = base_path();

        return str_starts_with($candidate, $root . '/') ? substr($candidate, strlen($root) + 1) : $candidate;
    }

    /**
     * The one document builder, hit and miss alike. A miss is the same shape with an empty match
     * list and a lead beside it, which is what makes "nothing found" a result rather than a special
     * case the surfaces have to know about.
     *
     * @param  'symbol'|'file'  $by
     * @param  list<string>  $nodes
     * @param  array{suggestions?: list<string>, graphNodeCount?: int, graphFileCount?: int}  $lead
     * @return LocateResult
     */
    private function document(string $query, string $by, array $nodes, ?int $limit, array $lead): array
    {
        // Sorted BEFORE the cap: nodesContaining() returns token-index order, which follows build
        // order, so an unsorted slice would make the visible page depend on how the graph was built.
        // nodesDefinedIn() already sorts for the same stated reason.
        sort($nodes);

        $shown = $limit === null ? $nodes : array_slice($nodes, 0, $limit);

        return [
            'query' => $query,
            'by' => $by,
            'total' => count($nodes),
            // Sparse like the rest of the document: no cap applied, no `limit` key. A document
            // without one and with `bounded: false` is complete by construction.
            ...($limit === null ? [] : ['limit' => $limit]),
            'bounded' => count($shown) < count($nodes),
            'matches' => array_map($this->match(...), $shown),
            ...$lead,
        ];
    }

    /**
     * The symbol lane's lead, on the same either/or {@see ImpactFormatter::missDiagnostic()} renders:
     * nearest node ids, or — when nothing in the graph even resembles the symbol — the count that
     * was scanned, which separates "wrong name" from "the graph is empty".
     *
     * @return array{suggestions: list<string>}|array{graphNodeCount: int}
     */
    private function symbolLead(string $symbol): array
    {
        $suggestions = $this->graph->nearestNodes($symbol);

        return $suggestions === []
            ? ['graphNodeCount' => $this->graph->nodeCount()]
            : ['suggestions' => $suggestions];
    }

    /**
     * The file lane's lead, on the same either/or the symbol lane uses.
     *
     * First choice is one known path sharing the queried basename, modelled on
     * {@see ScopedRebuild}'s provenance detail, which exists for this exact
     * failure — two path forms differing only by prefix look identical to the file being absent.
     *
     * Failing that, the count of files the graph can answer for. That is the file lane's
     * denominator, and it separates "wrong path" from "the graph pins no files at all" — the same
     * distinction {@see CodeGraph::nodeCount()} draws for a symbol. Without it a structured consumer
     * reads a file miss as a bare empty list, while only the prose reader is told anything.
     *
     * `graphNodeCount` is deliberately NOT used: a node count answers nothing about a path.
     * {@see CodeGraph::nearestNodes()} is not used either — it tokenises node ids.
     *
     * @return array{suggestions: list<string>}|array{graphFileCount: int}
     */
    private function fileLead(string $file): array
    {
        $basename = basename($this->normalisePath($file));
        $known = $this->graph->definedFiles();

        foreach ($known as $candidate) {
            if (basename($candidate) === $basename) {
                return ['suggestions' => [$candidate]];
            }
        }

        return ['graphFileCount' => count($known)];
    }

    /** @return LocateMatch */
    private function match(string $node): array
    {
        $entry = ['node' => $node];
        $kind = $this->kindOf($node);

        if ($kind !== null) {
            $entry['kind'] = $kind;
        }

        // Sparse, exactly as locationOf() is: an unknown line is an absent key, never a null.
        return $entry + ($this->graph->locationOf($node) ?? []);
    }

    /**
     * What a node id addresses — reported only when it is knowable.
     *
     * The tempting rule, "a first `::` segment with no backslash is a vocabulary prefix", is wrong
     * for a global-namespace class: it labels `A::m` as kind `A`. And a `::`-free id is not safely a
     * class either, because {@see NodeNormalizer} keeps Brain's id
     * verbatim for routes, middleware, and short names it could not resolve. So each branch needs
     * proof, and an unprovable id gets no label rather than a wrong one.
     */
    private function kindOf(string $node): ?string
    {
        if (! str_contains($node, '::')) {
            return str_contains($node, '\\') ? 'class' : null;
        }

        $head = explode('::', $node, 2)[0];

        if (str_contains($head, '\\')) {
            return 'member';
        }

        return in_array($head, self::KNOWN_PREFIXES, strict: true) ? $head : null;
    }

    /**
     * The defensive half of the input contract. Both surfaces reject an empty argument before they
     * build a graph, so this is unreachable in practice — but an empty needle reaching the graph
     * would render as a legitimate miss ({@see CodeGraph::nodesContaining()} returns `[]` for `''`,
     * and a whitespace-only needle misses through the regex instead), which is the one answer this
     * must never give.
     */
    private function require(string $value, string $argument): string
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            throw new InvalidArgumentException("The {$argument} argument must not be empty.");
        }

        return $trimmed;
    }
}
