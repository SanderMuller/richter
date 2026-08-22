<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Analysis\Hazard;
use SanderMuller\Richter\Analysis\Hazards\MigrationHazards;
use SanderMuller\Richter\Analysis\Hazards\ModelTables;
use SanderMuller\Richter\Changes\ChangedFileSymbols;
use SanderMuller\Richter\Changes\FrontendChanges;
use SanderMuller\Richter\Changes\MigrationChanges;
use SanderMuller\Richter\Changes\NonPhpFileChange;
use SanderMuller\Richter\Tests\TestCase;
use SanderMuller\Richter\Tracers\FeatureGateChecker;

final class MigrationHazardsTest extends TestCase
{
    private const string FILE = 'database/migrations/2026_01_01_000000_adjust_articles.php';

    private const string PROJECT = __DIR__ . '/../Fixtures/project';

    protected function setUp(): void
    {
        parent::setUp();

        ModelTables::flush();
    }

    protected function tearDown(): void
    {
        ModelTables::flush();

        parent::tearDown();
    }

    /** An anonymous-class migration, the shape every modern Laravel migration takes. */
    private function migration(string $up, string $down = ''): string
    {
        return <<<PHP
            <?php declare(strict_types=1);

            use Illuminate\\Database\\Migrations\\Migration;
            use Illuminate\\Database\\Schema\\Blueprint;
            use Illuminate\\Support\\Facades\\Schema;

            return new class extends Migration
            {
                public function up(): void
                {
                    {$up}
                }

                public function down(): void
                {
                    {$down}
                }
            };
            PHP;
    }

    /** @return list<Hazard> */
    private function hazards(string $headUp, ?string $baseUp = null, string $headDown = ''): array
    {
        return MigrationHazards::for(
            self::FILE,
            $this->migration($headUp, $headDown),
            $baseUp === null ? null : $this->migration($baseUp),
            self::PROJECT,
        )[0];
    }

    /** @return list<string> */
    private function evidence(string $headUp, ?string $baseUp = null, string $headDown = ''): array
    {
        return array_map(
            static fn (Hazard $hazard): string => $hazard->evidence,
            $this->hazards($headUp, $baseUp, $headDown),
        );
    }

    // ------------------------------------------------------------ dropped columns

    #[Test]
    public function a_dropped_column_in_a_new_migration_is_a_tier_two_hazard(): void
    {
        $hazards = $this->hazards("Schema::table('posts', function (Blueprint \$table) { \$table->dropColumn('subtitle'); });");

        $this->assertCount(1, $hazards);
        $this->assertSame('migration', $hazards[0]->lane);
        $this->assertSame(2, $hazards[0]->tier);
        $this->assertNull($hazards[0]->cwe);
        $this->assertSame('column `posts`.`subtitle` dropped', $hazards[0]->evidence);
    }

    #[Test]
    public function every_column_in_an_array_drop_reports(): void
    {
        $this->assertSame(
            ['column `posts`.`subtitle` dropped', 'column `posts`.`excerpt` dropped'],
            $this->evidence("Schema::table('posts', function (Blueprint \$table) { \$table->dropColumn(['subtitle', 'excerpt']); });"),
        );
    }

    #[Test]
    public function a_variadic_column_drop_reports_each_column(): void
    {
        $this->assertSame(
            ['column `posts`.`subtitle` dropped', 'column `posts`.`excerpt` dropped'],
            $this->evidence("Schema::table('posts', function (Blueprint \$table) { \$table->dropColumn('subtitle', 'excerpt'); });"),
        );
    }

    #[Test]
    public function a_column_name_built_at_runtime_still_reports_the_drop(): void
    {
        $this->assertSame(
            ['a column was dropped from `posts`, under a name richter could not read'],
            $this->evidence("Schema::table('posts', function (Blueprint \$table) { \$table->dropColumn(\$legacy); });"),
        );
    }

    #[Test]
    public function the_timestamps_shorthand_reports_both_columns(): void
    {
        $this->assertSame(
            ['column `posts`.`created_at` dropped', 'column `posts`.`updated_at` dropped'],
            $this->evidence("Schema::table('posts', function (Blueprint \$table) { \$table->dropTimestamps(); });"),
        );
    }

    #[Test]
    public function the_soft_deletes_shorthand_reports_its_default_column(): void
    {
        $this->assertSame(
            ['column `posts`.`deleted_at` dropped'],
            $this->evidence("Schema::table('posts', function (Blueprint \$table) { \$table->dropSoftDeletes(); });"),
        );
    }

