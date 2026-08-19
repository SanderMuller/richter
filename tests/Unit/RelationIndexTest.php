<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use PhpParser\Node\Stmt\ClassLike;
use PhpParser\NodeFinder;
use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Support\AppFiles;
use SanderMuller\Richter\Support\RelationIndex;
use SanderMuller\Richter\Tests\TestCase;

/**
 * The map Brain drops: which model a relation method returns. Most of what these cover is the refusal
 * side — a target the index cannot name for certain contributes nothing, because every later hop of a
 * traversal is only as sound as this lookup.
 */
final class RelationIndexTest extends TestCase
{
    #[Test]
    public function a_has_many_is_indexed_as_to_many(): void
    {
        $index = $this->index([$this->model('Post', 'public function comments() { return $this->hasMany(\\App\\Models\\Comment::class); }')]);

        $this->assertSame(
            ['owner' => 'App\\Models\\Post', 'method' => 'comments', 'related' => 'App\\Models\\Comment', 'toMany' => true],
            $index->relationOf('App\\Models\\Post', 'comments'),
        );
    }

    #[Test]
    public function a_belongs_to_is_indexed_as_to_one(): void
    {
        $index = $this->index([$this->model('Comment', 'public function author() { return $this->belongsTo(\\App\\Models\\User::class); }')]);

        $this->assertFalse($index->relationOf('App\\Models\\Comment', 'author')['toMany'] ?? true);
    }

    #[Test]
    public function every_to_many_kind_is_recognised(): void
    {
        // The kind decides where a body chain stops, so a miscategorised one would either cut a
        // chain short or continue it past a collection.
        foreach (['hasMany', 'hasManyThrough', 'belongsToMany', 'morphMany', 'morphToMany', 'morphedByMany'] as $kind) {
            $index = $this->index([$this->model('Post', "public function rel() { return \$this->{$kind}(\\App\\Models\\Comment::class); }")]);

            $this->assertTrue($index->relationOf('App\\Models\\Post', 'rel')['toMany'] ?? false, $kind);
        }
    }

    #[Test]
    public function every_to_one_kind_is_recognised(): void
    {
        foreach (['hasOne', 'hasOneThrough', 'belongsTo', 'morphOne'] as $kind) {
            $index = $this->index([$this->model('Post', "public function rel() { return \$this->{$kind}(\\App\\Models\\Comment::class); }")]);

            $this->assertFalse($index->relationOf('App\\Models\\Post', 'rel')['toMany'] ?? true, $kind);
        }
    }

    #[Test]
    public function a_morph_to_names_no_target_and_is_not_indexed(): void
    {
        $index = $this->index([$this->model('Comment', 'public function commentable() { return $this->morphTo(); }')]);

        $this->assertNull($index->relationOf('App\\Models\\Comment', 'commentable'));
    }

    #[Test]
    public function a_variable_target_is_not_indexed(): void
    {
        $index = $this->index([$this->model('Post', 'public function rel(string $class) { return $this->hasMany($class); }')]);

        $this->assertNull($index->relationOf('App\\Models\\Post', 'rel'));
    }

    #[Test]
    public function a_method_naming_two_targets_is_not_indexed(): void
    {
        // The runtime picks from state this cannot see; naming one would send a reader to a model the
        // method may never return.
        $index = $this->index([$this->model('Post', 'public function rel(bool $flag) { return $flag ? $this->hasMany(\\App\\Models\\Comment::class) : $this->hasMany(\\App\\Models\\Tag::class); }')]);

        $this->assertNull($index->relationOf('App\\Models\\Post', 'rel'));
    }

    #[Test]
    public function the_same_target_named_twice_in_one_method_is_still_indexed(): void
    {
        $index = $this->index([$this->model('Post', 'public function rel(bool $flag) { return $flag ? $this->hasMany(\\App\\Models\\Comment::class) : $this->hasMany(\\App\\Models\\Comment::class)->limit(1); }')]);

        $this->assertSame('App\\Models\\Comment', $index->relationOf('App\\Models\\Post', 'rel')['related'] ?? null);
    }

    #[Test]
    public function a_relation_to_a_vendor_model_is_not_indexed(): void
    {
        $index = $this->index([$this->model('Post', 'public function users() { return $this->hasMany(\\Illuminate\\Foundation\\Auth\\User::class); }')]);

        $this->assertNull($index->relationOf('App\\Models\\Post', 'users'));
    }

