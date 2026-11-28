<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Iterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Graph\CodeGraphBuilder;
use SanderMuller\Richter\Tests\TestCase;

/**
 * A dispatch of a method's own parameter, followed back to the callers that fill it.
 *
 * Over a temp project and through the build, because the proof spans files: the tracer records the
 * dispatch in one, and the callers are read from the others once every class is collected.
 */
final class ParameterDispatchBuildTest extends TestCase
{
    private const string TRAIT = 'App\Services\Concerns\DispatchesOrderBatch';

    private const string DISPATCHER = self::TRAIT . '::configureBatch';

    /** The trait both helpers live in: one builds the jobs, one dispatches what it is handed. */
    private const string HELPERS = <<<'PHP'
        <?php declare(strict_types=1);

        namespace App\Services\Concerns;

        use App\Jobs\ProcessOrder;
        use Illuminate\Support\Facades\Bus;

        trait DispatchesOrderBatch
        {
            protected function buildJobs(array $orders): array
            {
                $jobs = [];

                foreach ($orders as $order) {
                    $jobs[] = new ProcessOrder($order);
                }

                return $jobs;
            }

            protected function configureBatch(array $jobs, string $name = 'batch')
            {
                return Bus::batch($jobs)->name($name);
            }
        }
        PHP;

    private string $projectRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projectRoot = sys_get_temp_dir() . '/richter-parameter-dispatch-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        new Filesystem()->deleteDirectory($this->projectRoot);
        parent::tearDown();
    }

    /**
     * @param  array<string, string>  $files  path under `app/` => source
     * @return array{sites: list<array{file: string, line: int, dispatcher: string}>, jobs: list<string>}
     */
    private function build(array $files, string $dispatcher = self::DISPATCHER): array
    {
        foreach ($files as $path => $source) {
            File::ensureDirectoryExists(dirname("{$this->projectRoot}/app/{$path}"));
            File::put("{$this->projectRoot}/app/{$path}", $source);
        }

        $branch = new CodeGraphBuilder()->buildTracerBranch($this->projectRoot);
        $jobs = [];

        foreach ($branch['edges'] as $edge) {
            if ($edge['source'] === $dispatcher && $edge['type'] === 'action-to-job') {
                $jobs[] = $edge['target'];
            }
        }

        sort($jobs);

        return ['sites' => $branch['unresolvedDispatchSites'], 'jobs' => array_values(array_unique($jobs))];
    }

    /** A class that uses the helper trait, with one public method holding `$body`. */
    private static function importer(string $body, string $class = 'OrderImporter', string $extra = ''): string
    {
        return <<<PHP
            <?php declare(strict_types=1);

            namespace App\Services;

            use App\Jobs\ArchiveOrder;
            use App\Jobs\ProcessOrder;
            use App\Services\Concerns\DispatchesOrderBatch;

            final class {$class}
            {
                use DispatchesOrderBatch;

                public function run(array \$orders, \$unknown = null): void
                {
                    {$body}
                }
                {$extra}
            }
            PHP;
    }

    #[Test]
    public function a_batch_built_by_one_helper_and_dispatched_by_another_is_followed(): void
    {
        $result = $this->build([
            'Services/Concerns/DispatchesOrderBatch.php' => self::HELPERS,
            'Services/OrderImporter.php' => self::importer('$jobs = $this->buildJobs($orders); $this->configureBatch($jobs, "import")->dispatch();'),
        ]);

        $this->assertSame([], $result['sites']);
        $this->assertSame(['App\Jobs\ProcessOrder::handle'], $result['jobs']);
    }

    #[Test]
    public function the_jobs_of_every_caller_are_joined(): void
    {
        $result = $this->build([
            'Services/Concerns/DispatchesOrderBatch.php' => self::HELPERS,
            'Services/OrderImporter.php' => self::importer('$jobs = $this->buildJobs($orders); $this->configureBatch($jobs)->dispatch();'),
            'Services/OrderArchiver.php' => self::importer('$this->configureBatch([new ArchiveOrder(), function (): void {}])->dispatch();', 'OrderArchiver'),
            'Services/OrderSweeper.php' => self::importer('$this->configureBatch($this->buildJobs($orders))->dispatch();', 'OrderSweeper'),
        ]);

        $this->assertSame([], $result['sites']);
        $this->assertSame(['App\Jobs\ArchiveOrder::handle', 'App\Jobs\ProcessOrder::handle'], $result['jobs']);
    }

    #[Test]
    public function the_followed_dispatch_draws_what_the_same_jobs_dispatched_inline_draw(): void
    {
        $through = $this->build([
            'Services/Concerns/DispatchesOrderBatch.php' => self::HELPERS,
            'Services/OrderImporter.php' => self::importer('$jobs = $this->buildJobs($orders); $this->configureBatch($jobs)->dispatch();'),
        ]);

        new Filesystem()->deleteDirectory($this->projectRoot);

        $inline = $this->build([
            'Services/OrderImporter.php' => self::importer('$jobs = []; foreach ($orders as $order) { $jobs[] = new ProcessOrder($order); } \Illuminate\Support\Facades\Bus::batch($jobs)->dispatch();'),
        ], 'App\Services\OrderImporter::run');

        $this->assertSame($inline['jobs'], $through['jobs']);
        $this->assertSame($inline['sites'], $through['sites']);
    }

    #[Test]
    public function a_dispatcher_nothing_calls_dispatches_nothing(): void
    {
        $result = $this->build(['Services/Concerns/DispatchesOrderBatch.php' => self::HELPERS]);

        $this->assertSame([], $result['sites']);
        $this->assertSame([], $result['jobs']);
    }

    /**
     * Callers that pass something the proof cannot read, and mentions of the method that are no plain
     * call. Each keeps the site, with the dispatch statement as the place to look.
     *
     * @return Iterator<string, array{string, string}>
     */
    public static function callersThatKeepTheSite(): Iterator
    {
        yield 'an unknown value' => ['$this->configureBatch($unknown)->dispatch();', ''];
        yield 'a proven caller beside an unknown one' => ['$this->configureBatch($this->buildJobs($orders))->dispatch(); $this->configureBatch($unknown)->dispatch();', ''];
        yield 'an array with an unknown element' => ['$this->configureBatch([new ProcessOrder(1), $unknown])->dispatch();', ''];
        yield 'a spread argument' => ['$this->configureBatch(...$orders)->dispatch();', ''];
        yield 'a named argument' => ['$this->configureBatch(jobs: $this->buildJobs($orders))->dispatch();', ''];
        yield 'a local appended to after the helper call' => ['$jobs = $this->buildJobs($orders); $jobs[] = $unknown; $this->configureBatch($jobs)->dispatch();', ''];
        yield 'a local bound in a branch' => ['if ($orders) { $jobs = $this->buildJobs($orders); } $this->configureBatch($jobs)->dispatch();', ''];
        yield 'a first-class callable' => ['$configure = $this->configureBatch(...); $configure($unknown)->dispatch();', ''];
        yield 'a callable array' => ['call_user_func([$this, "configureBatch"], $unknown);', ''];
        yield 'a callable array with a computed name' => ['$method = "configure" . "Batch"; call_user_func([$this, $method], $unknown);', ''];
        yield 'a method name that is not written out' => ['$method = "configure" . "Batch"; $this->{$method}($unknown);', ''];
        yield 'a call on another object' => ['$other = new self(); $other->configureBatch($unknown)->dispatch();', ''];
        yield 'a helper that returns something unproven' => ['$this->configureBatch($this->loadJobs())->dispatch();', 'protected function loadJobs(): array { return cache()->get("jobs"); }'];
        yield "a helper that returns another helper's result" => ['$this->configureBatch($this->wrap($orders))->dispatch();', 'protected function wrap(array $orders): array { return $this->buildJobs($orders); }'];
        yield 'a generator helper' => ['$this->configureBatch($this->each($orders))->dispatch();', 'protected function each(array $orders): iterable { yield new ProcessOrder(1); return []; }'];
        yield 'a helper with no return' => ['$this->configureBatch($this->nothing())->dispatch();', 'protected function nothing() { $jobs = []; $jobs[] = new ProcessOrder(1); }'];
        yield 'a renamed trait method elsewhere in the class' => ['$this->configureBatch($this->buildJobs($orders))->dispatch();', 'use \App\Services\Concerns\Other { helper as public; }'];
    }

    #[Test]
    #[DataProvider('callersThatKeepTheSite')]
    public function a_caller_the_proof_cannot_read_keeps_the_site(string $body, string $extra): void
    {
        $result = $this->build([
            'Services/Concerns/DispatchesOrderBatch.php' => self::HELPERS,
            'Services/Concerns/Other.php' => "<?php\nnamespace App\\Services\\Concerns;\ntrait Other\n{\n    protected function helper(): void {}\n}\n",
            'Services/OrderImporter.php' => self::importer($body, extra: $extra),
        ]);

        $this->assertSame([['file' => 'app/Services/Concerns/DispatchesOrderBatch.php', 'line' => 23, 'dispatcher' => self::DISPATCHER]], $result['sites']);
        $this->assertSame([], $result['jobs']);
    }

    /**
     * Shapes of the dispatching method that are never set aside for the callers.
     *
     * @return Iterator<string, array{string}>
     */
    public static function dispatchersThatKeepTheSite(): Iterator
    {
        yield 'a public method' => ['public function configureBatch(array $jobs) { return Bus::batch($jobs); }'];
        yield 'a static method' => ['protected static function configureBatch(array $jobs) { return Bus::batch($jobs); }'];
        yield 'a parameter appended to first' => ['protected function configureBatch(array $jobs) { $jobs[] = $this->extra(); return Bus::batch($jobs); }'];
        yield 'a parameter by reference' => ['protected function configureBatch(array &$jobs) { return Bus::batch($jobs); }'];
        yield 'a variadic parameter' => ['protected function configureBatch(...$jobs) { return Bus::batch($jobs); }'];
        yield 'a parameter with a default that holds something' => ['protected function configureBatch(array $jobs = [null]) { return Bus::batch($jobs); }'];
        yield 'a parameter captured by a closure' => ['protected function configureBatch(array $jobs) { $late = fn () => $jobs; return Bus::batch($jobs); }'];
        yield 'a method that hands its locals out' => ['protected function configureBatch(array $jobs) { $this->log(compact("jobs")); return Bus::batch($jobs); }'];
    }

    #[Test]
    #[DataProvider('dispatchersThatKeepTheSite')]
    public function a_dispatcher_that_does_not_pass_its_parameter_straight_through_keeps_the_site(string $method): void
    {
        $helpers = "<?php\nnamespace App\\Services\\Concerns;\nuse App\\Jobs\\ProcessOrder;\nuse Illuminate\\Support\\Facades\\Bus;\ntrait DispatchesOrderBatch\n{\n    protected function buildJobs(array \$orders): array { return [new ProcessOrder(1)]; }\n    {$method}\n}\n";

        $result = $this->build([
            'Services/Concerns/DispatchesOrderBatch.php' => $helpers,
            'Services/OrderImporter.php' => self::importer('$this->configureBatch($this->buildJobs($orders));'),
        ]);

        $this->assertCount(1, $result['sites']);
        $this->assertSame(self::DISPATCHER, $result['sites'][0]['dispatcher']);
    }

    #[Test]
    public function every_declaration_of_the_building_helper_has_to_return_a_proven_array(): void
    {
        $base = "<?php\nnamespace App\\Services;\nuse App\\Jobs\\ProcessOrder;\nuse Illuminate\\Support\\Facades\\Bus;\nabstract class BatchRunner\n{\n    public function run(array \$orders): void { \$this->send(\$this->jobsFor(\$orders)); }\n    protected function jobsFor(array \$orders): array { return [new ProcessOrder(1)]; }\n    private function send(array \$jobs): void { Bus::batch(\$jobs)->dispatch(); }\n}\n";
        $proven = "<?php\nnamespace App\\Services;\nuse App\\Jobs\\ArchiveOrder;\nfinal class ArchiveRunner extends BatchRunner\n{\n    protected function jobsFor(array \$orders): array { if (\$orders === []) { return []; } return [new ArchiveOrder()]; }\n}\n";
        $unproven = "<?php\nnamespace App\\Services;\nfinal class LegacyRunner extends BatchRunner\n{\n    protected function jobsFor(array \$orders): array { return \$orders; }\n}\n";

        $followed = $this->build(['Services/BatchRunner.php' => $base, 'Services/ArchiveRunner.php' => $proven], 'App\Services\BatchRunner::send');

        $this->assertSame([], $followed['sites']);
        $this->assertSame(['App\Jobs\ArchiveOrder::handle', 'App\Jobs\ProcessOrder::handle'], $followed['jobs']);

        $kept = $this->build(['Services/LegacyRunner.php' => $unproven], 'App\Services\BatchRunner::send');

        $this->assertCount(1, $kept['sites']);
        $this->assertSame([], $kept['jobs']);
    }

    /**
     * A family the build cannot list in full. Something outside it could call the method.
     *
     * @return Iterator<string, array{array<string, string>}>
     */
    public static function familiesThatCannotBeListed(): Iterator
    {
        $call = '$this->configureBatch($this->buildJobs($orders))->dispatch();';

        yield 'a user that extends a class outside the scanned tree' => [[
            'Services/OrderImporter.php' => str_replace('final class OrderImporter', 'final class OrderImporter extends \Vendor\Package\BaseService', self::importer($call)),
        ]];
        yield 'a user that also uses a trait outside the scanned tree' => [[
            'Services/OrderImporter.php' => str_replace('use DispatchesOrderBatch;', 'use DispatchesOrderBatch, \Vendor\Package\Macroable;', self::importer($call)),
        ]];
        yield 'an anonymous class that uses the trait' => [[
            'Services/OrderImporter.php' => self::importer($call),
            'Services/Inline.php' => "<?php\nnamespace App\\Services;\nfinal class Inline\n{\n    public function make(): object { return new class { use Concerns\\DispatchesOrderBatch; }; }\n}\n",
        ]];
        yield 'a user that re-exposes the method under another name' => [[
            'Services/OrderImporter.php' => str_replace('use DispatchesOrderBatch;', 'use DispatchesOrderBatch { configureBatch as public send; }', self::importer($call)),
        ]];
    }

    /** @param  array<string, string>  $files */
    #[Test]
    #[DataProvider('familiesThatCannotBeListed')]
    public function a_family_that_cannot_be_listed_keeps_the_site(array $files): void
    {
        $result = $this->build(['Services/Concerns/DispatchesOrderBatch.php' => self::HELPERS] + $files);

        $this->assertCount(1, $result['sites']);
        $this->assertSame(self::DISPATCHER, $result['sites'][0]['dispatcher']);
        $this->assertSame([], $result['jobs']);
    }

    #[Test]
    public function a_private_method_ignores_a_parent_outside_the_scanned_tree(): void
    {
        // Nothing but the class itself can call a private method, so a vendor parent says nothing.
        $source = "<?php\nnamespace App\\Services;\nuse App\\Jobs\\ProcessOrder;\nuse Illuminate\\Support\\Facades\\Bus;\nfinal class OrderImporter extends \\Vendor\\Package\\BaseService\n{\n    public function run(): void { \$this->send([new ProcessOrder(1)]); }\n    private function send(array \$jobs): void { Bus::batch(\$jobs)->dispatch(); }\n}\n";

        $result = $this->build(['Services/OrderImporter.php' => $source], 'App\Services\OrderImporter::send');

        $this->assertSame([], $result['sites']);
        $this->assertSame(['App\Jobs\ProcessOrder::handle'], $result['jobs']);
    }

    #[Test]
    public function a_first_class_callable_beside_a_dispatched_parameter_is_no_dispatch(): void
    {
        // `ProcessOrder::dispatch(...)` builds a closure, and reading its arguments throws. The
        // parameter dispatch next to it is still followed.
        $source = "<?php\nnamespace App\\Services;\nuse App\\Jobs\\ProcessOrder;\nuse Illuminate\\Support\\Facades\\Bus;\nfinal class OrderImporter\n{\n    public function run(): void { \$this->send([new ProcessOrder(1)]); }\n    private function send(array \$jobs): void { \$later = ProcessOrder::dispatch(...); Bus::batch(\$jobs)->dispatch(); }\n}\n";

        $result = $this->build(['Services/OrderImporter.php' => $source], 'App\Services\OrderImporter::send');

        $this->assertSame([], $result['sites']);
        $this->assertSame(['App\Jobs\ProcessOrder::handle'], $result['jobs']);
    }

    #[Test]
    public function a_single_job_passed_through_a_parameter_is_followed_too(): void
    {
        $source = "<?php\nnamespace App\\Services;\nuse App\\Jobs\\ProcessOrder;\nfinal class OrderImporter\n{\n    public function run(): void { \$job = new ProcessOrder(1); \$this->send(\$job); \$this->send(new ProcessOrder(2)); }\n    private function send(\$job): void { dispatch(\$job); }\n}\n";

        $result = $this->build(['Services/OrderImporter.php' => $source], 'App\Services\OrderImporter::send');

        $this->assertSame([], $result['sites']);
        $this->assertSame(['App\Jobs\ProcessOrder::handle'], $result['jobs']);
    }

    #[Test]
    public function the_worker_process_resolves_the_same_dispatches_as_the_in_process_build(): void
    {
        $this->build([
            'Services/Concerns/DispatchesOrderBatch.php' => self::HELPERS,
            'Services/OrderImporter.php' => self::importer('$jobs = $this->buildJobs($orders); $this->configureBatch($jobs)->dispatch();'),
            'Services/OrderArchiver.php' => self::importer('$this->configureBatch($unknown)->dispatch();', 'OrderArchiver'),
        ]);

        $out = (string) tempnam(sys_get_temp_dir(), 'richter-test-');

        try {
            $this->assertSame(0, Artisan::call('richter:internal-tracer-branch', ['--project' => $this->projectRoot, '--out' => $out]));
            $decoded = json_decode((string) file_get_contents($out), associative: true);
        } finally {
            File::delete($out);
        }

        $this->assertEquals(new CodeGraphBuilder()->buildTracerBranch($this->projectRoot), $decoded);
    }
}
