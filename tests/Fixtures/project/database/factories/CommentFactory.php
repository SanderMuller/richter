<?php declare(strict_types=1);

namespace Database\Factories;

use App\Models\Comment;
use App\Models\Post;
use App\Services\CommentSummariser;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Comment> */
final class CommentFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'post_id' => Post::factory(),
            'body' => 'A comment',
        ];
    }

    public function summarised(): self
    {
        return $this->state(['summary' => new CommentSummariser()]);
    }
}
