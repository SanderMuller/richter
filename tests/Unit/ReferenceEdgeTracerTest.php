<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use App\Actions\Video\ValidateJsonImport;
use App\Handlers\Translation\DatabaseTranslationManager;
use App\Http\Controllers\Video\QuestionController;
use App\Http\Requests\Video\Validators\JsonInteractionImportValidator;
use App\Http\Resources\Api\v2\Video\QuestionPlayerResource;
use App\Http\Resources\Api\v2\Video\QuestionResource;
use App\Models\Concerns\WithAudits;
use App\Models\Question;
use App\Rules\CustomCss;
use App\Rules\Document;
use App\Transformers\Api\v2\Question\ExternalQuestion;
use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Tests\TestCase;
use SanderMuller\Richter\Tracers\ReferenceEdgeTracer;

final class ReferenceEdgeTracerTest extends TestCase
{
    private const string CONTROLLER = QuestionController::class;

    /**
     * @return list<array{source: string, target: string, type: string}>
     */
    private function edges(string $body, string $uses, string $fqcn = self::CONTROLLER): array
    {
        $source = "<?php\nnamespace App\Http\Controllers\Video;\n{$uses}\nclass QuestionController\n{\n    public function show(): mixed\n    {\n        return {$body}\n    }\n}\n";

        return new ReferenceEdgeTracer()->edgesForSource($source, $fqcn);
    }

    #[Test]
    public function it_links_a_resource_make_call_to_the_resource_class(): void
    {
        $edges = $this->edges('QuestionResource::make($question);', 'use App\Http\Resources\Api\v2\Video\QuestionResource;');

        $this->assertContains(['source' => self::CONTROLLER . '::show', 'target' => QuestionResource::class, 'type' => 'resource'], $edges);
    }

    #[Test]
    public function it_links_a_resource_collection_call_and_a_new_expression(): void
    {
        $edges = $this->edges(
            '[QuestionResource::collection($questions), new VideoResource($video)];',
            "use App\Http\Resources\Api\\v2\Video\QuestionResource;\nuse App\Http\Resources\VideoResource;",
        );

        $this->assertCount(2, $edges);
        $this->assertContains('App\Http\Resources\VideoResource', array_column($edges, 'target'));
    }

    #[Test]
    public function it_links_a_transformer_reference(): void
    {
        $edges = $this->edges('new ExternalQuestion($question);', 'use App\Transformers\Api\v2\Question\ExternalQuestion;');

        $this->assertContains(['source' => self::CONTROLLER . '::show', 'target' => ExternalQuestion::class, 'type' => 'resource'], $edges);
    }

    #[Test]
    public function a_nested_resource_links_to_the_inner_resource_but_not_itself(): void
    {
        $source = "<?php\nnamespace App\Http\Resources;\nuse App\Http\Resources\Api\\v2\Video\QuestionPlayerResource;\nclass QuestionResource\n{\n    public function toArray(): array\n    {\n        return [QuestionPlayerResource::make(\$this->resource), QuestionResource::collection(\$this->children)];\n    }\n}\n";

        $edges = new ReferenceEdgeTracer()->edgesForSource($source, 'App\Http\Resources\QuestionResource');

        $this->assertSame([
            ['source' => 'App\Http\Resources\QuestionResource::toArray', 'target' => QuestionPlayerResource::class, 'type' => 'resource'],
        ], $edges);
    }

    #[Test]
    public function it_links_a_custom_validation_rule_to_the_referencing_method(): void
    {
        $edges = $this->edges('[new Document(), CustomCss::class];', "use App\Rules\Document;\nuse App\Rules\CustomCss;");

        $this->assertContains(['source' => self::CONTROLLER . '::show', 'target' => Document::class, 'type' => 'validates-with'], $edges);
        $this->assertContains(['source' => self::CONTROLLER . '::show', 'target' => CustomCss::class, 'type' => 'validates-with'], $edges);
    }

