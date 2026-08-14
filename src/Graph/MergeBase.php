<?php declare(strict_types=1);

namespace SanderMuller\Richter\Graph;

use LaraMint\LaravelBrain\Analysis\Incremental\GraphProvenance;
use LaraMint\LaravelBrain\Graph\Graph as BrainGraph;

/**
 * The previous Brain graph a scoped rebuild can build onto, or why there is none.
 *
 * Five things can leave a run without a merge base, and they are not one fact: no cache entry yet is
 * the normal first run, an entry written before the feature existed is a one-time upgrade cost, and a
 * stored graph the codec refuses is a bug that repeats on every run forever. Returning `null` for all
 * five made those indistinguishable from outside — which is exactly the state a consumer report of
 * "the scoped path never runs" left this feature in.
 */
final readonly class MergeBase
{
    /**
     * @param  array{nonFile: array<string, mixed>, files: array<string, string>}|null  $inputs
     */
    private function __construct(
        public ?BrainGraph $brainGraph = null,
        public ?array $inputs = null,
        public ?string $refusal = null,
        public ?string $detail = null,
    ) {}

    /**
     * @param  array{nonFile: array<string, mixed>, files: array<string, string>}  $inputs
     */
    public static function of(BrainGraph $brainGraph, array $inputs): self
    {
        return new self(brainGraph: $brainGraph, inputs: $inputs);
    }

    public static function refused(string $reason, ?string $detail = null): self
    {
        return new self(refusal: $reason, detail: $detail);
    }

    /**
     * The files the previous graph attributes nodes to, in the form its own provenance carries.
     *
     * @return array<string, true>
     */
    public function provenanceFiles(): array
    {
        return $this->brainGraph instanceof BrainGraph
            ? array_fill_keys(array_keys(GraphProvenance::of($this->brainGraph)->byFile), true)
            : [];
    }
}
