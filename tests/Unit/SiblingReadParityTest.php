<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Analysis\ImpactAnalyzer;
use SanderMuller\Richter\Analysis\SiblingReadParity;
use SanderMuller\Richter\Changes\ChangedFileSymbols;
use SanderMuller\Richter\Changes\MemberChange;
use SanderMuller\Richter\Changes\SiblingReadIndex;
use SanderMuller\Richter\Changes\SiblingReads;
use SanderMuller\Richter\Graph\CodeGraph;
use SanderMuller\Richter\Tests\TestCase;

final class SiblingReadParityTest extends TestCase
{
    private const string MODEL = <<<'PHP'
        <?php
        namespace App\Models;
        /**
         * @property string|null $external_id
         * @property int|null $id
         * @property Customer|null $customer
         */
        class Order {
            public function resolvedExternalId(): ?string { return $this->external_id ?? $this->fallback(); }
        }
        PHP;

    private const string CHANGED = <<<'PHP'
        <?php
        namespace App\Actions;
        use App\Models\Order;
        class CreateTask {
            public function handle(Order $order): void { $this->post($order->external_id); }
        }
        PHP;

    protected function tearDown(): void
    {
        SiblingReadIndex::forget();

        parent::tearDown();
    }

    private function changedFile(string $source = self::CHANGED): ChangedFileSymbols
    {
        return new ChangedFileSymbols(
            'app/Actions/CreateTask.php',
            'App\Actions\CreateTask',
            [new MemberChange('handle', 'method', MemberChange::CHANGE_MODIFIED, true)],
            false,
            siblingReads: SiblingReads::in($source, ['handle']),
        );
    }

    /**
     * @param  array<string, string>  $baseFiles  path => source
     * @param  array<string, string>  $headFiles  path => source
     * @param  list<string>  $directoryListing
     */
    private function index(array $baseFiles, array $headFiles, array $directoryListing, ChangedFileSymbols $changed): SiblingReadIndex
    {
        return SiblingReadIndex::build(
            [$changed],
            static fn (string $directory): array => $directoryListing,
            static fn (string $file): ?string => $baseFiles[$file] ?? null,
            static fn (string $file): ?string => $headFiles[$file] ?? null,
        );
    }

    /**
     * @param  list<string>  $ignore
     * @return list<string>
     */
    private function findings(SiblingReadIndex $index, ChangedFileSymbols $changed, array $ignore = []): array
    {
        SiblingReadIndex::remember($index);

        return new SiblingReadParity($index, $ignore)->findingsFor($changed);
    }

    #[Test]
    public function it_reports_a_bare_read_where_the_declaring_class_resolves_the_same_value(): void
    {
        $changed = $this->changedFile();
        $findings = $this->findings(
            $this->index(['app/Models/Order.php' => self::MODEL], ['app/Models/Order.php' => self::MODEL], [], $changed),
            $changed,
        );

        $this->assertCount(1, $findings);
        // Both sides named, and no claim beyond what was seen.
        $this->assertStringContainsString('App\Actions\CreateTask::handle reads Order->external_id (bare)', $findings[0]);
        $this->assertStringContainsString('App\Models\Order::resolvedExternalId reads it (fallback)', $findings[0]);
        $this->assertStringNotContainsString('forgot', $findings[0]);
        $this->assertStringNotContainsString('fails to handle', $findings[0]);
    }

    #[Test]
    public function a_null_test_in_the_changed_code_is_reported_as_a_null_test_never_as_no_fallback(): void
    {
        $source = str_replace(
            '$this->post($order->external_id);',
            'if ($order->external_id === null) { return; }',
            self::CHANGED,
        );
        $changed = $this->changedFile($source);

        $findings = $this->findings(
            $this->index(['app/Models/Order.php' => self::MODEL], ['app/Models/Order.php' => self::MODEL], [], $changed),
            $changed,
        );

        $this->assertStringContainsString('(null-test)', $findings[0]);
    }

    #[Test]
    public function a_nullable_promoted_property_on_a_non_model_is_reported(): void
    {
        $dto = <<<'PHP'
            <?php
            namespace App\DataObjects;
            class OrderPayload {
                public function __construct(public readonly ?string $external_id = null) {}
                public function resolved(): string { return $this->external_id ?? 'none'; }
            }
            PHP;

        $source = <<<'PHP'
            <?php
            namespace App\Actions;
            use App\DataObjects\OrderPayload;
            class CreateTask {
                public function handle(OrderPayload $p): void { $this->post($p->external_id); }
            }
            PHP;

        $changed = $this->changedFile($source);
        $index = $this->index(
            ['app/DataObjects/OrderPayload.php' => $dto],
            ['app/DataObjects/OrderPayload.php' => $dto],
            [],
            $changed,
        );

        // The lane is not restricted to Eloquent models: a `?string` promoted property carries its
        // own nullability, and outside `app/Models` that declared type is the ONLY source there is.
        // Reading nullability from the union alone made this whole group unreachable.
        $findings = $this->findings($index, $changed);

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('Nullable per its declared type', $findings[0]);
    }

