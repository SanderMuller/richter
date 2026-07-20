<?php declare(strict_types=1);

namespace App\Transformers\Api\v2\Review;

use App\Models\Review;

final class ExternalReview
{
    public function __construct(private readonly Review $review) {}

    /** @return array<string, mixed> */
    public function transform(): array
    {
        return ['id' => $this->review->getKey()];
    }
}
