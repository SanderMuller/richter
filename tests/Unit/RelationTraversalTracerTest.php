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
            public function author() { return $this->belongsTo(\App\Models\User::class); }
        }
        class User
        {
            public function team() { return $this->belongsTo(\App\Models\Team::class); }
        }
        class Team {}
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
                public function run(\App\Models\Comment $comment): void
                {
                    $subject = $comment;
                    $subject = resolve('something');
                    $name = $subject->author;
                }
            }
            PHP);

        $this->assertSame([], $targets);
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

        foreach ([self::MODELS, $source] as $file) {
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