    #[Test]
    public function it_links_an_eager_load_by_model_constant_to_the_relation_method_node(): void
    {
        $edges = $this->edges(
            '$video->load([Video::INTERACTIONS, Video::QUESTIONS . \'.\' . Question::ANSWERS]);',
            "use App\Models\Question;\nuse App\Models\Video;",
        );

        $this->assertContains(['source' => self::CONTROLLER . '::show', 'target' => 'App\Models\Video::interactions', 'type' => 'loads-relation'], $edges);
        $this->assertContains(['source' => self::CONTROLLER . '::show', 'target' => 'App\Models\Video::questions', 'type' => 'loads-relation'], $edges);
        $this->assertContains(['source' => self::CONTROLLER . '::show', 'target' => 'App\Models\Question::answers', 'type' => 'loads-relation'], $edges);
    }

    #[Test]
    public function a_model_constant_outside_a_load_call_emits_no_relation_edge(): void
    {
        $this->assertSame([], $this->edges('$video->update([Video::INTERACTIONS => 1]);', 'use App\Models\Video;'));
    }

    #[Test]
    public function a_column_constant_inside_a_constraint_closure_emits_no_relation_edge(): void
    {
        // The closure body selects columns — those constants are not relation names; only the
        // array key is.
        $edges = $this->edges(
            '$video->load([Video::QUESTIONS => fn ($q) => $q->select([Question::ANSWERS, Video::INTERACTIONS])]);',
            "use App\Models\Question;\nuse App\Models\Video;",
        );

        $this->assertSame([
            ['source' => self::CONTROLLER . '::show', 'target' => 'App\Models\Video::questions', 'type' => 'loads-relation'],
        ], $edges);
    }

    #[Test]
    public function a_bare_has_call_with_a_model_constant_still_emits_a_relation_edge(): void
    {
        // The checker excludes bare has() (overloaded receivers), but the tracer must keep it —
        // ->has(Model::RELATION) sites are real reach that must not go dark on a relation rename.
        $edges = $this->edges('$query->has(Video::INTERACTIONS);', 'use App\Models\Video;');

        $this->assertContains(['source' => self::CONTROLLER . '::show', 'target' => 'App\Models\Video::interactions', 'type' => 'loads-relation'], $edges);
    }

    #[Test]
    public function a_doesnt_have_call_with_a_model_constant_emits_a_relation_edge(): void
    {
        $edges = $this->edges('$query->doesntHave(Video::QUESTIONS);', 'use App\Models\Video;');

        $this->assertContains(['source' => self::CONTROLLER . '::show', 'target' => 'App\Models\Video::questions', 'type' => 'loads-relation'], $edges);
    }

    #[Test]
    public function it_links_handler_action_and_validator_references(): void
    {
        $edges = $this->edges(
            '[new DatabaseTranslationManager(), ValidateJsonImport::class, JsonInteractionImportValidator::class];',
            "use App\Handlers\Translation\DatabaseTranslationManager;\nuse App\Actions\Video\ValidateJsonImport;\nuse App\Http\Requests\Video\Validators\JsonInteractionImportValidator;",
        );

        $this->assertContains(['source' => self::CONTROLLER . '::show', 'target' => DatabaseTranslationManager::class, 'type' => 'references'], $edges);
        $this->assertContains(['source' => self::CONTROLLER . '::show', 'target' => ValidateJsonImport::class, 'type' => 'references'], $edges);
        $this->assertContains(['source' => self::CONTROLLER . '::show', 'target' => JsonInteractionImportValidator::class, 'type' => 'validates-with'], $edges);
    }

    #[Test]
    public function a_used_app_trait_links_to_its_using_class(): void
    {
        $source = "<?php\nnamespace App\Models;\nuse App\Models\Concerns\WithAudits;\nclass Question\n{\n    use WithAudits;\n}\n";

        $edges = new ReferenceEdgeTracer()->edgesForSource($source, Question::class);

        $this->assertSame([
            ['source' => Question::class, 'target' => WithAudits::class, 'type' => 'uses-trait'],
        ], $edges);
    }

    #[Test]
    public function a_vendor_trait_emits_no_edge(): void
    {
        $source = "<?php\nnamespace App\Models;\nuse Illuminate\Database\Eloquent\SoftDeletes;\nclass Question\n{\n    use SoftDeletes;\n}\n";

        $this->assertSame([], new ReferenceEdgeTracer()->edgesForSource($source, Question::class));
    }

    #[Test]
    public function it_emits_no_edge_for_unrelated_references(): void
    {
        $this->assertSame([], $this->edges('response()->json($question);', 'use App\Models\Question;'));
    }
}
