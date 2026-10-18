<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Support;

use SanderMuller\Richter\Analysis\EntryPointAttribution;
use SanderMuller\Richter\Analysis\ImpactAnalyzer;

/**
 * A `detectChanges()` result for a formatter or presenter test: {@see ImpactAnalyzer::emptyDetectChanges()}
 * with the test's own keys on top.
 *
 * Hand-built results left out keys production always sets. Every one omitted `entryPointKeepSet`, so
 * the formatters and the presenter only ever ran the fallback for a missing keep set — a path the
 * analyzer never takes. Unless a test passes its own, the keep set here is every entry point, in the
 * reading order the analyzer gives it.
 *
 * @phpstan-import-type CompleteDetectChangesResult from ImpactAnalyzer
 *
 * @internal
 */
final class DetectChangesFixture
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return CompleteDetectChangesResult
     */
    public static function with(array $overrides = []): array
    {
        /** @var CompleteDetectChangesResult $result */
        $result = [...ImpactAnalyzer::emptyDetectChanges(), ...$overrides];

        if (! array_key_exists('entryPointKeepSet', $overrides)) {
            $result['entryPointKeepSet'] = [
                'kept' => EntryPointAttribution::order($result['entryPoints'], $result['entryPointAttribution']),
                'droppedHub' => 0,
            ];
        }

        return $result;
    }
}
