<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Use_;
use PhpParser\NodeFinder;
use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Support\AppFiles;
use SanderMuller\Richter\Support\RelationIndex;
use SanderMuller\Richter\Tests\TestCase;
use SanderMuller\Richter\Tracers\RelationTraversalTracer;

/**
 * A body that walks a relation. The positive cases are one lookup each; the interesting half is where
 * a chain must stop — past a collection, on an untyped root, on a name the index cannot place —
 * because an edge drawn there names a model the expression never reaches.
 */
final class RelationTraversalTracerTest extends TestCase
{
    private const string MODELS = <<<'PHP'
        <?php
        namespace App\Models;
        class Post
        {
            public function comments() { return $this->hasMany(\App\Models\Comment::class); }
            public function author() { return $this->belongsTo(\App\Models\User::class); }
        }
        class Comment
        {
            use \App\Models\Concerns\HasStatus;
            public function author() { return $this->belongsTo(\App\Models\User::class); }
            public function publish(): void {}
            public function scopePublished($query) { return $query; }
            #[\Illuminate\Database\Eloquent\Attributes\Scope]
            protected function visible($query): void {}
        }
        class User
        {
            public function team() { return $this->belongsTo(\App\Models\Team::class); }
            public function posts() { return $this->hasMany(\App\Models\Post::class); }
            public function notify(): void {}
        }
        class Team {}
        PHP;

    private const string TRAITS = <<<'PHP'
        <?php
        namespace App\Models\Concerns;
        trait HasStatus
        {
            public function scopeActive($query) { return $query; }
        }
        PHP;

    #[Test]
    public function a_typed_property_root_walks_every_to_one_hop(): void
    {
        $targets = $this->targets(<<<'PHP'
            <?php
            namespace App\Services;
            final class Reporter
            {
                public function __construct(private \App\Models\Post $post) {}

                public function run(): void
                {
                    $name = $this->post->author->team;
                }
            }
            PHP);

        $this->assertSame(['App\\Models\\Post::author', 'App\\Models\\User::team'], $targets);
    }

    #[Test]
    public function a_typed_parameter_root_resolves(): void
    {
        $targets = $this->targets(<<<'PHP'
            <?php
            namespace App\Services;
            final class Reporter
            {
                public function run(\App\Models\Comment $comment): void
                {
                    $name = $comment->author;
                }
            }
            PHP);

        $this->assertSame(['App\\Models\\Comment::author'], $targets);
    }

    #[Test]
    public function this_inside_the_model_itself_is_a_root(): void
    {
        $targets = $this->targets(<<<'PHP'
            <?php
            namespace App\Models;
            class Article extends Post
            {
                public function firstAuthor(): void
                {
                    $author = $this->author->team;
                }
            }
            PHP);

        $this->assertSame(['App\\Models\\Post::author', 'App\\Models\\User::team'], $targets);
    }

    #[Test]
    public function a_chain_stops_after_a_to_many_hop(): void
    {
        // `$post->comments` is a collection, so `->author` after it is a collection member. Drawing
        // `Comment::author` there would name an edge the expression does not make.
        $targets = $this->targets(<<<'PHP'
            <?php
            namespace App\Services;
            final class Reporter
            {
                public function run(\App\Models\Post $post): void
                {
                    $name = $post->comments->author;
                }
            }
            PHP);

        $this->assertSame(['App\\Models\\Post::comments'], $targets);
    }

    #[Test]
    public function the_method_form_draws_its_hop_and_stops(): void
    {
        $targets = $this->targets(<<<'PHP'
            <?php
            namespace App\Services;
            final class Reporter
            {
                public function run(\App\Models\Comment $comment): void
                {
                    $query = $comment->author()->where('active', true);
                }
            }
            PHP);

        $this->assertSame(['App\\Models\\Comment::author'], $targets);
    }

    #[Test]
    public function the_method_form_on_a_property_root_also_draws_its_hop(): void
    {
        // `$this->post->comments()` — the call is the second name, so the property root resolves and
        // the chain still ends at the call.
        $targets = $this->targets(<<<'PHP'
            <?php
            namespace App\Services;
            final class Reporter
            {
                public function __construct(private \App\Models\Post $post) {}

                public function run(): void
                {
                    $query = $this->post->comments()->latest();
                }
            }
            PHP);

        $this->assertSame(['App\\Models\\Post::comments'], $targets);
    }

