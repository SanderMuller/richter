<?php declare(strict_types=1);

namespace SanderMuller\Richter\Graph;

use LaraMint\LaravelBrain\Graph\Graph as BrainGraph;

/**
 * A completed build: richter's merged graph, plus the Brain graph it was merged from and how that
 * one was obtained.
 *
 * {@see CodeGraphBuilder::build()} returns only the merged graph, which is all any report needs.
 * The cache needs one thing more — Brain's own graph, to serve as the merge base for a later scoped
 * rebuild — so the detailed entry point hands both back rather than widening the return type every
 * existing caller depends on.
 *
 * `$path` is the honesty field. Without it a scoped build that fell back looks exactly like one that
 * never tried, and "did it engage?" is the first question anyone asks of this feature.
 */
final readonly class BuiltGraph
{
    /** @param  'full'|'scoped'|'scoped-rejected'  $path */
    public function __construct(
        public CodeGraph $graph,
        public BrainGraph $brainGraph,
        public string $path = 'full',
        public int $scopedFileCount = 0,
    ) {}
}
