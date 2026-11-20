<?php declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\PublishedScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Post extends Model
{
    use HasFactory;

    public const string COMMENTS = 'comments';

    public const string REVIEWS = 'reviews';

    public const string TITLE = 'title';

    public const string SLUG = 'slug';

    /** @var list<string> */
    protected $fillable = [self::TITLE, self::SLUG];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['status' => 'string'];
    }

    protected static function booted(): void
    {
        self::addGlobalScope(new PublishedScope());
    }

    /** @return HasMany<Comment, $this> */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    /** @return HasMany<Review, $this> */
    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }
}