    #[Test]
    public function a_docblock_shorthand_is_reported_as_a_docblock_not_as_a_declared_type(): void
    {
        // `@property ?string` is valid PHPDoc. Reading the `?` back as a declared type would put a
        // claim in the finding that no declaration supports.
        $model = str_replace('@property string|null $external_id', '@property ?string $external_id', self::MODEL);
        $changed = $this->changedFile();

        $findings = $this->findings(
            $this->index(['app/Models/Order.php' => $model], ['app/Models/Order.php' => $model], [], $changed),
            $changed,
        );

        $this->assertStringContainsString('Nullable per its docblock', $findings[0]);
    }

    #[Test]
    public function a_relation_or_cast_property_is_never_reported(): void
    {
        $source = str_replace('$order->external_id', '$order->customer', self::CHANGED);
        $changed = $this->changedFile($source);
        $model = str_replace('$this->external_id ?? $this->fallback()', '$this->customer ?? $this->fallback()', self::MODEL);

        // `Customer|null` says the relation may be unloaded, not that a value is absent here — a
        // different question, and two thirds of the noise when it was not excluded.
        $this->assertSame([], $this->findings(
            $this->index(['app/Models/Order.php' => $model], ['app/Models/Order.php' => $model], [], $changed),
            $changed,
        ));
    }

    #[Test]
    public function a_primary_key_is_never_reported(): void
    {
        $source = str_replace('$order->external_id', '$order->id', self::CHANGED);
        $changed = $this->changedFile($source);
        $model = str_replace('$this->external_id ?? $this->fallback()', '$this->id ?? $this->fallback()', self::MODEL);

        // `int|null` on `id` records "not yet persisted", never a value absent at a call site.
        $this->assertSame([], $this->findings(
            $this->index(['app/Models/Order.php' => $model], ['app/Models/Order.php' => $model], [], $changed),
            $changed,
        ));
    }

    #[Test]
    public function a_property_the_head_tree_makes_non_nullable_is_not_reported(): void
    {
        $changed = $this->changedFile();
        $head = str_replace('@property string|null $external_id', '@property string $external_id', self::MODEL);

        // Nullability is read from HEAD: the diff removed the absence this finding would be about.
        $this->assertSame([], $this->findings(
            $this->index(['app/Models/Order.php' => self::MODEL], ['app/Models/Order.php' => $head], [], $changed),
            $changed,
        ));
    }

    #[Test]
    public function evidence_the_diff_itself_introduces_does_not_count(): void
    {
        $changed = $this->changedFile();
        $baseModel = str_replace('$this->external_id ?? $this->fallback()', '$this->external_id', self::MODEL);

        // The fallback exists only in HEAD. The lane's claim is about the code that was already
        // there, so a guard this same change introduces proves nothing about it.
        $this->assertSame([], $this->findings(
            $this->index(['app/Models/Order.php' => $baseModel], ['app/Models/Order.php' => self::MODEL], [], $changed),
            $changed,
        ));
    }

    #[Test]
    public function a_sibling_in_a_changed_directory_is_evidence_from_the_base_tree(): void
    {
        $sibling = <<<'PHP'
            <?php
            namespace App\Actions;
            use App\Models\Order;
            class ComposeMail {
                public function handle(Order $order): void { $id = $order->external_id ?: 'none'; }
            }
            PHP;

        $changed = $this->changedFile();
        $findings = $this->findings(
            $this->index(
                ['app/Actions/ComposeMail.php' => $sibling, 'app/Models/Order.php' => self::MODEL],
                ['app/Models/Order.php' => self::MODEL],
                ['app/Actions/ComposeMail.php', 'app/Actions/CreateTask.php'],
                $changed,
            ),
            $changed,
        );

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('and 1 more', $findings[0]);
    }

    #[Test]
    public function a_file_absent_from_the_base_tree_contributes_nothing(): void
    {
        $changed = $this->changedFile();

        // An ADDED sibling is not in base by construction, and the model here cannot be read either:
        // no evidence, no finding, no guess.
        $this->assertSame([], $this->findings(
            $this->index([], ['app/Models/Order.php' => self::MODEL], ['app/Actions/Added.php'], $changed),
            $changed,
        ));
    }