    #[Test]
    public function an_untyped_root_draws_nothing(): void
    {
        $this->assertSame([], $this->targets(<<<'PHP'
            <?php
            namespace App\Services;
            final class Reporter
            {
                private $post;

                public function run($comment): void
                {
                    $a = $this->post->author;
                    $b = $comment->author;
                }
            }
            PHP));
    }

    #[Test]
    public function a_union_typed_root_draws_nothing(): void
    {
        // Two possible receivers, so a hop would have to pick one. It picks neither.
        $this->assertSame([], $this->targets(<<<'PHP'
            <?php
            namespace App\Services;
            final class Reporter
            {
                public function run(\App\Models\Post|\App\Models\Comment $subject): void
                {
                    $name = $subject->author;
                }
            }
            PHP));
    }

    #[Test]
    public function a_nullable_typed_root_resolves(): void
    {
        $targets = $this->targets(<<<'PHP'
            <?php
            namespace App\Services;
            final class Reporter
            {
                public function run(?\App\Models\Comment $comment): void
                {
                    $name = $comment?->author;
                }
            }
            PHP);

        $this->assertSame(['App\\Models\\Comment::author'], $targets);
    }

    #[Test]
    public function a_property_that_is_not_a_relation_draws_nothing(): void
    {
        $this->assertSame([], $this->targets(<<<'PHP'
            <?php
            namespace App\Services;
            final class Reporter
            {
                public function run(\App\Models\Post $post): void
                {
                    $title = $post->title;
                }
            }
            PHP));
    }

    #[Test]
    public function reading_a_typed_property_without_a_further_hop_draws_nothing(): void
    {
        // `$this->post` reads a property. The relation would be whatever came after it, and nothing
        // did.
        $this->assertSame([], $this->targets(<<<'PHP'
            <?php
            namespace App\Services;
            final class Reporter
            {
                public function __construct(private \App\Models\Post $post) {}

                public function run(): \App\Models\Post
                {
                    return $this->post;
                }
            }
            PHP));
    }

    #[Test]
    public function a_non_model_receiver_draws_nothing(): void
    {
        $this->assertSame([], $this->targets(<<<'PHP'
            <?php
            namespace App\Services;
            final class Reporter
            {
                public function __construct(private \App\Services\Settings $settings) {}

                public function run(): void
                {
                    $value = $this->settings->author;
                }
            }
            PHP));
    }

    #[Test]
    public function the_same_traversal_written_twice_draws_one_edge(): void
    {
        $targets = $this->targets(<<<'PHP'
            <?php
            namespace App\Services;
            final class Reporter
            {
                public function run(\App\Models\Comment $comment): void
                {
                    $a = $comment->author;
                    $b = $comment->author;
                }
            }
            PHP);

        $this->assertSame(['App\\Models\\Comment::author'], $targets);
    }

    #[Test]
    public function the_edge_names_the_calling_member(): void
    {
        $edges = $this->edges(<<<'PHP'
            <?php
            namespace App\Services;
            final class Reporter
            {
                public function run(\App\Models\Comment $comment): void
                {
                    $name = $comment->author;
                }
            }
            PHP);

        $this->assertSame([[
            'source' => 'App\\Services\\Reporter::run',
            'target' => 'App\\Models\\Comment::author',
            'type' => 'loads-relation',
        ]], $edges);
    }

    #[Test]
    public function a_model_returning_static_is_a_root(): void
    {
        $targets = $this->targets(<<<'PHP'
            <?php
            namespace App\Services;
            final class Reporter
            {
                public function run(): void
                {
                    $name = \App\Models\Comment::find(1)->author;
                }
            }
            PHP);

        $this->assertSame(['App\\Models\\Comment::author'], $targets);
    }

    #[Test]
    public function a_builder_returning_static_is_not_a_root(): void
    {
        // `query()` hands back a builder, so `->author` on it is a builder method rather than a
        // relation on the model.
        $this->assertSame([], $this->targets(<<<'PHP'
            <?php
            namespace App\Services;
            final class Reporter
            {
                public function run(): void
                {
                    $name = \App\Models\Comment::query()->author;
                }
            }
            PHP));
    }

