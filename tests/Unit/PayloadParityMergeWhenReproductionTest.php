<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Analysis\PayloadParityChecker;
use SanderMuller\Richter\Graph\CodeGraph;
use SanderMuller\Richter\Tests\TestCase;

/**
 * REPRODUCTION (not a permanent fixture): proves why the shipped v0.14.0 PayloadParityChecker is
 * silent at its own default `mirror_threshold => 1.0` on the archetypal model-view Resource — a
 * handful of plain passthrough keys plus a bare `$this->mergeWhen($cond, [ ...model fields... ])`.
 *
 * The consumer dogfood inferred the cause was "derived keys count against the denominator". The
 * real cause, read from the source: model fields exposed ONLY inside the skipped `mergeWhen` stay
 * in the DENOMINATOR (they are in the model's $fillable/casts set) but never reach the NUMERATOR
 * (keysOfArray skips a keyless item), so the ratio can never reach 1.0.
 */
final class PayloadParityMergeWhenReproductionTest extends TestCase
{
    private const string MODEL = 'App\\Models\\Post';

    private const string RESOURCE_FQCN = 'App\\Http\\Resources\\Api\\Post\\PlayerResource';

    private const string RESOURCE_PATH = 'app/Http/Resources/Api/Post/PlayerResource.php';

    /** 5 model fields exposed as plain passthrough keys. */
    private const array VISIBLE_FIELDS = ['title', 'slug', 'status', 'body', 'author'];

    /** 5 model fields exposed ONLY inside a bare mergeWhen block — invisible to the checker. */
    private const array HIDDEN_IN_MERGEWHEN = ['layout', 'theme', 'locale', 'excerpt', 'canonical'];

