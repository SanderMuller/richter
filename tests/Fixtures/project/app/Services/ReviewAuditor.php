<?php declare(strict_types=1);

namespace App\Services;

use App\Models\Review;

final class ReviewAuditor
{
    public function label(): string
    {
        return new Review()->auditName();
    }
}
