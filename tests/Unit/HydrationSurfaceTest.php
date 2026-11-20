<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use PhpParser\Node\Stmt\ClassLike;
use PhpParser\NodeFinder;
use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Support\AppFiles;
use SanderMuller\Richter\Support\HydrationSurface;
use SanderMuller\Richter\Tests\TestCase;

final class HydrationSurfaceTest extends TestCase
{
    private const string CAST = <<<'PHP'
        <?php
        namespace App\Casts;
        class Markdown {}
        PHP;

    private const string ENUM = <<<'PHP'
        <?php
        namespace App\Enums;
        enum Status: string { case Open = 'open'; }
        PHP;

    #[Test]
    public function a_casts_method_links_each_app_cast_class_it_names(): void
    {
        $edges = $this->edges([self::CAST, self::ENUM, <<<'PHP'
            <?php
            namespace App\Models;
            use App\Casts\Markdown;
            class Comment
            {
                protected function casts(): array
                {
                    return [
                        'body' => Markdown::class,
                        'summary' => Markdown::class . ':short',
                        'status' => \App\Enums\Status::class,
                        'meta' => 'array',
                        'missing' => \App\Casts\Unknown::class,
                    ];
                }
            }
            PHP]);

        $this->assertSame(['App\\Models\\Comment::casts casts App\\Casts\\Markdown'], $edges);
    }

    #[Test]
    public function a_casts_property_links_from_the_class(): void
    {
        $edges = $this->edges([self::CAST, <<<'PHP'
            <?php
            namespace App\Models;
            class Comment
            {
                protected $casts = ['body' => \App\Casts\Markdown::class];
            }
            PHP]);

        $this->assertSame(['App\\Models\\Comment casts App\\Casts\\Markdown'], $edges);
    }

    #[Test]
    public function a_relation_links_to_the_code_that_runs_when_it_loads_the_model(): void
    {
        $edges = $this->edges([self::CAST, <<<'PHP'
            <?php
            namespace App\Models;
            use Illuminate\Database\Eloquent\Casts\Attribute;
            trait HasStatus
            {
                public static function bootHasStatus(): void {}
                public function label(): string { return ''; }
            }
            abstract class Entry
            {
                protected static function booted(): void {}
            }
            class Comment extends Entry
            {
                use HasStatus;
                protected $casts = ['body' => \App\Casts\Markdown::class];
                protected function casts(): array { return []; }
                public function getTitleAttribute(): string { return ''; }
                protected function excerpt(): Attribute { return Attribute::get(fn () => ''); }
                public function publish(): void {}
            }
            PHP], [['source' => 'App\\Models\\Post::comments', 'target' => 'App\\Models\\Comment', 'type' => 'relation-to-model']]);

        $this->assertSame([
            'App\\Models\\Comment casts App\\Casts\\Markdown',
            'App\\Models\\Post::comments hydrates App\\Casts\\Markdown',
            'App\\Models\\Post::comments hydrates App\\Models\\Comment::casts',
            'App\\Models\\Post::comments hydrates App\\Models\\Comment::excerpt',
            'App\\Models\\Post::comments hydrates App\\Models\\Comment::getTitleAttribute',
            'App\\Models\\Post::comments hydrates App\\Models\\Entry::booted',
            'App\\Models\\Post::comments hydrates App\\Models\\HasStatus::bootHasStatus',
        ], $edges);
    }

    #[Test]
    public function a_relation_to_a_model_the_index_never_saw_links_nothing(): void
    {
        $this->assertSame([], $this->edges([], [['source' => 'App\\Models\\Post::comments', 'target' => 'App\\Models\\Comment', 'type' => 'relation-to-model']]));
    }

