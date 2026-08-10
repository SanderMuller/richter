<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Graph\CodeGraphBuilder;
use SanderMuller\Richter\Support\AppFiles;
use SanderMuller\Richter\Tests\TestCase;

/**
 * A file that parses but is semantically invalid — two `use` statements binding the same alias —
 * used to take the whole run down. Name resolution threw, no call site caught it, and a graph build
 * or a `detect-changes` over a diff containing such a file aborted with a `PhpParser\Error` instead
 * of a report. Advisory tooling must degrade, never abort.
 *
 * The tree is built in a temp dir on purpose: a class like this is a fatal compile error, so it must
 * never sit anywhere the test autoloader could reach it.
 */
final class DuplicateUseAliasTest extends TestCase
{
    private const string SOURCE = <<<'PHP'
        <?php declare(strict_types=1);

        namespace Acme\Services;

        use Acme\Billing\Report;
        use Acme\Exports\Report;

        final class Collector
        {
            public function run(): void
            {
                Report::build();
            }
        }
        PHP;

    private string $projectRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->projectRoot = sys_get_temp_dir() . '/richter-dup-alias-' . bin2hex(random_bytes(8));
        mkdir("{$this->projectRoot}/app/Services", recursive: true);
        file_put_contents("{$this->projectRoot}/app/Services/Collector.php", self::SOURCE);
    }

    protected function tearDown(): void
    {
        new Filesystem()->deleteDirectory($this->projectRoot);
        parent::tearDown();
    }

    #[Test]
    public function name_resolution_survives_a_duplicate_use_alias(): void
    {
        $ast = AppFiles::parseResolved(self::SOURCE);

        $this->assertIsArray($ast, 'the file parses; a duplicate alias is a semantic fault, not a parse failure');
    }

    #[Test]
    public function a_graph_build_over_such_a_file_completes_and_does_not_call_it_unparseable(): void
    {
        // Counting it unparseable would be its own harm: that flag is a GLOBAL determinability
        // blocker, so one invalid alias anywhere would make `affected-tests` refuse to answer.
        $branch = new CodeGraphBuilder()->buildTracerBranch($this->projectRoot);

        $this->assertSame(0, $branch['unparseableFiles']);
    }
}