    #[Test]
    public function a_local_bound_to_new_is_a_root(): void
    {
        $targets = $this->targets(<<<'PHP'
            <?php
            namespace App\Services;
            final class Reporter
            {
                public function run(): void
                {
                    $comment = new \App\Models\Comment();
                    $name = $comment->author;
                }
            }
            PHP);

        $this->assertSame(['App\\Models\\Comment::author'], $targets);
    }

    #[Test]
    public function a_local_bound_to_a_resolved_chain_carries_that_model(): void
    {
        // The hop that binds the local is drawn, and so is the hop taken through it afterwards.
        $targets = $this->targets(<<<'PHP'
            <?php
            namespace App\Services;
            final class Reporter
            {
                public function run(\App\Models\Post $post): void
                {
                    $author = $post->author;
                    $team = $author->team;
                }
            }
            PHP);

        $this->assertSame(['App\\Models\\Post::author', 'App\\Models\\User::team'], $targets);
    }

    #[Test]
    public function a_var_docblock_types_a_local(): void
    {
        $targets = $this->targets(<<<'PHP'
            <?php
            namespace App\Services;
            final class Reporter
            {
                public function run(): void
                {
                    /** @var \App\Models\Comment $comment */
                    $comment = resolve('comment');
                    $name = $comment->author;
                }
            }
            PHP);

        $this->assertSame(['App\\Models\\Comment::author'], $targets);
    }

    #[Test]
    public function a_nullable_docblock_types_a_local(): void
    {
        foreach (['?\\App\\Models\\Comment', '\\App\\Models\\Comment|null'] as $declared) {
            $targets = $this->targets(<<<PHP
                <?php
                namespace App\Services;
                final class Reporter
                {
                    public function run(): void
                    {
                        /** @var {$declared} \$comment */
                        \$comment = resolve('comment');
                        \$name = \$comment->author;
                    }
                }
                PHP);

            $this->assertSame(['App\\Models\\Comment::author'], $targets, $declared);
        }
    }

    #[Test]
    public function an_imported_docblock_name_resolves_through_the_files_use_statements(): void
    {
        // `@var Comment $comment` is the way the shape is actually written. Name resolution never
        // sees a docblock, so the imports have to be read for it.
        $targets = $this->targets(<<<'PHP'
            <?php
            namespace App\Services;
            use App\Models\Comment;
            final class Reporter
            {
                public function run(): void
                {
                    /** @var Comment $comment */
                    $comment = resolve('comment');
                    $name = $comment->author;
                }
            }
            PHP);

        $this->assertSame(['App\\Models\\Comment::author'], $targets);
    }

    #[Test]
    public function a_union_docblock_types_nothing(): void
    {
        $this->assertSame([], $this->targets(<<<'PHP'
            <?php
            namespace App\Services;
            final class Reporter
            {
                public function run(): void
                {
                    /** @var \App\Models\Comment|\App\Models\Post $subject */
                    $subject = resolve('subject');
                    $name = $subject->author;
                }
            }
            PHP));
    }

    #[Test]
    public function reassigning_a_local_to_something_untypable_clears_it(): void
    {
        // The second read happens after the rebind, so the old type must not still stand.
        $targets = $this->targets(<<<'PHP'
            <?php
            namespace App\Services;
            final class Reporter
            {
                public function run(): void
                {
                    $subject = new \App\Models\Comment();
                    $subject = resolve('something');
                    $name = $subject->author;
                }
            }
            PHP);

        $this->assertSame([], $targets);
    }

    #[Test]
    public function a_closure_binding_a_name_does_not_retype_the_outer_one(): void
    {
        // The closure's `$comment` is its own variable. Letting it out would type the outer
        // `$comment` as a Post and draw an edge to a relation the outer read never reaches.
        $targets = $this->targets(<<<'PHP'
            <?php
            namespace App\Services;
            final class Reporter
            {
                public function run(\App\Models\Comment $comment): void
                {
                    $fn = function () { $comment = new \App\Models\Post(); return $comment->comments; };
                    $name = $comment->author;
                }
            }
            PHP);

        $this->assertSame(['App\\Models\\Post::comments', 'App\\Models\\Comment::author'], $targets);
    }

