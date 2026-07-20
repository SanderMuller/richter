<?php declare(strict_types=1);

namespace App\Http\Requests\Post\Validators;

final class JsonCommentImportValidator
{
    /** @param array<string, mixed> $payload */
    public function validate(array $payload): bool
    {
        return $payload !== [];
    }
}
