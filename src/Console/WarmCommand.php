<?php declare(strict_types=1);

namespace SanderMuller\Richter\Console;

use Illuminate\Console\Command;
use SanderMuller\Richter\Analysis\JsonPresenter;
use SanderMuller\Richter\Console\Concerns\WarnsAboutRootNamespace;
use SanderMuller\Richter\Console\Concerns\WritesDocuments;
use SanderMuller\Richter\Graph\CacheStatus;
use SanderMuller\Richter\Graph\GraphCache;
use SanderMuller\Richter\Graph\WarmResult;
use SanderMuller\Richter\Support\RichterConfig;
use Throwable;

/**
 * Build the code graph on purpose and leave it on disk, or report whether what is on disk still
 * matches this tree.
 *
 * Every other command builds the graph as a side effect of a report, which leaves a consumer who
 * wants a graph baked at deploy time with no way to ask for one — and no way to find out whether the
 * baked one is being used. A miss is invisible by design: the cache is failure-tolerant, so a hosted
 * process whose entry no longer matches rebuilds on every cold container with nothing in the logs.
 *
 * `--check` is the answer to that, and it names the cause rather than reporting that there is one.
 * A build container and a runtime container on different PHP patch releases miss forever, silently;
 * this prints `php (8.5.8 → 8.5.9)`.
 *
 * Both modes exit non-zero when the answer is no, so a deploy step can gate on them.
 */
final class WarmCommand extends Command
{
    use WarnsAboutRootNamespace;
    use WritesDocuments;

    /** @var string */
    protected $signature = 'richter:warm
        {--check : Report whether the stored entry still matches this tree, and which input differs when it does not. Builds nothing}
        {--json : Emit the result as JSON on stdout}';

    /** @var string */
    protected $description = 'Build the code graph and store it, or check whether the stored one still matches';

    public function handle(GraphCache $graphs): int
    {
        $json = (bool) $this->option('json');

        try {
            // Inside the boundary, all of it: reading `richter.cache.enabled` throws on a
            // non-boolean value, and so does resolving the root namespace. A throw outside would
            // reach stdout as an unhandled exception in the one mode that promised a document.
            if (! RichterConfig::cacheEnabled()) {
                // Warming a disabled cache would build the graph, write nothing, and report success
                // for work that produced no entry; checking one would report a match nothing will
                // ever read, since graph() bypasses the cache entirely when it is off.
                return $this->refuse(
                    $json,
                    (bool) $this->option('check') ? 'check' : 'warm',
                    'cache-disabled',
                    'The cache is disabled. Set richter.cache.enabled to true before warming or checking it.',
                );
            }

            $this->warnAboutRootNamespace();

            return $this->option('check')
                ? $this->check($graphs->inspect(), $json)
                : $this->warm($graphs->warm(), $json);
        } catch (Throwable $throwable) {
            // An unexpected failure, not an answer — so it takes the `{"error": …}` shape rather
            // than the ok:false one a caller branches on.
            if ($json) {
                $this->writeDocument(JsonPresenter::encode(['error' => $throwable->getMessage()]));
            } else {
                $this->error($throwable->getMessage());
            }

            return self::FAILURE;
        }
    }

    private function warm(WarmResult $result, bool $json): int
    {
        if ($json) {
            $this->writeDocument(JsonPresenter::encode(array_filter([
                'mode' => 'warm',
                'ok' => $result->written,
                'built' => $result->built,
                'repaired' => $result->repaired,
                'fingerprint' => $result->fingerprint,
                'nodes' => $result->nodeCount,
                'file' => $result->file,
                'bytes' => $result->bytes,
                'seconds' => round($result->seconds, 2),
            ], static fn (mixed $value): bool => $value !== null)));

            return $result->written ? self::SUCCESS : self::FAILURE;
        }

        if (! $result->written) {
            $this->error("Could not write the cache entry to {$result->file}.");
            $this->line('  The build succeeded; storing it did not. Check the directory exists and is writable.');

            return self::FAILURE;
        }

        $this->line(match (true) {
            $result->built => sprintf('Built the code graph in %.1fs.', $result->seconds),
            // No builder ran here — the entry was missing or unusable while this process held the
            // graph for it, so saying "built" would describe work that did not happen.
            $result->repaired => sprintf('Rewrote the cache entry from the graph already in memory (%.1fs).', $result->seconds),
            default => sprintf('The entry was already current (%.1fs).', $result->seconds),
        });
        $this->line("  fingerprint  {$result->fingerprint}");
        $this->line('  nodes        ' . number_format($result->nodeCount));
        $this->line("  entry        {$result->file}" . $this->size($result->bytes));

        return self::SUCCESS;
    }

    private function check(CacheStatus $status, bool $json): int
    {
        if ($json) {
            // `ok` is built in, never filtered: it is the field a deploy step branches on, and
            // `ok: false` is the answer it most needs to receive. Only the keys that do not APPLY
            // are dropped, and each is dropped by its own condition rather than by a blanket
            // falsy filter — which is what silently removed `ok: false` in an earlier version.
            $document = ['mode' => 'check', 'ok' => $status->matches, 'fingerprint' => $status->fingerprint];

            // Only when it differs: on a match it repeats the line above, and on an unreadable
            // entry there is nothing to report.
            if ($status->storedFingerprint !== null && $status->storedFingerprint !== $status->fingerprint) {
                $document['storedFingerprint'] = $status->storedFingerprint;
            }

            if ($status->corrupt) {
                $document['corrupt'] = true;
            }

            $document['file'] = $status->file;

            if ($status->bytes !== null) {
                $document['bytes'] = $status->bytes;
            }

            if ($status->reason !== null) {
                $document['reason'] = $status->reason;
                $document['detail'] = $status->detail;
            }

            $this->writeDocument(JsonPresenter::encode($document));

            return $status->matches ? self::SUCCESS : self::FAILURE;
        }

        if ($status->matches) {
            $this->line('The cached entry matches this tree.');
            $this->line("  fingerprint  {$status->fingerprint}");
            $this->line("  entry        {$status->file}" . $this->size($status->bytes));

            return self::SUCCESS;
        }

        $this->line($status->corrupt
            ? 'The cached entry is UNUSABLE — every run rebuilds, and will keep rebuilding.'
            : 'The cached entry does NOT match this tree — every run rebuilds.');
        $this->line('  reason       ' . ($status->reason ?? 'unknown'));

        if ($status->detail !== null) {
            $this->line("  {$status->detail}");
        }

        return self::FAILURE;
    }

    private function size(?int $bytes): string
    {
        return $bytes === null ? '' : sprintf(' (%.1f MB)', $bytes / 1048576);
    }

    /**
     * An outcome this command is built to produce, answered as "no".
     *
     * It keeps the `{mode, ok: false, reason, detail}` shape a caller branches on, rather than the
     * `{"error": …}` form — that one is reserved for an unexpected throw, and a deploy step reading
     * `ok` must not have to guess which of two shapes it received for a state richter understands.
     */
    private function refuse(bool $json, string $mode, string $reason, string $message): int
    {
        if ($json) {
            $this->writeDocument(JsonPresenter::encode([
                'mode' => $mode,
                'ok' => false,
                'reason' => $reason,
                'detail' => $message,
            ]));
        } else {
            $this->error($message);
        }

        return self::FAILURE;
    }
}
