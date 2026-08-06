<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

use SanderMuller\Richter\Graph\CodeGraph;
use SanderMuller\Richter\Support\AppFiles;
use SanderMuller\Richter\Tracers\EntryPointTracer;

/**
 * An `app/` subdirectory whose classes are entirely absent from the graph.
 *
 * `richter.entry_point_roots` names the directories richter traces as entry surfaces. A subsystem
 * dispatched through a registry or a factory lives behind a call shape no tracer follows, so if its
 * directory is not configured, none of its classes ever enter the graph — and every report is then
 * silently narrower than the app, with nothing in the output saying so. The classes are not
 * UNRESOLVED either: UNRESOLVED describes a *changed* file that could not be placed, and these
 * classes are missing as CONSUMERS of code that did change, which no diff-scoped signal can see.
 *
 * Deliberately measured, not derived from config. Comparing the configured roots against the
 * directory listing looks equivalent and is not: the defaults name eight directories, so on a
 * conventional app that diff fires on `Models`, `Services`, `Http`, `Policies`, `Providers` and the
 * rest — none of which should be entry-point roots. A note that fires on every app teaches its
 * reader to ignore it, including the once it is right. Graph presence discriminates: in a healthy
 * setup those directories are richly represented and stay silent.
 *
 * @internal
 */
final class EntryPointRootCoverage
{
    /**
     * Below this, a directory stays silent. A handful of classes is a stub, a shared-kernel folder,
     * or a directory mid-creation — all of which are absent from the graph for reasons that are not
     * a misconfiguration.
     */
    private const int MIN_CLASSES = 5;

    /**
     * Node-id prefixes that carry an FQCN after them. `route::`/`view::`/`command::`/`schedule::`
     * are absent on purpose: those ids address a surface by uri/name/signature, so nothing in them
     * can be matched against a class file.
     *
     * @var list<string>
     */
    private const array FQCN_PREFIXES = ['model::', 'controller::', 'action::', 'middleware::'];

    /**
     * One note per directory that holds classes and reaches the graph with none of them.
     *
     * Zero coverage, not "low" coverage: partial presence is the normal state of any directory (a
     * few classes referenced, the rest not yet), so a ratio threshold would need tuning per app and
     * would fire on healthy ones. Zero needs no tuning and means exactly one thing — nothing in the
     * graph has ever heard of this directory.
     *
     * @param  list<string>|null  $configuredRoots  {@see RichterConfig::entryPointRoots()}; null takes the defaults
     * @return list<string>
     */
    public static function notes(string $projectRoot, CodeGraph $graph, ?array $configuredRoots = null): array
    {
        $roots = new EntryPointTracer($configuredRoots)->roots();
        $covered = self::fqcnsInGraph($graph);
        $notes = [];

        foreach (self::classesByDirectory($projectRoot) as $directory => $fqcns) {
            if (count($fqcns) < self::MIN_CLASSES) {
                continue;
            }

            if (self::isConfigured($directory, $roots)) {
                continue;
            }

            if (array_any($fqcns, static fn (string $fqcn): bool => isset($covered[$fqcn]))) {
                continue;
            }

            $notes[] = sprintf(
                'richter: app/%s/ holds %d classes and none of them appear in the code graph. If they run through a registry, '
                . 'factory or other dispatch richter does not trace, add "%s" to richter.entry_point_roots — otherwise every '
                . 'report is narrower than the app without saying so.',
                $directory,
                count($fqcns),
                $directory,
            );
        }

        return $notes;
    }

    /**
     * A directory is configured when a root names it or names something inside it: `Http/Middleware`
     * being traced makes `Http` a directory richter already reaches into, and re-proposing the
     * parent would be advice to trace every controller as an entry surface.
     *
     * @param  list<string>  $roots
     */
    private static function isConfigured(string $directory, array $roots): bool
    {
        return array_any($roots, static fn (string $root): bool => $root === $directory || str_starts_with($root, $directory . '/'));
    }

    /**
     * Every class under `app/`, bucketed by its immediate subdirectory and sorted by name — one walk
     * of the tree, not one per directory, since this runs on every command invocation including the
     * warm-cache path where nothing else touches the filesystem.
     *
     * Immediate children only. A nested directory (`app/Http/Integrations`) whose parent has any
     * graph presence stays silent, which is the deliberate trade: a note that misses a case costs
     * the reader nothing, and one that fires on a healthy app costs it every future note's
     * credibility.
     *
     * @return array<string, list<string>>
     */
    private static function classesByDirectory(string $projectRoot): array
    {
        $buckets = [];

        foreach (AppFiles::phpClasses($projectRoot . '/app', $projectRoot) as $class) {
            $relative = substr($class['path'], strlen($projectRoot . '/app/'));
            $separator = strpos($relative, '/');

            if ($separator !== false) {
                $buckets[substr($relative, 0, $separator)][] = $class['fqcn'];
            }
        }

        ksort($buckets);

        return $buckets;
    }

    /**
     * Every class FQCN the graph mentions, as a lookup set — built once, so the check costs one pass
     * over the nodes rather than a scan per class file.
     *
     * @return array<string, true>
     */
    private static function fqcnsInGraph(CodeGraph $graph): array
    {
        $fqcns = [];

        foreach ($graph->nodes() as $node) {
            $fqcn = self::fqcnOf($node);

            if ($fqcn !== null) {
                $fqcns[$fqcn] = true;
            }
        }

        return $fqcns;
    }

    /** The class an id addresses, or null when the id addresses a surface rather than a class. */
    private static function fqcnOf(string $node): ?string
    {
        foreach (self::FQCN_PREFIXES as $prefix) {
            if (str_starts_with($node, $prefix)) {
                return explode('::', substr($node, strlen($prefix)), 2)[0];
            }
        }

        // A bare `Fqcn` or `Fqcn::member`; anything still prefixed addresses a non-class surface.
        return str_contains($node, '::') && ! str_contains(explode('::', $node, 2)[0], '\\')
            ? null
            : explode('::', $node, 2)[0];
    }
}