    #[Test]
    public function a_closure_does_not_inherit_the_enclosing_locals(): void
    {
        // A variable the closure imports by `use` has a type this cannot follow across the boundary,
        // so the chain inside stops rather than assuming the outer binding still holds.
        $this->assertSame([], $this->targets(<<<'PHP'
            <?php
            namespace App\Services;
            final class Reporter
            {
                public function run(\App\Models\Comment $comment): void
                {
                    $fn = function () use ($comment) { return $comment->author; };
                }
            }
            PHP));
    }

    #[Test]
    public function a_local_read_before_it_is_bound_draws_nothing(): void
    {
        $this->assertSame([], $this->targets(<<<'PHP'
            <?php
            namespace App\Services;
            final class Reporter
            {
                public function run(): void
                {
                    $name = $comment->author;
                    $comment = new \App\Models\Comment();
                }
            }
            PHP));
    }

    #[Test]
    public function a_find_with_an_array_id_is_not_a_root(): void
    {
        // `Builder::find()` hands an array to `findMany()`, so the chain would be reading a
        // collection. Only a scalar literal id cannot turn out to be one.
        $this->assertSame([], $this->targets(<<<'PHP'
            <?php
            namespace App\Services;
            final class Reporter
            {
                public function run(): void
                {
                    $name = \App\Models\Comment::find([1, 2])->author;
                    $other = \App\Models\Comment::find($ids)->author;
                }
            }
            PHP));
    }

    #[Test]
    public function a_conditional_binding_clears_the_variable(): void
    {
        // Source order cannot say which branch ran, and a sibling branch may bind another model.
        $this->assertSame([], $this->targets(<<<'PHP'
            <?php
            namespace App\Services;
            final class Reporter
            {
                public function run(bool $flag): void
                {
                    $subject = new \App\Models\Comment();

                    if ($flag) {
                        $subject = new \App\Models\Post();
                    }

                    $name = $subject->author;
                }
            }
            PHP));
    }

    #[Test]
    public function a_binding_inside_a_loop_clears_the_variable(): void
    {
        $this->assertSame([], $this->targets(<<<'PHP'
            <?php
            namespace App\Services;
            final class Reporter
            {
                public function run(array $rows): void
                {
                    foreach ($rows as $row) {
                        $subject = new \App\Models\Comment();
                    }

                    $name = $subject->author;
                }
            }
            PHP));
    }

    #[Test]
    public function a_nested_assignment_types_neither_name(): void
    {
        // `$a = helper($a = new Comment())` runs inside out and the outer call decides what the name
        // holds, which is not knowable here.
        $this->assertSame([], $this->targets(<<<'PHP'
            <?php
            namespace App\Services;
            final class Reporter
            {
                public function run(): void
                {
                    $subject = \resolve($subject = new \App\Models\Comment());
                    $name = $subject->author;
                }
            }
            PHP));
    }

    #[Test]
    public function a_later_docblock_does_not_type_an_earlier_read(): void
    {
        // A `@var` speaks for its own statement. Applied method-wide, the first read below would be
        // traced from Post and draw an edge the expression never reaches.
        $targets = $this->targets(<<<'PHP'
            <?php
            namespace App\Services;
            final class Reporter
            {
                public function run(): void
                {
                    $subject = resolve('one');
                    $first = $subject->author;

                    /** @var \App\Models\Post $subject */
                    $subject = resolve('two');
                    $second = $subject->comments;
                }
            }
            PHP);

        $this->assertSame(['App\\Models\\Post::comments'], $targets);
    }

    #[Test]
    public function an_aliased_import_resolves_a_docblock_name(): void
    {
        $targets = $this->targets(<<<'PHP'
            <?php
            namespace App\Services;
            use App\Models\Comment as CommentModel;
            final class Reporter
            {
                public function run(): void
                {
                    /** @var CommentModel $subject */
                    $subject = resolve('subject');
                    $name = $subject->author;
                }
            }
            PHP);

        $this->assertSame(['App\\Models\\Comment::author'], $targets);
    }

    #[Test]
    public function a_function_import_never_resolves_a_docblock_name(): void
    {
        // `use function` imports no class, so a docblock that happens to match its name is not its
        // to answer for.
        $this->assertSame([], $this->targets(<<<'PHP'
            <?php
            namespace App\Services;
            use function App\Models\Comment;
            final class Reporter
            {
                public function run(): void
                {
                    /** @var Comment $subject */
                    $subject = resolve('subject');
                    $name = $subject->author;
                }
            }
            PHP));
    }

