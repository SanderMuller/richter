<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

use SanderMuller\Richter\Changes\FrontendChanges;
use SanderMuller\Richter\Support\RichterConfig;
use Symfony\Component\Finder\Finder;

/**
 * Maps route nodes to the frontend test files (Vitest/Jest/Playwright/Cypress specs) referencing
 * them — the JS counterpart of {@see TestReferenceIndex}, built on the same reference scanning the
 * frontend bridge uses. Advisory by design: a frontend test is a suggestion for the JS runner,
 * never an input to `richter:affected-tests` determinability, and a missed reference only means a
 * spec isn't suggested — so unlike the PHP index there is no tri-state.
 */
final class FrontendTestIndex
{
    private const array SPEC_PATTERNS = ['*.test.ts', '*.test.tsx', '*.test.js', '*.test.jsx', '*.spec.ts', '*.spec.tsx', '*.spec.js', '*.spec.jsx', '*.cy.ts', '*.cy.js'];

    /** @var array<string, list<string>> route node id => spec files referencing it */
    private array $byNode = [];

    public function __construct(private readonly FrontendChanges $frontendChanges = new FrontendChanges()) {}

    /**
     * Builds from `richter.frontend.test_paths`, falling back to the frontend roots — spec-named
     * files only, so application sources under the same roots never register as tests.
     */
    public static function fromConfiguredPaths(string $projectRoot): self
    {
        $paths = RichterConfig::frontendTestPaths();
        $paths = $paths === [] ? RichterConfig::frontendRoots() : $paths;

        $index = new self();
        $directories = [];

        foreach ($paths as $path) {
            $directory = "{$projectRoot}/" . trim($path, '/');

            if (is_dir($directory)) {
                $directories[] = $directory;
            }
        }

        if ($directories === []) {
            return $index;
        }

        foreach (Finder::create()->files()->in($directories)->name(self::SPEC_PATTERNS) as $file) {
            $path = $file->getPathname();

            if (str_starts_with($path, $projectRoot . '/')) {
                $path = substr($path, strlen($projectRoot) + 1);
            }

            $index->addSource((string) file_get_contents($file->getPathname()), $path);
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
    public function testsReferencing(string $entryPointNode): array
    {
        return $this->byNode[$entryPointNode] ?? [];
    }
}
