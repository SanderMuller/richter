<?php declare(strict_types=1);

namespace SanderMuller\Richter\Support;

use SanderMuller\Richter\Analysis\ImpactFormatter;

/**
 * The tail a report prints under its entry-point list when a keep set folded surfaces away.
 *
 * One place, three formats. The rows themselves render differently in text, markdown and HTML, but
 * WHAT the tail says — how many surfaces, reached through which hub, and that this is context rather
 * than work — is one decision, and a second copy of it would drift the way the entry-point ordering
 * drifted between the reports and the payload.
 *
 * The wording follows {@see ImpactFormatter}'s association fold rather
 * than a bare "and N more": that fold names the shared CAUSE, because a count without one tells a
 * reader to go looking. It also drops the "… and" opener where a capped list has already written one,
 * for the same reason it does there — the phrase only reads directly after a list.
 *
 * @internal
 */
final readonly class HubFold
{
    /**
     * How many surfaces each hub folded away, keyed by the hub's path and sorted by it so two runs of
     * one diff print the same tail.
     *
     * @param  list<string>  $entryPoints  every reached surface
     * @param  list<string>  $kept  the surfaces the keep set kept
     * @param  array<string, array{via: string, ownReach: int}>  $attribution
     * @return array<string, int> hub path => count
     */
    public static function counts(array $entryPoints, array $kept, array $attribution): array
    {
        $keptSet = array_fill_keys($kept, true);
        $counts = [];

        foreach ($entryPoints as $entryPoint) {
            if (isset($keptSet[$entryPoint])) {
                continue;
            }

            $via = $attribution[$entryPoint]['via'] ?? null;

            if (is_string($via) && $via !== '') {
                $counts[$via] = ($counts[$via] ?? 0) + 1;
            }
        }

        ksort($counts);

        return $counts;
    }

    /**
     * The tail as plain sentences, one per hub, without any format's own bullet or markup.
     *
     * @param  array<string, int>  $counts  from {@see counts()}
     * @param  bool  $afterCappedList  whether the inline list already printed its own "… and N more"
     * @return list<string>
     */
    public static function sentences(array $counts, bool $afterCappedList): array
    {
        $sentences = [];
        $first = true;

        foreach ($counts as $path => $count) {
            $sentences[] = sprintf(
                '%s%d %s reached only through %s, which this project lists as a hub — context, not surfaces this change forgot',
                $first && ! $afterCappedList ? '… and ' : '',
                $count,
                $count === 1 ? 'surface' : 'surfaces',
                $path,
            );
            $first = false;
        }

        return $sentences;
    }
}
