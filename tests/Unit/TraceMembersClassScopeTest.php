<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Support\AppNamespace;
use SanderMuller\Richter\Tests\TestCase;
use SanderMuller\Richter\Tracers\EntryPointTracer;

/**
 * The class expansion behind `richter.second_hop = 'class'`: a bare FQCN candidate stands for every
 * traceable method the class declares. What it must not do is expand a sibling class-like's methods,
 * and what it must not hide is a class it could not read at all.
 */
final class TraceMembersClassScopeTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->projectRoot = sys_get_temp_dir() . '/richter-class-scope-' . bin2hex(random_bytes(8));
        mkdir("{$this->projectRoot}/app/Support", recursive: true);

        AppNamespace::flush();
    }

    protected function tearDown(): void
    {
        new Filesystem()->deleteDirectory($this->projectRoot);
        AppNamespace::flush();

        parent::tearDown();
    }

    #[Test]
    public function a_class_candidate_reads_every_traceable_method_it_declares(): void
    {
        $this->file('Support/Registry.php', <<<'PHP'
            <?php declare(strict_types=1);

            namespace App\Support;

            final class Registry
            {
                public static function all(): string
                {
                    $reader = new Reader();

                    return $reader->read();
                }

                public static function sweep(): string
                {
                    $sweeper = new Sweeper();

                    return $sweeper->sweep();
                }
            }
            PHP);

        $targets = $this->targetsFor('App\\Support\\Registry');

        $this->assertContains('App\\Support\\Reader::read', $targets);
        $this->assertContains('App\\Support\\Sweeper::sweep', $targets);
    }

    #[Test]
    public function only_the_named_class_expands_when_a_file_declares_two(): void
    {
        // `methodsOf()` collects every ClassMethod in the file; this path must not, or a candidate
        // would drag in methods it never declared.
        $this->file('Support/Registry.php', <<<'PHP'
            <?php declare(strict_types=1);

            namespace App\Support;

            final class Registry
            {
                public static function all(): string
                {
                    $reader = new Reader();

                    return $reader->read();
                }
            }

            final class Sidecar
            {
                public function ride(): string
                {
                    $sweeper = new Sweeper();

                    return $sweeper->sweep();
                }
            }
            PHP);

        $targets = $this->targetsFor('App\\Support\\Registry');

        $this->assertContains('App\\Support\\Reader::read', $targets);
        $this->assertNotContains('App\\Support\\Sweeper::sweep', $targets);
    }

    #[Test]
    public function an_abstract_class_expands_to_the_methods_that_have_a_body(): void
    {
        $this->file('Support/BaseRegistry.php', <<<'PHP'
            <?php declare(strict_types=1);

            namespace App\Support;

            abstract class BaseRegistry
            {
                abstract public function pending(): string;

                public function settled(): string
                {
                    $reader = new Reader();

                    return $reader->read();
                }
            }
            PHP);

        $this->assertContains('App\\Support\\Reader::read', $this->targetsFor('App\\Support\\BaseRegistry'));
    }

    #[Test]
    public function an_enum_expands_like_a_class(): void
    {
        $this->file('Support/Mode.php', <<<'PHP'
            <?php declare(strict_types=1);

            namespace App\Support;

            enum Mode: string
            {
                case Fast = 'fast';

                public function describe(): string
                {
                    $reader = new Reader();

                    return $reader->read();
                }
            }
            PHP);

        $this->assertContains('App\\Support\\Reader::read', $this->targetsFor('App\\Support\\Mode'));
    }

    #[Test]
    public function a_class_with_no_traceable_method_reads_clean_rather_than_unread(): void
    {
        // It was read; it simply had nothing to walk. Counting it would inflate the gap the count
        // exists to report.
        $this->file('Support/Constants.php', <<<'PHP'
            <?php declare(strict_types=1);

            namespace App\Support;

            final class Constants
            {
                public function label(): string
                {
                    return 'plain';
                }
            }
            PHP);

        $result = new EntryPointTracer()->traceMembers(['App\\Support\\Constants'], $this->projectRoot);

        $this->assertSame([], $result['edges']);
        $this->assertSame(0, $result['unread']);
    }

    #[Test]
    public function a_class_with_no_file_under_app_counts_as_one_unread(): void
    {
        // A static-call target only has to be in the app namespace and loadable, so it may have no
        // file here at all. Reporting it beats skipping it in silence.
        $result = new EntryPointTracer()->traceMembers(['App\\Support\\Absent'], $this->projectRoot);

        $this->assertSame([], $result['edges']);
        $this->assertSame(1, $result['unread']);
    }

    #[Test]
    public function an_unparseable_class_counts_once_not_once_per_method(): void
    {
        $this->file('Support/Broken.php', "<?php declare(strict_types=1);\n\nnamespace App\\Support;\n\nfinal class Broken {\n");

        $result = new EntryPointTracer()->traceMembers(['App\\Support\\Broken'], $this->projectRoot);

        $this->assertSame(1, $result['unread']);
    }

    #[Test]
    public function a_file_declaring_another_class_is_not_read_for_this_one(): void
    {
        $this->file('Support/Renamed.php', <<<'PHP'
            <?php declare(strict_types=1);

            namespace App\Support;

            final class SomethingElse
            {
                public function work(): string
                {
                    $reader = new Reader();

                    return $reader->read();
                }
            }
            PHP);

        $result = new EntryPointTracer()->traceMembers(['App\\Support\\Renamed'], $this->projectRoot);

        $this->assertSame([], $result['edges']);
        $this->assertSame(1, $result['unread']);
    }

    #[Test]
    public function a_member_candidate_for_a_missing_class_reads_as_empty_not_unread(): void
    {
        // Pre-existing asymmetry, pinned rather than changed: Brain's MethodTracer returns no edges
        // for a class it cannot find instead of throwing, so the member path reads "it calls
        // nothing" where the class path reports one unread for the same missing class.
        $result = new EntryPointTracer()->traceMembers(['App\\Support\\Absent::run'], $this->projectRoot);

        $this->assertSame([], $result['edges']);
        $this->assertSame(0, $result['unread']);
    }

    #[Test]
    public function a_member_candidate_still_reads_exactly_that_method(): void
    {
        // The default scope is unchanged by the expansion: a `Class::method` id reads that method
        // and no sibling.
        $this->file('Support/Registry.php', <<<'PHP'
            <?php declare(strict_types=1);

            namespace App\Support;

            final class Registry
            {
                public static function all(): string
                {
                    $reader = new Reader();

                    return $reader->read();
                }

                public static function sweep(): string
                {
                    $sweeper = new Sweeper();

                    return $sweeper->sweep();
                }
            }
            PHP);

        $targets = $this->targetsFor('App\\Support\\Registry::all');

        $this->assertContains('App\\Support\\Reader::read', $targets);
        $this->assertNotContains('App\\Support\\Sweeper::sweep', $targets);
    }

    /** @return list<string> */
    private function targetsFor(string $node): array
    {
        $result = new EntryPointTracer()->traceMembers([$node], $this->projectRoot);

        return array_column($result['edges'], 'target');
    }

    private function file(string $relativePath, string $source): void
    {
        $path = "{$this->projectRoot}/app/{$relativePath}";
        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, recursive: true);
        }

        file_put_contents($path, $source);

        // The collaborators the bodies above call have to exist for the walk to name them.
        foreach (['Reader' => 'read', 'Sweeper' => 'sweep'] as $class => $method) {
            file_put_contents(
                "{$this->projectRoot}/app/Support/{$class}.php",
                "<?php declare(strict_types=1);\n\nnamespace App\\Support;\n\nfinal class {$class}\n{\n    public function {$method}(): string\n    {\n        return '{$method}';\n    }\n}\n",
            );
        }
    }
}
