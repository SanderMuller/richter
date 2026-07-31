<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Graph\CodeGraph;
use SanderMuller\Richter\Graph\CodeGraphBuilder;
use SanderMuller\Richter\Tests\TestCase;
use SanderMuller\Richter\Tracers\ConstantReferenceTracer;

/**
 * Plan cref-wire: the build wires {@see ConstantReferenceTracer} in, so
 * a constant/enum-case change pins to its readers. Isolated temp-dir app + the Brain-independent
 * {@see CodeGraphBuilder::buildTracerBranch()}, so no booted route graph is needed.
 */
final class ConstantReferenceGraphTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir() . '/richter-cref-' . bin2hex(random_bytes(6));

        // Money declares SCALE and reads it via static::; Pricing (a subclass) also reads static::SCALE
        // — both must resolve to Money::SCALE (inherited). Pricing declares VAT_RATE, read only by
        // withTax. Unused::NEVER is declared but read nowhere.
        $this->write('app/Money/Money.php', <<<'PHP'
            <?php declare(strict_types=1);
            namespace App\Money;
            abstract class Money
            {
                protected const int SCALE = 2;
                public function roundIt(float $v): float { return round($v, static::SCALE); }
            }
            PHP);

        $this->write('app/Money/Pricing.php', <<<'PHP'
            <?php declare(strict_types=1);
            namespace App\Money;
            final class Pricing extends Money
            {
                public const float VAT_RATE = 0.21;
                public function withTax(int $net): float { return $net * (1 + self::VAT_RATE); }
                public function precision(): int { return static::SCALE; }
                public function shippingLabel(): string { return 'label'; }
            }
            PHP);

        $this->write('app/Money/Unused.php', <<<'PHP'
            <?php declare(strict_types=1);
            namespace App\Money;
            final class Unused { public const int NEVER = 1; }
            PHP);
    }

    protected function tearDown(): void
    {
        $this->deleteRecursive($this->root);

        parent::tearDown();
    }

    private function graph(): CodeGraph
    {
        return new CodeGraph(new CodeGraphBuilder()->buildTracerBranch($this->root)['edges'], hasUnparseableFiles: false);
    }

    /** @return list<string> */
    private function callerNodes(CodeGraph $graph, string $node): array
    {
        return array_column($graph->callersOf([$node]), 'node');
    }

    #[Test]
    public function a_constant_is_read_only_by_its_actual_reader(): void
    {
        $callers = $this->callerNodes($this->graph(), 'App\Money\Pricing::VAT_RATE');

        $this->assertContains('App\Money\Pricing::withTax', $callers);
        $this->assertNotContains('App\Money\Pricing::shippingLabel', $callers);
    }

    #[Test]
    public function an_inherited_constant_connects_every_reader_to_the_declaring_class(): void
    {
        // Both the base's own read and the subclass's `static::SCALE` resolve to Money::SCALE — so a
        // change to the base constant reaches both. This is the under-selection guard.
        $callers = $this->callerNodes($this->graph(), 'App\Money\Money::SCALE');

        $this->assertContains('App\Money\Money::roundIt', $callers);
        $this->assertContains('App\Money\Pricing::precision', $callers);
    }

    #[Test]
    public function a_read_nowhere_constant_nodes_as_a_leaf(): void
    {
        $graph = $this->graph();

        // The declares edge nodes it (so a change reads "analyzed", not UNRESOLVED); its only caller is
        // the declaring class via that declares edge — no method reads it.
        $this->assertTrue($graph->hasNode('App\Money\Unused::NEVER'));
        $readers = array_values(array_filter(
            $this->callerNodes($graph, 'App\Money\Unused::NEVER'),
            static fn (string $node): bool => str_contains($node, '::'),
        ));
        $this->assertSame([], $readers, 'a read-nowhere constant has no method readers');
    }

    private function write(string $relativePath, string $contents): void
    {
        $absolute = $this->root . '/' . $relativePath;
        @mkdir(dirname($absolute), 0777, true);
        file_put_contents($absolute, $contents);
    }

    private function deleteRecursive(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach (array_diff((array) scandir($path), ['.', '..']) as $entry) {
            $child = $path . '/' . $entry;
            is_dir($child) ? $this->deleteRecursive($child) : @unlink($child);
        }

        @rmdir($path);
    }
}
