<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Changes\EloquentConfig;
use SanderMuller\Richter\Tests\TestCase;

final class EloquentConfigTest extends TestCase
{
    #[Test]
    public function field_set_unions_fillable_and_casts_property_and_casts_method(): void
    {
        $source = "<?php\nclass Post\n{\n    protected \$fillable = ['title', 'slug'];\n\n    protected \$casts = ['status' => 'string'];\n\n    protected function casts(): array\n    {\n        return ['layout' => 'string'];\n    }\n}\n";

        $this->assertSame(['title', 'slug', 'status', 'layout'], EloquentConfig::fieldSet($source));
    }

    #[Test]
    public function field_set_reads_each_propertys_own_array_in_a_combined_declaration(): void
    {
        // $fillable and $casts declared in ONE statement — a single Property node shared by both
        // PropertyItems. Each name must read its OWN array, not whichever the parser happens to
        // find first (the bug this test guards against would return ['title', 'slug'] twice and
        // miss 'status' entirely).
        $source = "<?php\nclass Post\n{\n    protected \$fillable = ['title', 'slug'], \$casts = ['status' => 'string'];\n}\n";

        $fields = EloquentConfig::fieldSet($source);

        $this->assertCount(3, $fields);
        $this->assertContains('title', $fields);
        $this->assertContains('slug', $fields);
        $this->assertContains('status', $fields);
    }

    #[Test]
    public function field_set_deduplicates_a_field_declared_in_more_than_one_member(): void
    {
        $source = "<?php\nclass Post\n{\n    protected \$fillable = ['title'];\n\n    protected \$casts = ['title' => 'string'];\n}\n";

        $this->assertSame(['title'], EloquentConfig::fieldSet($source));
    }

    #[Test]
    public function a_class_constant_key_is_resolved_by_reflection(): void
    {
        // App\Models\Post::COMMENTS is a real, autoloaded constant in the fixture project — `self`
        // resolves through the class node's own namespacedName, so it must match a REAL class.
        $source = "<?php\nnamespace App\Models;\nclass Post\n{\n    protected \$casts = [self::COMMENTS => 'string'];\n}\n";

        $this->assertSame(['comments'], EloquentConfig::fieldSet($source));
    }

    #[Test]
    public function a_foreign_class_constant_is_resolved_against_the_real_fixture_model(): void
    {
        $source = "<?php\nnamespace App\Http\Controllers;\nuse App\Models\Post;\nclass Foo\n{\n    protected \$fillable = [Post::COMMENTS];\n}\n";

        $this->assertSame(['comments'], EloquentConfig::fieldSet($source));
    }

    #[Test]
    public function an_unloadable_constant_class_degrades_to_skipping_that_item(): void
    {
        $source = "<?php\nclass Foo\n{\n    protected \$fillable = ['title', \App\Models\NoSuchModel::NAME];\n}\n";

        $this->assertSame(['title'], EloquentConfig::fieldSet($source));
    }

    #[Test]
    public function a_config_member_declared_by_two_classes_contributes_nothing(): void
    {
        $source = "<?php\nclass A\n{\n    protected \$fillable = ['a'];\n}\nclass B\n{\n    protected \$fillable = ['b'];\n}\n";

        $this->assertSame([], EloquentConfig::fieldSet($source));
    }

    #[Test]
    public function unparseable_source_yields_an_empty_field_set(): void
    {
        $this->assertSame([], EloquentConfig::fieldSet('not valid php {{{'));
    }

    #[Test]
    public function added_names_reports_the_difference_regardless_of_classification(): void
    {
        $base = "<?php\nclass Post\n{\n    protected \$fillable = ['title'];\n}\n";
        // A rename (title -> heading) co-occurring with an add (layout) — MODIFIED overall, but
        // addedNames() is independent of isAdditionOnlyEdit() and still reports the added name.
        $head = "<?php\nclass Post\n{\n    protected \$fillable = ['heading', 'layout'];\n}\n";

        $this->assertSame(['heading', 'layout'], EloquentConfig::addedNames($head, $base));
    }

    #[Test]
    public function added_names_is_empty_when_nothing_new_appears(): void
    {
        $base = "<?php\nclass Post\n{\n    protected \$fillable = ['title'];\n}\n";
        $head = "<?php\nclass Post\n{\n    protected \$fillable = ['title'];\n}\n";

        $this->assertSame([], EloquentConfig::addedNames($head, $base));
    }

    #[Test]
    public function added_names_reads_each_propertys_own_array_in_a_combined_declaration(): void
    {
        $base = "<?php\nclass Post\n{\n    protected \$fillable = ['title', 'slug'], \$casts = [];\n}\n";
        $head = "<?php\nclass Post\n{\n    protected \$fillable = ['title', 'slug'], \$casts = ['status' => 'string'];\n}\n";

        $this->assertSame(['status'], EloquentConfig::addedNames($head, $base));
    }
}