    #[Test]
    public function the_soft_deletes_shorthand_reports_the_column_it_is_given(): void
    {
        $this->assertSame(
            ['column `posts`.`archived_at` dropped'],
            $this->evidence("Schema::table('posts', function (Blueprint \$table) { \$table->dropSoftDeletes('archived_at'); });"),
        );
    }

    #[Test]
    public function the_morphs_shorthand_reports_both_columns_it_names(): void
    {
        $this->assertSame(
            ['column `posts`.`author_type` dropped', 'column `posts`.`author_id` dropped'],
            $this->evidence("Schema::table('posts', function (Blueprint \$table) { \$table->dropMorphs('author'); });"),
        );
    }

    #[Test]
    public function the_remember_token_shorthand_reports_its_column(): void
    {
        $this->assertSame(
            ['column `posts`.`remember_token` dropped'],
            $this->evidence("Schema::table('posts', function (Blueprint \$table) { \$table->dropRememberToken(); });"),
        );
    }

    #[Test]
    public function an_additive_blueprint_helper_reports_nothing(): void
    {
        $this->assertSame([], $this->hazards("Schema::table('posts', function (Blueprint \$table) { \$table->timestamps(); \$table->softDeletes(); });"));
    }

    #[Test]
    public function the_timezone_variants_of_the_shorthands_report_the_same_columns(): void
    {
        $this->assertSame(
            ['column `posts`.`created_at` dropped', 'column `posts`.`updated_at` dropped', 'column `posts`.`deleted_at` dropped'],
            $this->evidence("Schema::table('posts', function (Blueprint \$table) { \$table->dropTimestampsTz(); \$table->dropSoftDeletesTz(); });"),
        );
    }

    #[Test]
    public function a_constrained_foreign_id_drop_reports_its_column(): void
    {
        $this->assertSame(
            ['column `posts`.`author_id` dropped'],
            $this->evidence("Schema::table('posts', function (Blueprint \$table) { \$table->dropConstrainedForeignId('author_id'); });"),
        );
    }

    #[Test]
    public function a_drop_inside_a_create_is_read_too(): void
    {
        // Unusual, but `Schema::create()` takes the same blueprint, so refusing to read it would be an
        // arbitrary blind spot rather than a decision.
        $this->assertSame(
            ['column `posts`.`subtitle` dropped'],
            $this->evidence("Schema::create('posts', function (Blueprint \$table) { \$table->id(); \$table->dropColumn('subtitle'); });"),
        );
    }

    // ------------------------------------------------------------ dropped tables

    #[Test]
    public function a_dropped_table_reports(): void
    {
        $this->assertSame(
            ['table `posts` dropped'],
            $this->evidence("Schema::drop('posts');"),
        );
    }

    #[Test]
    public function drop_if_exists_reports_the_same_way(): void
    {
        $this->assertSame(
            ['table `posts` dropped'],
            $this->evidence("Schema::dropIfExists('posts');"),
        );
    }

    // ------------------------------------------------------------ renamed columns

    #[Test]
    public function a_renamed_column_reports_both_names(): void
    {
        $this->assertSame(
            ['column `posts`.`subtitle` renamed to `standfirst`'],
            $this->evidence("Schema::table('posts', function (Blueprint \$table) { \$table->renameColumn('subtitle', 'standfirst'); });"),
        );
    }

    // ------------------------------------------------------------ what stays silent

    #[Test]
    public function an_additive_migration_reports_nothing(): void
    {
        $this->assertSame([], $this->hazards(
            "Schema::table('posts', function (Blueprint \$table) { \$table->string('slug')->nullable(); });",
        ));
    }

    #[Test]
    public function a_conventional_down_is_never_read(): void
    {
        // The shape that would make every migration ever written report: `down()` reverses `up()`,
        // so it drops the column `up()` added.
        $this->assertSame([], $this->hazards(
            headUp: "Schema::table('posts', function (Blueprint \$table) { \$table->string('slug')->nullable(); });",
            headDown: "Schema::table('posts', function (Blueprint \$table) { \$table->dropColumn('slug'); });",
        ));
    }

    #[Test]
    public function an_operation_the_base_already_held_does_not_report(): void
    {
        $up = "Schema::table('posts', function (Blueprint \$table) { \$table->dropColumn('subtitle'); });";

        $this->assertSame([], $this->hazards($up, $up));
    }

