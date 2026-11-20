<?php declare(strict_types=1);

namespace App\Models;

use App\Casts\Markdown;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Comment extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['body' => Markdown::class];
    }

    /** @return BelongsTo<Post, $this> */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeRecent(Builder $query): Builder
    {
        return $query->latest();
    }

    public function archive(): void
    {
        $this->delete();
    }
}
