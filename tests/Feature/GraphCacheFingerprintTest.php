<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Feature;

use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Graph\CodeGraphBuilder;
use SanderMuller\Richter\Graph\GraphCache;
use SanderMuller\Richter\Tests\TestCase;

/**
 * The fingerprint's in-process stat-cache (plan 047 lever B, safe form): it skips re-reading a file
 * whose stat signature is unchanged, but must produce a fingerprint byte-identical to hashing every
 * file and must never miss a real content change.
 */
final class GraphCacheFingerprintTest extends TestCase
{
    private string $project = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->project = sys_get_temp_dir() . '/richter-fp-' . bin2hex(random_bytes(6));
        mkdir($this->project . '/app', recursive: true);
        file_put_contents($this->project . '/app/Alpha.php', "<?php\nnamespace App;\nclass Alpha { public function a() { return 1; } }\n");
        file_put_contents($this->project . '/app/Beta.php', "<?php\nnamespace App;\nclass Beta { public function b() { return 2; } }\n");
        // Age both files out of the current second so the first fingerprint caches them (not racy).
        touch($this->project . '/app/Alpha.php', time() - 5);
        touch($this->project . '/app/Beta.php', time() - 5);
        clearstatcache();
    }

    protected function tearDown(): void
    {
        @unlink($this->project . '/app/Alpha.php');
        @unlink($this->project . '/app/Beta.php');
        @rmdir($this->project . '/app');
        @rmdir($this->project);
        parent::tearDown();
    }

    #[Test]
    public function the_stat_cache_yields_a_fingerprint_identical_to_a_cold_compute(): void
    {
        $cache = new GraphCache(new CodeGraphBuilder());

        $first = $cache->fingerprint($this->project);   // reads + caches every file
        $second = $cache->fingerprint($this->project);  // reuses the stat cache — no content read

        $this->assertSame($first, $second, 'repeated fingerprint must be stable');

        // A fresh instance content-hashes from scratch; the stat-cached value must equal it exactly.
        $cold = new GraphCache(new CodeGraphBuilder())->fingerprint($this->project);
        $this->assertSame($first, $cold, 'the stat cache must not change the fingerprint value');
    }

    #[Test]
    public function a_content_change_is_detected_through_the_stat_cache(): void
    {
        $cache = new GraphCache(new CodeGraphBuilder());

        $before = $cache->fingerprint($this->project);  // caches both files (aged, not racy)

        // Change content. Real mtime/ctime jump to now → the file is racily-recent → re-hashed
        // (and only visible at all because fingerprint() calls clearstatcache()).
        file_put_contents($this->project . '/app/Alpha.php', "<?php\nnamespace App;\nclass Alpha { public function a() { return 999; } }\n");

        $after = $cache->fingerprint($this->project);

        $this->assertNotSame($before, $after, 'a changed file must change the fingerprint even with the stat cache warm');
    }
}
