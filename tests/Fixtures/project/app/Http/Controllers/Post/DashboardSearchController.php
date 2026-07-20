<?php declare(strict_types=1);

namespace App\Http\Controllers\Post;

use App\Models\Post;
use Illuminate\Database\Eloquent\Collection;

final class DashboardSearchController
{
    /** @return Collection<int, Post> */
    public function index(): Collection
    {
        return Post::query()->get();
    }
}
