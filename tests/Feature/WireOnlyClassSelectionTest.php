<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Tests\Support\FakesGit;
use SanderMuller\Richter\Tests\TestCase;

/**
 * A class that only describes a shape for the frontend: `#[TypeScript]` generates a type from it,
 * and no application code names it. The graph has no node for it, so the change used to read
 * UNRESOLVED and withdraw the selection, though only the tests that name the class can see it.
 */
final class WireOnlyClassSelectionTest extends TestCase
{
    use FakesGit;

    private const string DTO = 'app/DataObjects/StoreOrderBody.php';

    /** @var list<string> */
    private array $written = [];

    protected function tearDown(): void
    {
        foreach ($this->written as $file) {
            File::delete(base_path($file));
        }

        File::deleteDirectory(base_path('app/DataObjects'));

        parent::tearDown();
    }

    private function write(string $file, string $source): void
    {
        File::ensureDirectoryExists(dirname(base_path($file)));
        File::put(base_path($file), $source);
        $this->written[] = $file;
    }

    private function dto(string $parameters): string
    {
        return "<?php\nnamespace App\\DataObjects;\n\nuse Spatie\\TypeScriptTransformer\\Attributes\\TypeScript;\n\n#[TypeScript]\nfinal readonly class StoreOrderBody\n{\n    public function __construct({$parameters}) {}\n}\n";
    }

    /** The DTO gains a promoted parameter, on line 9 of both sides. */
    private function changeTheDto(): void
    {
        $base = $this->dto('public string $id');
        $head = $this->dto('public string $id, public ?int $quantity = null');
        $this->write(self::DTO, $head);
        $file = self::DTO;
        $diff = "diff --git a/{$file} b/{$file}\n--- a/{$file}\n+++ b/{$file}\n@@ -9,1 +9,1 @@\n-    public function __construct(public string \$id) {}\n+    public function __construct(public string \$id, public ?int \$quantity = null) {}\n";

        $this->fakeGit([
            '*merge-base*' => Process::result("abc123\n"),
            '*show-prefix*' => Process::result(),
            '*show*' => Process::result($base),
            '*status*' => Process::result(''),
            '*diff*' => Process::result($diff),
        ]);
    }

    /** @return array{exit: int, determinable: bool, reasons: list<string>, tests: list<string>} */
    private function select(): array
    {
        $this->withoutMockingConsoleOutput();
        $exit = Artisan::call('richter:affected-tests', ['--base' => 'some-base', '--json' => true, '--no-cache' => true]);
        $output = Artisan::output();
        $document = json_decode(substr($output, (int) strpos($output, '{')), associative: true);
        $this->assertIsArray($document);
        $this->assertIsBool($document['determinable'] ?? null);
        $this->assertIsArray($document['reasons'] ?? null);
        $this->assertIsArray($document['tests'] ?? null);

        return [
            'exit' => $exit,
            'determinable' => $document['determinable'],
            'reasons' => array_values(array_filter($document['reasons'], is_string(...))),
            'tests' => array_values(array_filter($document['tests'], is_string(...))),
        ];
    }

    #[Test]
    public function a_wire_only_class_selects_the_tests_that_name_it(): void
    {
        $this->write('tests/Feature/WireShapeTest.php', "<?php\nuse App\\DataObjects\\StoreOrderBody;\nit('maps the shape', fn () => expect(StoreOrderBody::class)->toBeString());\n");
        $this->write('tests/Feature/UnrelatedTest.php', "<?php\nit('runs', fn () => expect(true)->toBeTrue());\n");
        $this->changeTheDto();

        $result = $this->select();

        $this->assertSame([], $result['reasons']);
        $this->assertTrue($result['determinable']);
        $this->assertSame(0, $result['exit']);
        $this->assertSame(['tests/Feature/WireShapeTest.php'], $result['tests']);
    }

    /** The scan's own hits select, so every way a test can name the class counts. */
    #[Test]
    public function every_test_that_names_the_class_is_selected(): void
    {
        $this->write('tests/Feature/NamespaceTest.php', "<?php\nuse App\\DataObjects;\nit('maps', fn () => expect(DataObjects\\StoreOrderBody::class)->toBeString());\n");
        $this->write('tests/Feature/StringTest.php', "<?php\nit('maps', fn () => expect(class_exists('App\\\\DataObjects\\\\StoreOrderBody'))->toBeTrue());\n");
        $this->write('tests/Support/BuildsBodies.php', "<?php\nnamespace Tests\\Support;\nuse App\\DataObjects\\StoreOrderBody;\ntrait BuildsBodies\n{\n    public function body(): StoreOrderBody { return new StoreOrderBody('1'); }\n}\n");
        $this->write('tests/Feature/CheckoutTest.php', "<?php\nuses(Tests\\Support\\BuildsBodies::class);\nit('checks out', fn () => expect(\$this->body())->not->toBeNull());\n");
        $this->write('tests/Feature/TypesTest.php', "<?php\nit('keeps the types current', fn () => \$this->artisan('typescript:transform')->assertSuccessful());\n");
        $this->write('tests/Feature/UnrelatedTest.php', "<?php\nit('runs', fn () => expect(true)->toBeTrue());\n");
        $this->changeTheDto();

        $result = $this->select();

        $this->assertTrue($result['determinable']);
        $this->assertSame(['tests/Feature/CheckoutTest.php', 'tests/Feature/NamespaceTest.php', 'tests/Feature/StringTest.php', 'tests/Feature/TypesTest.php'], $result['tests']);
    }

    #[Test]
    public function a_test_that_names_the_class_in_a_string_is_selected(): void
    {
        $this->write('tests/Feature/StringTest.php', "<?php\nit('maps', fn () => expect(class_exists('App\\\\DataObjects\\\\StoreOrderBody'))->toBeTrue());\n");
        $this->changeTheDto();

        $result = $this->select();

        $this->assertTrue($result['determinable']);
        $this->assertSame(['tests/Feature/StringTest.php'], $result['tests']);
    }

    #[Test]
    public function a_support_file_whose_users_cannot_be_listed_keeps_it_unresolved(): void
    {
        $this->write('tests/Datasets/Bodies.php', "<?php\nuse App\\DataObjects\\StoreOrderBody;\ndataset('bodies', [[StoreOrderBody::class]]);\n");
        $this->changeTheDto();

        $result = $this->select();

        $this->assertFalse($result['determinable']);
        $this->assertContains('changed file(s) could not be placed in the graph (UNRESOLVED)', $result['reasons']);
    }

    #[Test]
    public function a_wire_class_that_application_code_names_stays_unresolved(): void
    {
        $this->write('app/Http/Controllers/StoreOrderController.php', "<?php\nnamespace App\\Http\\Controllers;\n\nuse App\\DataObjects\\StoreOrderBody;\n\nfinal class StoreOrderController\n{\n    public function __invoke(): string\n    {\n        return StoreOrderBody::class;\n    }\n}\n");
        $this->changeTheDto();

        $result = $this->select();

        $this->assertFalse($result['determinable']);
        $this->assertContains('changed file(s) could not be placed in the graph (UNRESOLVED)', $result['reasons']);
    }

    #[Test]
    public function a_class_with_another_attribute_stays_unresolved(): void
    {
        $this->changeTheDto();
        $this->write(self::DTO, str_replace('#[TypeScript]', '#[\\App\\Attributes\\Shape]', $this->dto('public string $id, public ?int $quantity = null')));

        $result = $this->select();

        $this->assertFalse($result['determinable']);
        $this->assertContains('changed file(s) could not be placed in the graph (UNRESOLVED)', $result['reasons']);
    }
}
