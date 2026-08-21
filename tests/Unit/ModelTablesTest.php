<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Analysis\Hazards\ModelTables;
use SanderMuller\Richter\Tests\TestCase;

final class ModelTablesTest extends TestCase
{
    private const string PROJECT = __DIR__ . '/../Fixtures/project';

    private string $temporaryRoot = '';

    protected function setUp(): void
    {
        parent::setUp();

        ModelTables::flush();
    }

    protected function tearDown(): void
    {
        ModelTables::flush();

        if ($this->temporaryRoot !== '') {
            $models = glob("{$this->temporaryRoot}/app/Models/*.php");

            array_map(unlink(...), $models === false ? [] : $models);
            rmdir("{$this->temporaryRoot}/app/Models");
            rmdir("{$this->temporaryRoot}/app");
            rmdir($this->temporaryRoot);
        }

        parent::tearDown();
    }

    /** @param  array<string, string>  $models  short class name => class body */
    private function project(array $models): string
    {
        $this->temporaryRoot = sys_get_temp_dir() . '/richter-model-tables-' . bin2hex(random_bytes(6));
        mkdir("{$this->temporaryRoot}/app/Models", recursive: true);

        foreach ($models as $name => $body) {
            file_put_contents(
                "{$this->temporaryRoot}/app/Models/{$name}.php",
                "<?php declare(strict_types=1);\n\nnamespace App\\Models;\n\nuse Illuminate\\Database\\Eloquent\\Model;\n\nfinal class {$name} extends Model\n{\n{$body}\n}\n",
            );
        }

        return $this->temporaryRoot;
    }

    #[Test]
    public function a_model_is_found_by_its_conventional_table(): void
    {
        $this->assertSame('App\Models\Post', ModelTables::modelFor('posts', self::PROJECT));
    }

    #[Test]
    public function a_multi_word_model_snake_cases_its_conventional_table(): void
    {
        $this->assertSame('App\Models\PostContainer', ModelTables::modelFor('post_containers', self::PROJECT));
    }

    #[Test]
    public function an_irregular_plural_resolves_the_way_eloquent_resolves_it(): void
    {
        $root = $this->project(['Person' => '']);

        $this->assertSame('App\Models\Person', ModelTables::modelFor('people', $root));
        $this->assertNull(ModelTables::modelFor('persons', $root));
    }

    #[Test]
    public function an_explicit_table_property_wins_over_the_convention(): void
    {
        $root = $this->project(['Article' => "    protected \$table = 'legacy_articles';"]);

        $this->assertSame('App\Models\Article', ModelTables::modelFor('legacy_articles', $root));
        $this->assertNull(ModelTables::modelFor('articles', $root));
    }

    #[Test]
    public function two_models_claiming_one_table_resolve_to_neither(): void
    {
        $root = $this->project([
            'Article' => "    protected \$table = 'entries';",
            'Entry' => '',
        ]);

        $this->assertNull(ModelTables::modelFor('entries', $root));
    }

    #[Test]
    public function a_table_no_model_claims_resolves_to_nothing(): void
    {
        $this->assertNull(ModelTables::modelFor('legacy_imports', self::PROJECT));
    }

    #[Test]
    public function a_missing_models_directory_resolves_to_nothing(): void
    {
        $this->assertNull(ModelTables::modelFor('posts', sys_get_temp_dir() . '/richter-absent-' . bin2hex(random_bytes(6))));
    }
}
