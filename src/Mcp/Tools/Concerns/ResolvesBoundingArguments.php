<?php declare(strict_types=1);

namespace SanderMuller\Richter\Mcp\Tools\Concerns;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use SanderMuller\Richter\Analysis\BoundedPresenter;

/**
 * Reads and validates the tier-2 drill-down arguments (`full`, `entries`) the bounded tools share.
 *
 * Validation runs BEFORE any other work in a tool's handle(): DetectChangesTool returns early on an
 * empty diff, and validation placed after that return would silently accept invalid input whenever
 * the diff is empty. The values arrive as `mixed` through {@see Request::get()}, so the input schema
 * alone proves nothing about a direct handle() call.
 */
trait ResolvesBoundingArguments
{
    /**
     * @return array{bool, list<string>}|Response the resolved [full, entries] pair, or the error
     *                                            response rejecting invalid input
     */
    private function boundingArguments(Request $request): array|Response
    {
        $full = $request->get('full') ?? false;

        if (! is_bool($full)) {
            return Response::error('The full argument must be a boolean.');
        }

        $entries = $request->get('entries') ?? [];

        if (! is_array($entries) || ! array_is_list($entries)) {
            return Response::error('The entries argument must be a list of entry-point node strings.');
        }

        foreach ($entries as $entry) {
            if (! is_string($entry)) {
                return Response::error('The entries argument must be a list of entry-point node strings.');
            }
        }

        return [$full, $entries];
    }

    /** The shared input-schema descriptions, so the two tools cannot drift apart. */
    private static function fullArgumentDescription(): string
    {
        return 'Return the uncapped lists and maps instead of the default capped-at-'
            . BoundedPresenter::LIST_CAP . ' view. The response still carries bounded/…Total fields. '
            . 'Reach for this only when the whole fan-out is genuinely needed — on a hub symbol the full document is very large.';
    }

    private static function entriesArgumentDescription(): string
    {
        return 'Entry-point nodes (from a previous response) to keep visible past the cap: they stay in entryPoints and keep their values in every per-entry map. '
            . 'Unknown names are ignored. Ignored when full is true.';
    }
}