    #[Test]
    public function reformatting_an_operation_does_not_report_it_as_new(): void
    {
        $this->assertSame([], $this->hazards(
            "Schema::table('posts', function (Blueprint \$table) {\n    \$table->dropColumn('subtitle');\n});",
            "Schema::table('posts', function (Blueprint \$table) { \$table->dropColumn('subtitle'); });",
        ));
    }

    #[Test]
    public function only_the_operation_the_head_added_reports(): void
    {
        $this->assertSame(
            ['column `posts`.`excerpt` dropped'],
            $this->evidence(
                "Schema::table('posts', function (Blueprint \$table) { \$table->dropColumn('subtitle'); \$table->dropColumn('excerpt'); });",
                "Schema::table('posts', function (Blueprint \$table) { \$table->dropColumn('subtitle'); });",
            ),
        );
    }

    #[Test]
    public function a_table_name_built_at_runtime_is_refused(): void
    {
        $this->assertSame([], $this->hazards("Schema::table(\$name, function (Blueprint \$table) { \$table->dropColumn('subtitle'); });"));
    }

    #[Test]
    public function a_file_declaring_two_up_methods_is_refused(): void
    {
        $source = <<<'PHP'
            <?php declare(strict_types=1);

            class First { public function up(): void { \Illuminate\Support\Facades\Schema::drop('posts'); } }
            class Second { public function up(): void {} }
            PHP;

        $this->assertSame([], MigrationHazards::for(self::FILE, $source, null, self::PROJECT)[0]);
    }

    #[Test]
    public function an_unparseable_migration_is_refused(): void
    {
        $this->assertSame([], MigrationHazards::for(self::FILE, '<?php class {', null, self::PROJECT)[0]);
    }

    #[Test]
    public function a_first_class_callable_reference_is_not_read_as_an_operation(): void
    {
        $this->assertSame([], $this->hazards('Schema::drop(...);'));
    }

    #[Test]
    public function a_matching_method_on_another_facade_is_not_a_schema_operation(): void
    {
        $this->assertSame([], $this->hazards("\Illuminate\Support\Facades\Cache::drop('posts');"));
    }

    #[Test]
    public function a_matching_method_on_another_object_inside_the_closure_is_not_read(): void
    {
        $this->assertSame([], $this->hazards(
            "Schema::table('posts', function (Blueprint \$table) use (\$service) { \$service->dropColumn('subtitle'); });",
        ));
    }

    #[Test]
    public function a_connection_scoped_chain_draws_nothing(): void
    {
        $this->assertSame([], $this->hazards("Schema::connection('reporting')->drop('posts');"));
    }

    #[Test]
    public function a_base_that_does_not_parse_reports_every_head_operation(): void
    {
        // The safe direction: an over-report on a broken base, never a silent all-clear.
        $this->assertSame(
            ['table `posts` dropped'],
            array_map(
                static fn (Hazard $hazard): string => $hazard->evidence,
                MigrationHazards::for(self::FILE, $this->migration("Schema::drop('posts');"), '<?php class {', self::PROJECT)[0],
            ),
        );
    }

    #[Test]
    public function an_arrow_function_callback_is_read_the_same_way(): void
    {
        $this->assertSame(
            ['column `posts`.`subtitle` dropped'],
            $this->evidence("Schema::table('posts', fn (Blueprint \$table) => \$table->dropColumn('subtitle'));"),
        );
    }

    // ------------------------------------------------------------ legacy shape

    #[Test]
    public function a_named_migration_class_is_read_the_same_way(): void
    {
        $source = <<<'PHP'
            <?php declare(strict_types=1);

            use Illuminate\Database\Migrations\Migration;
            use Illuminate\Database\Schema\Blueprint;
            use Illuminate\Support\Facades\Schema;

            class AdjustArticles extends Migration
            {
                public function up(): void
                {
                    Schema::table('posts', function (Blueprint $table) { $table->dropColumn('subtitle'); });
                }
            }
            PHP;

        $hazards = MigrationHazards::for(self::FILE, $source, null, self::PROJECT)[0];

        $this->assertCount(1, $hazards);
        $this->assertSame('column `posts`.`subtitle` dropped', $hazards[0]->evidence);
    }

    // ------------------------------------------------------------ member and ignore key