    #[Test]
    public function a_relation_links_to_each_observer_of_the_model_it_returns(): void
    {
        $edges = HydrationSurface::observerEdges([], [
            ['source' => 'App\\Models\\Comment', 'target' => 'App\\Observers\\CommentObserver', 'type' => 'model-to-observer'],
            ['source' => 'App\\Models\\Post::comments', 'target' => 'App\\Models\\Comment', 'type' => 'relation-to-model'],
            ['source' => 'App\\Models\\Comment::post', 'target' => 'App\\Models\\Post', 'type' => 'relation-to-model'],
        ]);

        $this->assertSame([
            ['source' => 'App\\Models\\Post::comments', 'target' => 'App\\Observers\\CommentObserver', 'type' => 'hydrates'],
        ], $edges);
    }

    #[Test]
    public function a_relation_links_to_the_observer_of_a_parent_model(): void
    {
        $edges = HydrationSurface::observerEdges(
            ['App\\Models\\Comment' => ['parent' => 'App\\Models\\Entry', 'declared' => []]],
            [
                ['source' => 'App\\Models\\Entry', 'target' => 'App\\Observers\\EntryObserver', 'type' => 'model-to-observer'],
                ['source' => 'App\\Models\\Post::comments', 'target' => 'App\\Models\\Comment', 'type' => 'relation-to-model'],
            ],
        );

        $this->assertSame([
            ['source' => 'App\\Models\\Post::comments', 'target' => 'App\\Observers\\EntryObserver', 'type' => 'hydrates'],
        ], $edges);
    }

    #[Test]
    public function framework_hooks_and_a_nullable_attribute_accessor_are_hydration_members(): void
    {
        $edges = $this->edges([<<<'PHP'
            <?php
            namespace App\Models;
            use Illuminate\Database\Eloquent\Attributes\Initialize;
            use Illuminate\Database\Eloquent\Casts\Attribute;
            class Comment
            {
                #[Initialize]
                public function configure(): void {}
                public function __construct(array $attributes = []) {}
                public function newCollection(array $models = []) {}
                public function newQueryForRestoration($ids) {}
                public function getMorphClass() {}
                public function toArray() {}
                protected function excerpt(): ?Attribute { return null; }
                public function publish(): void {}
            }
            PHP], [['source' => 'App\\Models\\Post::comments', 'target' => 'App\\Models\\Comment', 'type' => 'relation-to-model']]);

        $this->assertSame([
            'App\\Models\\Post::comments hydrates App\\Models\\Comment::__construct',
            'App\\Models\\Post::comments hydrates App\\Models\\Comment::configure',
            'App\\Models\\Post::comments hydrates App\\Models\\Comment::excerpt',
            'App\\Models\\Post::comments hydrates App\\Models\\Comment::getMorphClass',
            'App\\Models\\Post::comments hydrates App\\Models\\Comment::newCollection',
            'App\\Models\\Post::comments hydrates App\\Models\\Comment::newQueryForRestoration',
            'App\\Models\\Post::comments hydrates App\\Models\\Comment::toArray',
        ], $edges);
    }

    #[Test]
    public function a_casts_property_in_a_grouped_declaration_is_read_by_name(): void
    {
        $edges = $this->edges([self::CAST, <<<'PHP'
            <?php
            namespace App\Models;
            class Comment
            {
                protected $hidden = [], $casts = ['body' => \App\Casts\Markdown::class];
            }
            PHP]);

        $this->assertSame(['App\\Models\\Comment casts App\\Casts\\Markdown'], $edges);
    }

    /**
     * @param  list<string>  $sources
     * @param  list<array{source: string, target: string, type: string}>  $relationEdges
     * @return list<string>
     */
    private function edges(array $sources, array $relationEdges = []): array
    {
        $surface = new HydrationSurface();

        foreach ($sources as $source) {
            $ast = AppFiles::parseResolved($source);
            $this->assertNotNull($ast);
            $surface->collect(array_values(new NodeFinder()->findInstanceOf($ast, ClassLike::class)));
        }

        $lines = array_map(static fn (array $edge): string => "{$edge['source']} {$edge['type']} {$edge['target']}", $surface->edges($relationEdges));
        sort($lines);

        return $lines;
    }
}