    #[Test]
    public function a_relationship_call_on_another_receiver_is_not_indexed(): void
    {
        $index = $this->index([$this->model('Post', 'public function rel(\\App\\Models\\Tag $tag) { return $tag->hasMany(\\App\\Models\\Comment::class); }')]);

        $this->assertNull($index->relationOf('App\\Models\\Post', 'rel'));
    }

    #[Test]
    public function an_inherited_relation_resolves_to_the_declaring_ancestor(): void
    {
        $base = $this->model('BaseModel', 'public function comments() { return $this->hasMany(\\App\\Models\\Comment::class); }');
        $subclass = <<<'PHP'
            <?php
            namespace App\Models;
            final class Post extends BaseModel {}
            PHP;

        $relation = $this->index([$base, $subclass])->relationOf('App\\Models\\Post', 'comments');

        $this->assertNotNull($relation);
        $this->assertSame('App\\Models\\BaseModel', $relation['owner']);
        $this->assertSame('App\\Models\\Comment', $relation['related']);
    }

    #[Test]
    public function a_trait_relation_is_copied_into_the_using_class(): void
    {
        // A trait method is copied into the using class, not dispatched — the rule the hierarchy and
        // constant lanes both follow — so the owner is the model, never the trait.
        $relation = $this->index([$this->commentableTrait(), $this->usingModel('Post')])->relationOf('App\\Models\\Post', 'comments');

        $this->assertNotNull($relation);
        $this->assertSame('App\\Models\\Post', $relation['owner']);
        $this->assertSame('App\\Models\\Comment', $relation['related']);
    }

    #[Test]
    public function a_trait_used_by_two_models_resolves_for_each(): void
    {
        $index = $this->index([$this->commentableTrait(), $this->usingModel('Post'), $this->usingModel('Video')]);
        $post = $index->relationOf('App\\Models\\Post', 'comments');
        $video = $index->relationOf('App\\Models\\Video', 'comments');

        $this->assertNotNull($post);
        $this->assertNotNull($video);
        $this->assertSame('App\\Models\\Post', $post['owner']);
        $this->assertSame('App\\Models\\Video', $video['owner']);
    }

    #[Test]
    public function a_class_declaring_the_relation_itself_wins_over_the_trait(): void
    {
        $model = <<<'PHP'
            <?php
            namespace App\Models;
            final class Post
            {
                use \App\Models\Concerns\Commentable;

                public function comments() { return $this->hasMany(\App\Models\Note::class); }
            }
            PHP;

        $this->assertSame(
            'App\\Models\\Note',
            $this->index([$this->commentableTrait(), $model])->relationOf('App\\Models\\Post', 'comments')['related'] ?? null,
        );
    }

    #[Test]
    public function a_model_outside_app_models_is_indexed_too(): void
    {
        // The scan is shape-based: the `$this->hasMany(...)` call is the signal, not the directory.
        $domain = <<<'PHP'
            <?php
            namespace App\Domain\Billing;
            final class Invoice
            {
                public function lines() { return $this->hasMany(\App\Domain\Billing\InvoiceLine::class); }
            }
            PHP;

        $this->assertSame(
            'App\\Domain\\Billing\\InvoiceLine',
            $this->index([$domain])->relationOf('App\\Domain\\Billing\\Invoice', 'lines')['related'] ?? null,
        );
    }

    #[Test]
    public function a_method_that_declares_no_relation_resolves_to_nothing(): void
    {
        $index = $this->index([$this->model('Post', 'public function title() { return "x"; }')]);

        $this->assertNull($index->relationOf('App\\Models\\Post', 'title'));
        $this->assertNull($index->relationOf('App\\Models\\Unknown', 'comments'));
    }

    private function commentableTrait(): string
    {
        return <<<'PHP'
            <?php
            namespace App\Models\Concerns;
            trait Commentable
            {
                public function comments() { return $this->hasMany(\App\Models\Comment::class); }
            }
            PHP;
    }

    private function usingModel(string $name): string
    {
        return <<<PHP
            <?php
            namespace App\\Models;
            final class {$name}
            {
                use \\App\\Models\\Concerns\\Commentable;
            }
            PHP;
    }

    private function model(string $name, string $body): string
    {
        return <<<PHP
            <?php
            namespace App\\Models;
            class {$name}
            {
                {$body}
            }
            PHP;
    }

    /** @param  list<string>  $sources */
    private function index(array $sources): RelationIndex
    {
        $index = new RelationIndex();

        foreach ($sources as $source) {
            $ast = AppFiles::parseResolved($source);
            $this->assertNotNull($ast);
            $index->collect(array_values(new NodeFinder()->findInstanceOf($ast, ClassLike::class)));
        }

        return $index;
    }
}