    private string $projectRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->projectRoot = sys_get_temp_dir() . '/richter-parity-mergewhen-' . bin2hex(random_bytes(8));
        mkdir($this->projectRoot . '/app/Http/Resources/Api/Post', recursive: true);
    }

    protected function tearDown(): void
    {
        new Filesystem()->deleteDirectory($this->projectRoot);

        parent::tearDown();
    }

    #[Test]
    public function silent_at_the_default_1_0_because_mergewhen_fields_inflate_the_denominator(): void
    {
        // A genuinely omitted field: 'spotlight' is added to the model and exposed by NO resource key.
        $this->putArchetypalResource(extraMergeWhenKeys: []);

        $fieldSet = [...self::VISIBLE_FIELDS, ...self::HIDDEN_IN_MERGEWHEN, 'spotlight'];

        $findings = $this->checker(threshold: 1.0)->findingsFor(self::MODEL, $fieldSet, ['spotlight']);

        // 5 visible / 10 pre-existing = 0.5 < 1.0 → the Resource never counts as a mirror, so the
        // real omission is never reported. This is the shipped recall failure.
        $this->assertSame([], $findings, 'DEFECT REPRODUCED: silent at the shipped default 1.0');
    }

    #[Test]
    public function the_same_omission_fires_at_0_2_proving_the_ratio_is_the_only_thing_gating_it(): void
    {
        $this->putArchetypalResource(extraMergeWhenKeys: []);

        $fieldSet = [...self::VISIBLE_FIELDS, ...self::HIDDEN_IN_MERGEWHEN, 'spotlight'];

        $findings = $this->checker(threshold: 0.2)->findingsFor(self::MODEL, $fieldSet, ['spotlight']);

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('spotlight', $findings[0]);
        // Firing at 0.2 proves the Resource evaluates (a bare mergeWhen is skipped, not aborted) —
        // only the 1.0 ratio gate was keeping it silent.
    }

    #[Test]
    public function counting_the_mergewhen_keys_would_restore_a_sane_1_0(): void
    {
        // Simulate the proposed fix: if the mergeWhen's literal model-field keys were in scope, the
        // Resource would expose all 10 pre-existing fields → 10/10 = 1.0 → qualifies at the default.
        // We model that here by making every field a plain passthrough key (what the fix makes the
        // mergeWhen keys equivalent to).
        $this->putResource(self::RESOURCE_PATH, $this->resourceBody(
            plainKeys: [...self::VISIBLE_FIELDS, ...self::HIDDEN_IN_MERGEWHEN],
            mergeWhenKeys: [],
        ));

        $fieldSet = [...self::VISIBLE_FIELDS, ...self::HIDDEN_IN_MERGEWHEN, 'spotlight'];

        $findings = $this->checker(threshold: 1.0)->findingsFor(self::MODEL, $fieldSet, ['spotlight']);

        $this->assertCount(1, $findings, 'With every model field visible, 1.0 is reachable and the omission surfaces');
        $this->assertStringContainsString('spotlight', $findings[0]);
    }

    #[Test]
    public function latent_false_positive_a_field_exposed_inside_mergewhen_reads_as_missing(): void
    {
        // 'excerpt' IS exposed by the Resource — but inside the skipped mergeWhen. The diff adds it.
        // The checker cannot see it, so at any firing threshold it wrongly reports it omitted.
        $this->putArchetypalResource(extraMergeWhenKeys: ['excerpt']);

        $fieldSet = [...self::VISIBLE_FIELDS, ...self::HIDDEN_IN_MERGEWHEN, 'excerpt'];

        $findings = $this->checker(threshold: 0.2)->findingsFor(self::MODEL, $fieldSet, ['excerpt']);

        // The Resource DOES expose 'excerpt' (in the mergeWhen), so a correct checker would stay
        // silent. Today it fires — the false finding the mergeWhen blind spot creates once the
        // threshold is low enough to fire at all.
        $this->assertNotSame([], $findings, 'FALSE POSITIVE REPRODUCED: names a field the Resource actually exposes');
        $this->assertStringContainsString('excerpt', $findings[0]);
    }

    /** @param  list<string>  $extraMergeWhenKeys  extra model fields exposed inside the mergeWhen block */
    private function putArchetypalResource(array $extraMergeWhenKeys): void
    {
        $this->putResource(self::RESOURCE_PATH, $this->resourceBody(
            plainKeys: self::VISIBLE_FIELDS,
            mergeWhenKeys: [...self::HIDDEN_IN_MERGEWHEN, ...$extraMergeWhenKeys],
        ));
    }

    /**
     * @param  list<string>  $plainKeys      fields exposed as plain `'x' => $this->resource->x`
     * @param  list<string>  $mergeWhenKeys  fields exposed only inside a bare `$this->mergeWhen(...)`
     */
    private function resourceBody(array $plainKeys, array $mergeWhenKeys): string
    {
        $plain = implode("\n", array_map(
            static fn (string $field): string => "            '{$field}' => \$this->resource->{$field},",
            $plainKeys,
        ));

        $mergeWhen = $mergeWhenKeys === [] ? '' : "            \$this->mergeWhen(\$this->editMode(\$request), [\n" . implode("\n", array_map(
            static fn (string $field): string => "                '{$field}' => \$this->resource->{$field},",
            $mergeWhenKeys,
        )) . "\n            ]),\n";

        return <<<PHP
            <?php declare(strict_types=1);
            namespace App\\Http\\Resources\\Api\\Post;
            use Illuminate\\Http\\Resources\\Json\\JsonResource;
            final class PlayerResource extends JsonResource
            {
                public function toArray(\$request): array
                {
                    return [
            {$plain}
                        'preview_url' => \$this->whenLoaded('preview'),
            {$mergeWhen}        ];
                }
            }
            PHP;
    }

    private function checker(float $threshold): PayloadParityChecker
    {
        $graph = new CodeGraph([
            ['source' => 'App\Http\Controllers\Post\PlayerController::show', 'target' => self::MODEL . '::reviews', 'type' => 'loads-relation'],
            ['source' => 'App\Http\Controllers\Post\PlayerController::show', 'target' => self::RESOURCE_FQCN, 'type' => 'resource'],
        ], hasUnparseableFiles: false, nodeMetadata: [
            self::RESOURCE_FQCN => ['file' => self::RESOURCE_PATH],
        ]);

        return new PayloadParityChecker($graph, $threshold, [], $this->projectRoot);
    }

    private function putResource(string $relativePath, string $body): void
    {
        $absolute = "{$this->projectRoot}/{$relativePath}";
        @mkdir(dirname($absolute), recursive: true);
        file_put_contents($absolute, $body);
    }
}
