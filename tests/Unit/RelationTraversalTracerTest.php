<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use PhpParser\Node\Stmt\ClassLike;
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

            $index->collect($classLikes);
            $tracer->collect($classLikes);
        }

        return $tracer->edges($index);
    }
}
