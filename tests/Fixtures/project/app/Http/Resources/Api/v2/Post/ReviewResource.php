<?php declare(strict_types=1);

namespace App\Http\Resources\Api\v2\Post;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ReviewResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'player' => ReviewPlayerResource::make($this->resource),
        ];
    }
}
