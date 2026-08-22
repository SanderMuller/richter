<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Analysis\ColumnReferences;
use SanderMuller\Richter\Analysis\Hazard;
use SanderMuller\Richter\Graph\CodeGraph;
use SanderMuller\Richter\Tests\TestCase;

final class ColumnReferencesTest extends TestCase
{
    private const string MODEL = 'App\Models\Post';

    private const string RESOURCE = 'App\Http\Resources\PostResource';

    private string $projectRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->projectRoot = sys_get_temp_dir() . '/richter-column-references-' . bin2hex(random_bytes(8));
        mkdir("{$this->projectRoot}/app/Models", recursive: true);
        mkdir("{$this->projectRoot}/app/Http/Resources", recursive: true);
    }

    protected function tearDown(): void
    {
        new Filesystem()->deleteDirectory($this->projectRoot);

        parent::tearDown();
    }

    private function putModel(string $fillable): void
    {
        file_put_contents("{$this->projectRoot}/app/Models/Post.php", <<<PHP
            <?php declare(strict_types=1);
            namespace App\\Models;
            use Illuminate\\Database\\Eloquent\\Model;
            final class Post extends Model
            {
                protected \$fillable = [{$fillable}];
            }
            PHP);
    }

    private function putResource(string $keys): void
    {
        file_put_contents("{$this->projectRoot}/app/Http/Resources/PostResource.php", <<<PHP
            <?php declare(strict_types=1);
            namespace App\\Http\\Resources;
            use Illuminate\\Http\\Resources\\Json\\JsonResource;
            final class PostResource extends JsonResource
            {
                public function toArray(\$request): array
                {
                    return [{$keys}];
                }
            }
            PHP);
    }

    /** A graph placing the model's file and wiring a controller to the resource. */
    private function graph(bool $wireResource = true): CodeGraph
    {
        $edges = [['source' => 'App\Http\Controllers\PostController::show', 'target' => self::MODEL . '::title', 'type' => 'reads']];

        if ($wireResource) {
            $edges[] = ['source' => 'App\Http\Controllers\PostController::show', 'target' => self::RESOURCE, 'type' => 'resource'];
        }

        return new CodeGraph($edges, hasUnparseableFiles: false, nodeMetadata: [
            self::MODEL => ['file' => 'app/Models/Post.php'],
            self::RESOURCE => ['file' => 'app/Http/Resources/PostResource.php'],
        ]);
    }

    private function dropOf(string $column, string $member = self::MODEL): Hazard
    {
        // Built the way MigrationHazards builds one, so the suppression keys are the real ones.
        return new Hazard('migration', 2, null, $member, "column `posts`.`{$column}` dropped",
            ignoreKey: "posts.{$column}", alsoIgnoredBy: ['posts'], field: $column);
    }

    private function attach(Hazard $hazard, ?CodeGraph $graph = null): Hazard
    {
        return new ColumnReferences($graph ?? $this->graph(), $this->projectRoot)->attach([$hazard])[0];
    }

    #[Test]
    public function a_dropped_column_still_in_fillable_is_named(): void
    {
        $this->putModel("'title', 'subtitle'");

        $this->assertSame(
            'column `posts`.`subtitle` dropped, still named by App\Models\Post\'s own $fillable/$casts',
            $this->attach($this->dropOf('subtitle'))->evidence,
        );
    }

    #[Test]
    public function a_model_that_no_longer_lists_the_column_adds_nothing(): void
    {
        $this->putModel("'title'");

        $this->assertSame('column `posts`.`subtitle` dropped', $this->attach($this->dropOf('subtitle'))->evidence);
    }

    #[Test]
    public function a_resource_still_carrying_the_key_is_named(): void
    {
        $this->putModel("'title'");
        $this->putResource("'title' => \$this->title, 'subtitle' => \$this->subtitle");

        $this->assertSame(
            'column `posts`.`subtitle` dropped, still named by a `subtitle` key in app/Http/Resources/PostResource.php',
            $this->attach($this->dropOf('subtitle'))->evidence,
        );
    }

    #[Test]
    public function both_surfaces_are_named_when_both_still_have_it(): void
    {
        $this->putModel("'title', 'subtitle'");
        $this->putResource("'title' => \$this->title, 'subtitle' => \$this->subtitle");

        $this->assertStringContainsString('$fillable/$casts', $this->attach($this->dropOf('subtitle'))->evidence);
        $this->assertStringContainsString('PostResource.php', $this->attach($this->dropOf('subtitle'))->evidence);
    }

    #[Test]
    public function a_resource_reachable_only_by_name_is_still_named(): void
    {
        $this->putModel("'title', 'slug'");
        $this->putResource("'title' => \$this->title, 'slug' => \$this->slug, 'subtitle' => \$this->subtitle");

        // The graph wires no resource here, so the name fallback is what finds `PostResource` — and a
        // name match must mirror two of the model's fields, not one.
        $this->assertStringContainsString(
            'PostResource.php',
            $this->attach($this->dropOf('subtitle'), $this->graph(wireResource: false))->evidence,
        );
    }

    #[Test]
    public function a_wired_resource_that_does_not_mirror_the_model_is_not_named(): void
    {
        $this->putModel("'title', 'slug'");

        // One controller may touch several models and return several resources. A resource carrying a
        // key of the same name is not this model's resource on the strength of that name alone.
        file_put_contents("{$this->projectRoot}/app/Http/Resources/PostResource.php", <<<'PHP'
            <?php declare(strict_types=1);
            namespace App\Http\Resources;
            use Illuminate\Http\Resources\Json\JsonResource;
            final class PostResource extends JsonResource
            {
                public function toArray($request): array
                {
                    return ['subtitle' => $this->subtitle, 'locale' => $this->locale];
                }
            }
            PHP);

        $this->assertSame('column `posts`.`subtitle` dropped', $this->attach($this->dropOf('subtitle'))->evidence);
    }

    #[Test]
    public function a_resource_whose_keys_cannot_be_read_is_skipped(): void
    {
        $this->putModel("'title'");
        $this->putResource("...\$base, 'subtitle' => \$this->subtitle");

        $this->assertSame('column `posts`.`subtitle` dropped', $this->attach($this->dropOf('subtitle'))->evidence);
    }

    #[Test]
    public function a_table_name_member_finds_nothing_and_stays_as_it_was(): void
    {
        $this->putModel("'title', 'subtitle'");

        $hazard = $this->dropOf('subtitle', member: 'legacy_imports');

        $this->assertSame('column `posts`.`subtitle` dropped', $this->attach($hazard)->evidence);
    }

    #[Test]
    public function a_renamed_column_is_enriched_the_same_way(): void
    {
        $this->putModel("'title', 'subtitle'");

        $rename = new Hazard('migration', 2, null, self::MODEL, 'column `posts`.`subtitle` renamed to `standfirst`', field: 'subtitle');

        $this->assertStringContainsString('still named by', $this->attach($rename)->evidence);
    }

    #[Test]
    public function a_hazard_from_another_lane_is_left_alone(): void
    {
        $this->putModel("'title', 'subtitle'");

        $other = new Hazard('model', 2, 'CWE-915', self::MODEL . '::$fillable', '$fillable gained subtitle');

        $this->assertSame('$fillable gained subtitle', $this->attach($other)->evidence);
    }

    #[Test]
    public function a_drop_with_no_readable_column_name_is_left_alone(): void
    {
        $this->putModel("'title', 'subtitle'");

        $unnamed = new Hazard('migration', 2, null, self::MODEL, 'a column was dropped from `posts`, under a name richter could not read');

        $this->assertSame('a column was dropped from `posts`, under a name richter could not read', $this->attach($unnamed)->evidence);
    }

    #[Test]
    public function enrichment_never_moves_the_tier_or_the_reach(): void
    {
        $this->putModel("'title', 'subtitle'");

        $graded = $this->dropOf('subtitle')->withReach(Hazard::REACH_PUBLIC_WRITE);
        $enriched = $this->attach($graded);

        $this->assertSame(2, $enriched->tier);
        $this->assertSame(Hazard::REACH_PUBLIC_WRITE, $enriched->reach);
        $this->assertSame('posts.subtitle', $enriched->suppressionKey());
    }
}