    #[Test]
    public function an_own_method_with_a_model_return_type_roots_the_chain(): void
    {
        $targets = $this->targets(<<<'PHP'
            <?php
            namespace App\Services;
            use App\Models\Post;
            final class Reporter
            {
                public function run(): void
                {
                    $name = $this->post()->author->team;
                    $other = $this?->post()?->author;
                }

                private function post(): ?Post { return null; }
            }
            PHP);

        $this->assertSame(['App\\Models\\Post::author', 'App\\Models\\User::team'], $targets);
    }

    #[Test]
    public function a_later_relation_call_still_stops_a_chain_rooted_in_an_own_method(): void
    {
        $targets = $this->targets(<<<'PHP'
            <?php
            namespace App\Services;
            use App\Models\Comment;
            final class Reporter
            {
                public function run(): void
                {
                    $query = $this->comment()->author()->team;
                }

                private function comment(): Comment { return new Comment(); }
            }
            PHP);

        // `author()` returns a builder, so `team` is read off the builder, not off `User`.
        $this->assertSame(['App\\Models\\Comment::author'], $targets);
    }

    #[Test]
    public function an_own_relation_with_an_app_relation_return_type_stays_a_relation_hop(): void
    {
        $targets = $this->targets(<<<'PHP'
            <?php
            namespace App\Models;
            class Article
            {
                public function remarks(): \App\Relations\RemarkRelation { return $this->hasMany(\App\Models\Comment::class); }

                public function count(): int { return $this->remarks()->count(); }
            }
            PHP);

        $this->assertSame(['App\\Models\\Article::remarks'], $targets);
    }

    #[Test]
    public function an_own_method_without_an_app_class_return_type_roots_nothing(): void
    {
        $this->assertSame([], $this->targets(<<<'PHP'
            <?php
            namespace App\Services;
            final class Reporter
            {
                public function run(): void
                {
                    $a = $this->untyped()->author;
                    $b = $this->either()->author;
                    $c = $this->vendor()->author;
                    $d = $this->post();
                }

                private function untyped() { return null; }
                private function either(): \App\Models\Post|\App\Models\Comment { return new \App\Models\Post(); }
                private function vendor(): \Illuminate\Support\Collection { return collect(); }
                private function post(): \App\Models\Post { return new \App\Models\Post(); }
            }
            PHP));
    }

    #[Test]
    public function a_call_at_the_end_of_a_to_one_chain_names_the_loaded_model(): void
    {
        $edges = $this->edges(<<<'PHP'
            <?php
            namespace App\Services;
            final class Reporter
            {
                public function __construct(private \App\Models\Post $post) {}

                public function run(): void
                {
                    $this->post->author?->notify();
                }
            }
            PHP);

        $this->assertContains(['source' => 'App\\Services\\Reporter::run', 'target' => 'App\\Models\\Post::author', 'type' => 'loads-relation'], $edges);
        $this->assertContains(['source' => 'App\\Services\\Reporter::run', 'target' => 'App\\Models\\User::notify', 'type' => 'instance-call'], $edges);
    }

    #[Test]
    public function a_local_bound_through_a_relation_carries_the_loaded_model_to_a_call(): void
    {
        $this->assertSame(['App\\Models\\User::notify'], $this->instanceCalls(<<<'PHP'
            <?php
            namespace App\Services;
            final class Reporter
            {
                public function run(\App\Models\Post $post): void
                {
                    $author = $post->author;
                    $author->notify();
                }
            }
            PHP));
    }

    #[Test]
    public function a_foreach_over_a_to_many_relation_types_its_value_variable(): void
    {
        $edges = $this->edges(<<<'PHP'
            <?php
            namespace App\Services;
            final class Publisher
            {
                public function run(\App\Models\Post $post): void
                {
                    foreach ($post->comments as $comment) {
                        $comment->publish();
                        $comment->author->notify();
                    }
                }
            }
            PHP);

        $this->assertSame(
            ['App\\Models\\Post::comments', 'App\\Models\\Comment::publish', 'App\\Models\\Comment::author', 'App\\Models\\User::notify'],
            array_column($edges, 'target'),
        );
        $this->assertSame(['loads-relation', 'instance-call', 'loads-relation', 'instance-call'], array_column($edges, 'type'));
    }

