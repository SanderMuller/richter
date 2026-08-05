<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

use SanderMuller\Richter\Changes\ChangedFileSymbols;
use SanderMuller\Richter\Graph\CodeGraph;
use SanderMuller\Richter\Support\RichterConfig;

/**
 * The payload-parity findings family's dispatch, beside {@see ImpactAnalyzer} (complexity
 * budget): one gate (`payload_parity.enabled` / `--no-payload-parity`) constructs both
 * checkers, and one per-file call fans out to whichever lane the file triggers — added
 * model fields to the model→resource lane, removed resource keys to the frontend-consumer
 * lane. Findings only, in both directions.
 */
final readonly class ParityFindings
{
    /**
     * @param  bool|null  $enabledOverride  the command's `--no-payload-parity`; null defers to config
     * @return array{0: PayloadParityChecker|null, 1: FrontendConsumerParityChecker|null}
     */
    public static function checkers(CodeGraph $graph, ?bool $enabledOverride): array
    {
        if (! ($enabledOverride ?? RichterConfig::payloadParityEnabled())) {
            return [null, null];
        }

        $ignore = RichterConfig::payloadParityIgnore();

        return [
            new PayloadParityChecker($graph, RichterConfig::payloadParityMirrorThreshold(), $ignore),
            new FrontendConsumerParityChecker($graph, $ignore),
        ];
    }

    /**
     * No file prefix on these — unlike the source-checker findings, each names the
     * OTHER side of the parity pair (the resource a model change affects, the consumer
     * file a resource change strands), not the changed file itself.
     *
     * @return list<string>
     */
    public static function for(ChangedFileSymbols $file, ?PayloadParityChecker $modelLane, ?FrontendConsumerParityChecker $consumerLane): array
    {
        return [
            ...(! $modelLane instanceof PayloadParityChecker || $file->addedModelFields === [] ? [] : $modelLane->findingsFor($file->fqcn, $file->modelFieldSet, $file->addedModelFields)),
            ...(! $consumerLane instanceof FrontendConsumerParityChecker || $file->removedResourceKeys === [] ? [] : $consumerLane->findingsFor($file->fqcn, $file->removedResourceKeys, $file->addedResourceKeys)),
        ];
    }
}
