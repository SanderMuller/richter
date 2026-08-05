<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

use SanderMuller\Richter\Changes\FrontendChanges;
use SanderMuller\Richter\Support\RichterConfig;
use Symfony\Component\Finder\Finder;

/**
 * Inverse index from route nodes to the frontend files that consume them — the
 * consumer-parity lane's "who reads this endpoint" lookup. Sources are the configured
 * frontend roots' JS/TS files, filtered through the bridge's own
 * {@see FrontendChanges::handles()} (extensions, `.d.ts`, generated paths — one filter,
 * no drift), plus every Blade view's inline `<script>` slices, so the lane works for SPA,
 * hybrid, and pure-Blade apps alike. Construction is the caller's to defer: only a run
 * with a surviving removed resource key should pay this scan.
 */
final class FrontendConsumerIndex
{
    /** @var array<string, list<string>> route node id => consuming files, in scan order */
    private array $byNode = [];

    public function __construct(private readonly FrontendChanges $frontendChanges = new FrontendChanges()) {}

    public static function fromProject(string $projectRoot): self
    {
        $index = new self();
        $projectRoot = rtrim($projectRoot, '/');

        foreach (self::directories($projectRoot, RichterConfig::frontendRoots()) as $directory) {
            foreach (Finder::create()->files()->in($directory) as $file) {
                $path = self::relative($file->getPathname(), $projectRoot);

                if ($index->frontendChanges->handles($path)) {
                    $index->addSource((string) file_get_contents($file->getPathname()), $path);
                }
            }
        }

        foreach (self::directories($projectRoot, ['resources/views']) as $directory) {
            foreach (Finder::create()->files()->in($directory)->name('*.blade.php') as $file) {
                $source = (string) file_get_contents($file->getPathname());

                // Cheap pre-check: most views carry no inline script at all.
                if (! str_contains($source, '<script')) {
                    continue;
                }

                $index->addSource($index->frontendChanges->scriptSlices($source), self::relative($file->getPathname(), $projectRoot));
            }
        }

        return $index;
    }

    public function addSource(string $source, string $file): void
    {
        foreach ($this->frontendChanges->routeNodesIn($source) as $node) {
            $files = $this->byNode[$node] ?? [];

            if (! in_array($file, $files, strict: true)) {
                $files[] = $file;
            }

            $this->byNode[$node] = $files;
        }
    }

    /** @return list<string> */
    public function filesReferencing(string $routeNode): array
    {
        return $this->byNode[$routeNode] ?? [];
    }

    /**
     * @param  list<string>  $paths
     * @return list<string>
     */
    private static function directories(string $projectRoot, array $paths): array
    {
        $directories = [];

        foreach ($paths as $path) {
            $directory = "{$projectRoot}/" . trim($path, '/');

            if (is_dir($directory)) {
                $directories[] = $directory;
            }
        }

        return $directories;
    }

    private static function relative(string $path, string $projectRoot): string
    {
        return str_starts_with($path, $projectRoot . '/') ? substr($path, strlen($projectRoot) + 1) : $path;
    }
}