    #[Test]
    public function a_call_on_a_model_no_relation_loaded_draws_no_instance_call(): void
    {
        // A typed parameter, a `new` and a static all name the class in the source already, and a
        // call on a collection or on a relation builder is not a call on the model.
        $this->assertSame([], $this->instanceCalls(<<<'PHP'
            <?php
            namespace App\Services;
            final class Publisher
            {
                public function run(\App\Models\Comment $comment, \App\Models\Post $post): void
                {
                    $comment->publish();
                    new \App\Models\Comment()->publish();
                    \App\Models\Comment::find(1)->publish();
                    $post->comments->publish();
                    $post->author()->notify();

                    foreach ($post->comments() as $item) {
                        $item->publish();
                    }
                }
            }
            PHP));
    }

    #[Test]
    public function a_foreach_over_anything_else_clears_the_names_it_binds(): void
    {
        $this->assertSame([], $this->instanceCalls(<<<'PHP'
            <?php
            namespace App\Services;
            final class Publisher
            {
                public function run(\App\Models\Post $post, array $rows): void
                {
                    $comment = $post->author;
                    $key = $post->author;

                    foreach ($rows as $key => $comment) {
                        $comment->notify();
                        $key->notify();
                    }
                }
            }
            PHP));
    }

    #[Test]
    public function a_foreach_key_over_a_relation_is_not_the_model(): void
    {
        $this->assertSame(['App\\Models\\Comment::publish'], $this->instanceCalls(<<<'PHP'
            <?php
            namespace App\Services;
            final class Publisher
            {
                public function run(\App\Models\Post $post): void
                {
                    $index = $post->author;

                    foreach ($post->comments as $index => $comment) {
                        $index->notify();
                        $comment->publish();
                    }
                }
            }
            PHP));
    }

    #[Test]
    public function a_foreach_reads_its_relation_before_it_rebinds_the_root_name(): void
    {
        $this->assertSame(['App\\Models\\Comment::publish'], $this->instanceCalls(<<<'PHP'
            <?php
            namespace App\Services;
            final class Publisher
            {
                public function run(\App\Models\Post $post): void
                {
                    foreach ($post->comments as $post) {
                        $post->publish();
                    }
                }
            }
            PHP));
    }

    #[Test]
    public function a_foreach_over_a_to_one_relation_iterates_attributes_not_models(): void
    {
        $this->assertSame([], $this->instanceCalls(<<<'PHP'
            <?php
            namespace App\Services;
            final class Publisher
            {
                public function run(\App\Models\Post $post): void
                {
                    foreach ($post->author as $field) {
                        $field->notify();
                    }
                }
            }
            PHP));
    }

    #[Test]
    public function a_write_that_states_no_type_clears_a_loaded_local(): void
    {
        $this->assertSame([], $this->instanceCalls(<<<'PHP'
            <?php
            namespace App\Services;
            final class Notifier
            {
                public function run(\App\Models\Post $post, array $rows): void
                {
                    $a = $post->author;
                    [$a] = $rows;
                    $a->notify();

                    $b = $post->author;
                    $b = &$rows[0];
                    $b->notify();

                    $c = $post->author;
                    try {
                        $rows[] = 1;
                    } catch (\Exception $c) {
                        $c->notify();
                    }

                    $d = $post->author;
                    $d .= 'x';
                    $d->notify();
                }
            }
            PHP));
    }

    #[Test]
    public function a_foreach_value_is_the_loaded_model_only_inside_the_loop(): void
    {
        $this->assertSame(['App\\Models\\Comment::publish'], $this->instanceCalls(<<<'PHP'
            <?php
            namespace App\Services;
            final class Publisher
            {
                public function run(\App\Models\Post $post, bool $flag): void
                {
                    if ($flag) {
                        $item = $post->author;
                    } else {
                        foreach ($post->comments as $item) {
                            $item->publish();
                        }
                    }

                    // After the loop, `$item` may be the author or the last comment.
                    $item->notify();
                }
            }
            PHP));
    }

