<?php declare(strict_types=1);

namespace Database\Factories;

use App\Jobs\Exports\CsvExportJob;
use App\Models\Post;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Post> */
final class PostFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            Post::TITLE => 'A title',
            'export' => CsvExportJob::forRows([]),
        ];
    }
}