    #[Test]
    public function the_member_is_the_model_owning_the_table(): void
    {
        $hazards = $this->hazards("Schema::table('posts', function (Blueprint \$table) { \$table->dropColumn('subtitle'); });");

        $this->assertSame('App\Models\Post', $hazards[0]->member);
    }

    #[Test]
    public function a_table_no_model_claims_keeps_the_table_name_as_its_member(): void
    {
        $hazards = $this->hazards("Schema::table('legacy_imports', function (Blueprint \$table) { \$table->dropColumn('subtitle'); });");

        $this->assertSame('legacy_imports', $hazards[0]->member);
    }

    #[Test]
    public function a_column_drop_is_silenced_by_its_table_and_column(): void
    {
        $hazards = $this->hazards("Schema::table('posts', function (Blueprint \$table) { \$table->dropColumn('subtitle'); });");

        $this->assertSame('posts.subtitle', $hazards[0]->suppressionKey());
    }

    #[Test]
    public function a_column_drop_answers_to_its_table_as_well(): void
    {
        $hazards = $this->hazards("Schema::table('posts', function (Blueprint \$table) { \$table->dropColumn('subtitle'); });");

        // Silencing a noisy table once, rather than column by column.
        $this->assertSame(['posts.subtitle', 'posts'], $hazards[0]->suppressionKeys());
    }

    #[Test]
    public function a_table_drop_is_silenced_by_its_table(): void
    {
        $hazards = $this->hazards("Schema::drop('posts');");

        $this->assertSame('posts', $hazards[0]->suppressionKey());
    }

    // ------------------------------------------------------------ dispatch

    #[Test]
    public function the_migrations_root_is_claimed_and_nothing_else_is(): void
    {
        $this->assertTrue(MigrationChanges::handles('database/migrations/2026_01_01_000000_adjust_articles.php'));
        $this->assertFalse(MigrationChanges::handles('database/factories/PostFactory.php'));
        $this->assertFalse(MigrationChanges::handles('database/migrations/notes.md'));
        $this->assertFalse(MigrationChanges::handles('app/Models/Post.php'));
    }

    #[Test]
    public function a_migration_seeds_nothing(): void
    {
        $symbols = MigrationChanges::resolve(
            self::FILE,
            $this->migration("Schema::drop('posts');"),
            null,
            isNew: true,
            hasAdditions: true,
        );

        $this->assertSame([], $symbols->members);
        $this->assertSame('', $symbols->fqcn);
    }

    #[Test]
    public function a_deleted_migration_raises_nothing(): void
    {
        $symbols = MigrationChanges::resolve(
            self::FILE,
            '',
            $this->migration("Schema::drop('posts');"),
            isNew: false,
            hasAdditions: false,
        );

        $this->assertSame([], $symbols->hazards);
        $this->assertSame([], $symbols->findings);
    }

    #[Test]
    public function a_changed_migration_reaches_the_reader_through_the_diff_dispatch(): void
    {
        $head = $this->migration("Schema::drop('posts');");

        $symbols = NonPhpFileChange::resolve(
            self::FILE,
            static fn (): string => $head,
            static fn (): ?string => null,
            isNew: true,
            hasAdditions: true,
            frontend: new FrontendChanges(),
            gates: new FeatureGateChecker([]),
        );

        $this->assertInstanceOf(ChangedFileSymbols::class, $symbols);
        $this->assertSame(['table `posts` dropped'], array_map(
            static fn (Hazard $hazard): string => $hazard->evidence,
            $symbols->hazards,
        ));
    }

    #[Test]
    public function an_unreadable_head_reports_a_finding_rather_than_an_all_clear(): void
    {
        $symbols = MigrationChanges::resolve(self::FILE, null, null, isNew: true, hasAdditions: true);

        $this->assertSame([], $symbols->hazards);
        $this->assertSame(
            [self::FILE . ' could not be read at head — schema operations were not compared'],
            $symbols->findings,
        );
    }

    #[Test]
    public function an_unreadable_base_on_a_modified_migration_reports_a_finding(): void
    {
        $symbols = MigrationChanges::resolve(
            self::FILE,
            $this->migration("Schema::drop('posts');"),
            null,
            isNew: false,
            hasAdditions: true,
        );

        $this->assertSame([], $symbols->hazards);
        $this->assertSame(
            [self::FILE . ' could not be read at base — schema operations were not compared'],
            $symbols->findings,
        );
    }
}
