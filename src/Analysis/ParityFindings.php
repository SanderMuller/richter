<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

use SanderMuller\Richter\Changes\ChangedFileSymbols;
use SanderMuller\Richter\Graph\CodeGraph;
use SanderMuller\Richter\Support\RichterConfig;

/**
 * The payload-parity findings family's dispatch, beside {@see ImpactAnalyzer} (complexity
 * budget): one gate (`payload_parity.enabled` / `--no-payload-parity`) constructs every
 * checker, and one per-file call fans out to whichever lane the file triggers — added
 * model fields to the model→resource lane, removed resource keys to the frontend-consumer
 * lane, removed `rules()` fields to the request lane.
 *
 * These are HAZARDS, at tier 2. A payload key a consumer still reads, a model field that never
 * reached the resource mirroring its siblings, a form-request field that stopped being validated —
 * each names something that BREAKS, which is what separates a hazard from a finding. The predicates
 * are unchanged and the `payload_parity` config keys still gate them; only the destination moved.
 */
final readonly class ParityFindings
{
    /**
     * @param  bool|null  $enabledOverride  the command's `--no-payload-parity`; null defers to config
     * @return array{0: PayloadParityChecker|null, 1: FrontendConsumerParityChecker|null, 2: RequestFieldParityChecker|null}
     */
    public static function checkers(CodeGraph $graph, ?bool $enabledOverride): array
    {
        if (! ($enabledOverride ?? RichterConfig::payloadParityEnabled())) {
            return [null, null, null];
        }

        $ignore = RichterConfig::payloadParityIgnore();
        // One lane for both consumer-facing checkers: it holds the frontend index and the per-file
        // scan contents, and a diff that removes both a resource key and a rules() field would
        // otherwise walk every frontend root and Blade view twice. Still lazy — a run where neither
        // lane fires builds no index at all.
        $lane = new FrontendConsumerLane($graph, $ignore);

        return [
            new PayloadParityChecker($graph, RichterConfig::payloadParityMirrorThreshold(), $ignore),
            new FrontendConsumerParityChecker($lane),
            new RequestFieldParityChecker($lane),
        ];
    }

    /**
     * No file prefix on these — each names the OTHER side of the parity pair (the resource a model
     * change affects, the consumer file a resource or form-request change strands) in its evidence,
     * while `member` anchors on the changed file itself. The reach lane needs something it can look
     * up in the graph; the evidence carries the rest.
     *
     * @return list<Hazard>
     */
    public static function for(ChangedFileSymbols $file, ?PayloadParityChecker $modelLane, ?FrontendConsumerParityChecker $consumerLane, ?RequestFieldParityChecker $requestLane): array
    {
        $hazards = [
            ...self::hazards($file->fqcn, ! $modelLane instanceof PayloadParityChecker || $file->addedModelFields === [] ? [] : $modelLane->findingsFor($file->fqcn, $file->modelFieldSet, $file->addedModelFields)),
            ...self::hazards($file->fqcn, ! $consumerLane instanceof FrontendConsumerParityChecker || $file->removedResourceKeys === [] ? [] : $consumerLane->findingsFor($file->fqcn, $file->removedResourceKeys, $file->addedResourceKeys)),
            ...self::hazards($file->fqcn, ! $requestLane instanceof RequestFieldParityChecker || $file->removedRequestFields === [] ? [] : $requestLane->findingsFor($file->fqcn, $file->removedRequestFields, $file->addedRequestFields)),
        ];

        foreach (self::inlineRequestFindingsByMember($file, $requestLane) as $member => $findings) {
            $hazards = [...$hazards, ...self::hazards($member, $findings)];
        }

        return $hazards;
    }

    /**
     * @param  list<string>  $findings
     * @return list<Hazard>
     */
    private static function hazards(string $member, array $findings): array
    {
        return array_map(
            static fn (string $finding): Hazard => new Hazard('parity', 2, null, $member, $finding),
            $findings,
        );
    }

    /**
     * Inline validation is anchored on the MEMBER that holds it, not the file: the routes upstream
     * of a controller action are what its payload comes from, and a sibling action in the same class
     * validates something else entirely. The parser hands over fully qualified member ids, so a file
     * declaring two classes anchors each on its own.
     *
     * @return array<string, list<string>>
     */
    private static function inlineRequestFindingsByMember(ChangedFileSymbols $file, ?RequestFieldParityChecker $requestLane): array
    {
        if (! $requestLane instanceof RequestFieldParityChecker) {
            return [];
        }

        $findings = [];

        foreach ($file->inlineRequestFields as $member => [$removed, $added]) {
            $memberFindings = $requestLane->findingsFor($member, $removed, $added);

            if ($memberFindings !== []) {
                $findings[$member] = $memberFindings;
            }
        }

        return $findings;
    }
}
