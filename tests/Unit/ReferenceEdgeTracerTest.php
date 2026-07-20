<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use App\Actions\Post\ValidateJsonImport;
use App\Handlers\Translation\DatabaseTranslationManager;
use App\Http\Controllers\Post\ReviewController;
use App\Http\Requests\Post\Validators\JsonCommentImportValidator;
use App\Http\Resources\Api\v2\Post\ReviewPlayerResource;
use App\Http\Resources\Api\v2\Post\ReviewResource;
use App\Models\Concerns\WithAudits;
use App\Models\Review;
use App\Rules\CustomCss;
use App\Rules\Document;
use App\Transformers\Api\v2\Review\ExternalReview;
use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Tests\TestCase;
use SanderMuller\Richter\Tracers\ReferenceEdgeTracer;

final class ReferenceEdgeTracerTest extends TestCase
{
    private const string CONTROLLER = ReviewController::class;

    /**
     * @return list<array{source: string, target: string, type: string}>
     */
    private function edges(string $body, string $uses, string $fqcn = self::CONTROLLER): array
    {
        $source = "<?php\nnamespace App\Http\Controllers\Post;\n{$uses}\nclass ReviewController\n{\n    public function show(): mixed\n    {\n        return {$body}\n    }\n}\n";

        return new ReferenceEdgeTracer()->edgesForSource($source, $fqcn);
    }

    #[Test]
    public function it_links_a_resource_make_call_to_the_resource_class(): void
    {
        $edges = $this->edges('ReviewResource::make($review);', 'use App\Http\Resources\Api\v2\Post\ReviewResource;');

        $this->assertContains(['source' => self::CONTROLLER . '::show', 'target' => ReviewResource::class, 'type' => 'resource'], $edges);
    }

    #[Test]
    public function it_links_a_resource_collection_call_and_a_new_expression(): void
    {
        $edges = $this->edges(
            '[ReviewResource::collection($reviews), new PostResource($post)];',
            "use App\Http\Resources\Api\\v2\Post\ReviewResource;\nuse App\Http\Resources\PostResource;",
        );

        $this->assertCount(2, $edges);
        $this->assertContains('App\Http\Resources\PostResource', array_column($edges, 'target'));
    }

    #[Test]
    public function it_links_a_transformer_reference(): void
    {
        $edges = $this->edges('new ExternalReview($review);', 'use App\Transformers\Api\v2\Review\ExternalReview;');

        $this->assertContains(['source' => self::CONTROLLER . '::show', 'target' => ExternalReview::class, 'type' => 'resource'], $edges);
    }