    #[Test]
    public function a_declaring_class_the_diff_also_changed_is_still_evidence_through_its_base_version(): void
    {
        $changed = $this->changedFile();
        $model = new ChangedFileSymbols('app/Models/Order.php', 'App\Models\Order', [], false);

        $index = SiblingReadIndex::build(
            [$changed, $model],
            static fn (string $directory): array => [],
            static fn (string $file): ?string => ['app/Models/Order.php' => self::MODEL][$file] ?? null,
            static fn (string $file): ?string => ['app/Models/Order.php' => self::MODEL][$file] ?? null,
        );

        // A feature that edits its model AND an action beside it is the common shape. The model's
        // BASE version still describes the convention the changed action was written against.
        $this->assertCount(1, $this->findings($index, $changed));
    }

    #[Test]
    public function the_changed_file_is_never_its_own_evidence(): void
    {
        $source = <<<'PHP'
            <?php
            namespace App\Actions;
            use App\Models\Order;
            class CreateTask {
                public function handle(Order $order): void { $this->post($order->external_id); }
                public function other(Order $order): void { $id = $order->external_id ?? 'x'; }
            }
            PHP;

        $changed = $this->changedFile($source);

        // The directory listing offers the changed file itself; it must be excluded, or a diff would
        // grade itself against its own other method.
        $this->assertSame([], $this->findings(
            $this->index(['app/Actions/CreateTask.php' => $source], ['app/Models/Order.php' => self::MODEL], ['app/Actions/CreateTask.php'], $changed),
            $changed,
        ));
    }

    #[Test]
    public function an_ignored_pair_or_type_is_silent(): void
    {
        $changed = $this->changedFile();
        $index = $this->index(['app/Models/Order.php' => self::MODEL], ['app/Models/Order.php' => self::MODEL], [], $changed);

        $this->assertCount(1, $this->findings($index, $changed));
        $this->assertSame([], $this->findings($index, $changed, ['App\Models\Order::external_id']));
        $this->assertSame([], $this->findings($index, $changed, ['App\Models\Order']));
    }

    #[Test]
    public function the_lane_is_off_when_the_config_gate_is_off(): void
    {
        config()->set('richter.sibling_read_parity.enabled', false);

        $this->assertNotInstanceOf(SiblingReadParity::class, SiblingReadParity::fromConfig());
        $this->assertNotInstanceOf(SiblingReadParity::class, SiblingReadParity::fromConfig(false));
    }

    #[Test]
    public function the_finding_reaches_the_report_through_the_analyzer(): void
    {
        $changed = $this->changedFile();
        SiblingReadIndex::remember($this->index(
            ['app/Models/Order.php' => self::MODEL],
            ['app/Models/Order.php' => self::MODEL],
            [],
            $changed,
        ));

        $result = new ImpactAnalyzer(new CodeGraph([], hasUnparseableFiles: false))
            ->detectChanges([$changed], payloadParityEnabled: false);

        // File-prefixed, in `findings` — the advisory channel.
        $this->assertNotEmpty(array_filter(
            $result['findings'],
            static fn (string $finding): bool => str_starts_with($finding, 'app/Actions/CreateTask.php: ')
                && str_contains($finding, 'reads Order->external_id'),
        ));

        // And it moves nothing else. The same run with the lane silent grades identically: a style
        // difference between two named sites is not evidence that anything breaks.
        SiblingReadIndex::forget();
        $without = new ImpactAnalyzer(new CodeGraph([], hasUnparseableFiles: false))
            ->detectChanges([$changed], payloadParityEnabled: false);

        $this->assertSame($without['risk'], $result['risk']);
        $this->assertSame($without['riskCause'], $result['riskCause']);
        $this->assertSame($without['hazards'], $result['hazards']);
        $this->assertSame($without['verification'], $result['verification']);
        $this->assertNotSame($without['findings'], $result['findings']);
    }

    #[Test]
    public function a_forgotten_index_reports_nothing(): void
    {
        SiblingReadIndex::remember($this->index(
            ['app/Models/Order.php' => self::MODEL],
            ['app/Models/Order.php' => self::MODEL],
            [],
            $this->changedFile(),
        ));

        // A run drops the previous run's index before it starts. Without that, a long-lived process
        // would compare one diff's reads against another diff's evidence.
        SiblingReadIndex::forget();

        $this->assertSame([], SiblingReadIndex::forRun()->evidence);
        $this->assertSame([], SiblingReadIndex::forRun()->nullableScalars);
    }

    #[Test]
    public function a_removed_member_contributes_no_read(): void
    {
        $changed = new ChangedFileSymbols(
            'app/Actions/CreateTask.php',
            'App\Actions\CreateTask',
            [new MemberChange('handle', 'method', MemberChange::CHANGE_REMOVED, true)],
            false,
            siblingReads: [],
        );

        $this->assertSame([], $this->findings(
            $this->index(['app/Models/Order.php' => self::MODEL], ['app/Models/Order.php' => self::MODEL], [], $changed),
            $changed,
        ));
    }
}