    #[Test]
    public function a_foreach_over_a_to_many_hop_mid_chain_binds_nothing(): void
    {
        $this->assertSame([], $this->instanceCalls(<<<'PHP'
            <?php
            namespace App\Services;
            final class Publisher
            {
                public function run(\App\Models\Post $post): void
                {
                    foreach ($post->comments->author as $item) {
                        $item->notify();
                    }

                    foreach ($post->comments as [$first]) {
                        $first->publish();
                    }
                }
            }
            PHP));
    }

    #[Test]
    public function a_foreach_after_several_hops_binds_the_last_relation_model(): void
    {
        $this->assertSame(['App\\Models\\User::notify'], $this->instanceCalls(<<<'PHP'
            <?php
            namespace App\Services;
            final class Publisher
            {
                public function run(\App\Models\Comment $comment): void
                {
                    foreach ($comment->author->posts as $post) {
                        $post->author->notify();
                    }
                }
            }
            PHP));
    }

    #[Test]
    public function a_call_on_a_loaded_model_counts_on_the_right_of_an_assignment_and_after_an_own_method(): void
    {
        $this->assertSame(['App\\Models\\User::notify', 'App\\Models\\User::notify'], array_column(array_values(array_filter(
            $this->edges(<<<'PHP'
                <?php
                namespace App\Services;
                use App\Models\Post;
                final class Notifier
                {
                    public function first(Post $post): void
                    {
                        $result = $post->author->notify();
                    }

                    public function second(): void
                    {
                        $this->post()->author->notify();
                    }

                    private function post(): Post { return new Post(); }
                }
                PHP),
            static fn (array $edge): bool => $edge['type'] === 'instance-call',
        )), 'target'));
    }

    #[Test]
    public function a_scope_on_a_relation_builder_names_the_scope_method(): void
    {
        $this->assertSame(
            ['App\\Models\\Comment::scopePublished', 'App\\Models\\Comment::visible', 'App\\Models\\Comment::scopeActive'],
            $this->instanceCalls(<<<'PHP'
                <?php
                namespace App\Services;
                final class Feed
                {
                    public function run(\App\Models\Post $post): void
                    {
                        $post->comments()->published()->get();
                        $post->comments()->where('approved', true)->visible()->latest()->get();
                        $post->comments()->orderByDesc('id')->active()->first();
                    }
                }
                PHP),
        );
    }

    #[Test]
    public function a_scope_inside_the_model_reads_its_own_relation(): void
    {
        $this->assertSame(['App\\Models\\Comment::scopePublished'], $this->instanceCalls(<<<'PHP'
            <?php
            namespace App\Models;
            class Article
            {
                public function comments() { return $this->hasMany(\App\Models\Comment::class); }

                public function latestPublished(): mixed
                {
                    return $this->comments()->published()->first();
                }
            }
            PHP));
    }

    #[Test]
    public function a_builder_chain_ends_at_a_call_that_leaves_the_builder(): void
    {
        // `get()` returns a collection and `count()` a number; `notify()` is no scope of Comment.
        $this->assertSame([], $this->instanceCalls(<<<'PHP'
            <?php
            namespace App\Services;
            final class Feed
            {
                public function run(\App\Models\Post $post): void
                {
                    $post->comments()->get()->published();
                    $post->comments()->count();
                    $post->comments()->notify()->published();
                    $post->comments()->publish();
                    $post->comments->published();
                }
            }
            PHP));
    }

    /** @return list<string> */
    private function instanceCalls(string $source): array
    {
        return array_column(array_values(array_filter(
            $this->edges($source),
            static fn (array $edge): bool => $edge['type'] === 'instance-call',
        )), 'target');
    }

    /** @return list<string> */
    private function targets(string $source): array
    {
        return array_column($this->edges($source), 'target');
    }

    /** @return list<array{source: string, target: string, type: string}> */
    private function edges(string $source): array
    {
        $index = new RelationIndex();
        $tracer = new RelationTraversalTracer();

        foreach ([self::MODELS, self::TRAITS, $source] as $file) {
            $ast = AppFiles::parseResolved($file);
            $this->assertNotNull($ast);
            $classLikes = array_values(new NodeFinder()->findInstanceOf($ast, ClassLike::class));

            $uses = array_values(new NodeFinder()->findInstanceOf($ast, Use_::class));
            $index->collect($classLikes);
            $tracer->collect($classLikes, $uses);
        }

        return $tracer->edges($index);
    }
}