    #[Test]
    public function a_nested_resource_links_to_the_inner_resource_but_not_itself(): void
    {
        $source = "<?php\nnamespace App\Http\Resources;\nuse App\Http\Resources\Api\\v2\Post\ReviewPlayerResource;\nclass ReviewResource\n{\n    public function toArray(): array\n    {\n        return [ReviewPlayerResource::make(\$this->resource), ReviewResource::collection(\$this->children)];\n    }\n}\n";

        $edges = new ReferenceEdgeTracer()->edgesForSource($source, 'App\Http\Resources\ReviewResource');

        $this->assertSame([
            ['source' => 'App\Http\Resources\ReviewResource::toArray', 'target' => ReviewPlayerResource::class, 'type' => 'resource'],
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
            '$post->load([Post::COMMENTS, Post::REVIEWS . \'.\' . Review::ANSWERS]);',
            "use App\Models\Post;\nuse App\Models\Review;",
        );

        $this->assertContains(['source' => self::CONTROLLER . '::show', 'target' => 'App\Models\Post::comments', 'type' => 'loads-relation'], $edges);
        $this->assertContains(['source' => self::CONTROLLER . '::show', 'target' => 'App\Models\Post::reviews', 'type' => 'loads-relation'], $edges);
        $this->assertContains(['source' => self::CONTROLLER . '::show', 'target' => 'App\Models\Review::answers', 'type' => 'loads-relation'], $edges);
    }

    #[Test]
    public function a_model_constant_outside_a_load_call_emits_no_relation_edge(): void
    {
        $this->assertSame([], $this->edges('$post->update([Post::COMMENTS => 1]);', 'use App\Models\Post;'));
    }

    #[Test]
    public function a_column_constant_inside_a_constraint_closure_emits_no_relation_edge(): void
    {
        // The closure body selects columns — those constants are not relation names; only the
        // array key is.
        $edges = $this->edges(
            '$post->load([Post::REVIEWS => fn ($q) => $q->select([Review::ANSWERS, Post::COMMENTS])]);',
            "use App\Models\Post;\nuse App\Models\Review;",
        );

        $this->assertSame([
            ['source' => self::CONTROLLER . '::show', 'target' => 'App\Models\Post::reviews', 'type' => 'loads-relation'],
        ], $edges);
    }

    #[Test]
    public function a_bare_has_call_with_a_model_constant_still_emits_a_relation_edge(): void
    {
        // The checker excludes bare has() (overloaded receivers), but the tracer must keep it —
        // ->has(Model::RELATION) sites are real reach that must not go dark on a relation rename.
        $edges = $this->edges('$query->has(Post::COMMENTS);', 'use App\Models\Post;');

        $this->assertContains(['source' => self::CONTROLLER . '::show', 'target' => 'App\Models\Post::comments', 'type' => 'loads-relation'], $edges);
    }

    #[Test]
    public function a_doesnt_have_call_with_a_model_constant_emits_a_relation_edge(): void
    {
        $edges = $this->edges('$query->doesntHave(Post::REVIEWS);', 'use App\Models\Post;');

        $this->assertContains(['source' => self::CONTROLLER . '::show', 'target' => 'App\Models\Post::reviews', 'type' => 'loads-relation'], $edges);
    }

    #[Test]
    public function it_links_handler_action_and_validator_references(): void
    {
        $edges = $this->edges(
            '[new DatabaseTranslationManager(), ValidateJsonImport::class, JsonCommentImportValidator::class];',
            "use App\Handlers\Translation\DatabaseTranslationManager;\nuse App\Actions\Post\ValidateJsonImport;\nuse App\Http\Requests\Post\Validators\JsonCommentImportValidator;",
        );

        $this->assertContains(['source' => self::CONTROLLER . '::show', 'target' => DatabaseTranslationManager::class, 'type' => 'references'], $edges);
        $this->assertContains(['source' => self::CONTROLLER . '::show', 'target' => ValidateJsonImport::class, 'type' => 'references'], $edges);
        $this->assertContains(['source' => self::CONTROLLER . '::show', 'target' => JsonCommentImportValidator::class, 'type' => 'validates-with'], $edges);
    }

    #[Test]
    public function a_used_app_trait_links_to_its_using_class(): void
    {
        $source = "<?php\nnamespace App\Models;\nuse App\Models\Concerns\WithAudits;\nclass Review\n{\n    use WithAudits;\n}\n";

        $edges = new ReferenceEdgeTracer()->edgesForSource($source, Review::class);

        $this->assertSame([
            ['source' => Review::class, 'target' => WithAudits::class, 'type' => 'uses-trait'],
        ], $edges);
    }

    #[Test]
    public function a_vendor_trait_emits_no_edge(): void
    {
        $source = "<?php\nnamespace App\Models;\nuse Illuminate\Database\Eloquent\SoftDeletes;\nclass Review\n{\n    use SoftDeletes;\n}\n";

        $this->assertSame([], new ReferenceEdgeTracer()->edgesForSource($source, Review::class));
    }

    #[Test]
    public function it_emits_no_edge_for_unrelated_references(): void
    {
        $this->assertSame([], $this->edges('response()->json($review);', 'use App\Models\Review;'));
    }
}
