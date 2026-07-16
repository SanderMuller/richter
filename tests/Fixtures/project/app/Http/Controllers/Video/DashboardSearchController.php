<?php declare(strict_types=1);

namespace App\Http\Controllers\Video;

use App\Models\Video;
use Illuminate\Database\Eloquent\Collection;

final class DashboardSearchController
{
    /** @return Collection<int, Video> */
    public function index(): Collection
    {
        return Video::query()->get();
    }
}
