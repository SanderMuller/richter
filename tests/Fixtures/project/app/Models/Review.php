<?php declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\WithAudits;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Review extends Model
{
    use WithAudits;

    public const string ANSWERS = 'answers';

    /** @return HasMany<Comment, $this> */
    public function answers(): HasMany
    {
        return $this->hasMany(Comment::class);
    }
}
