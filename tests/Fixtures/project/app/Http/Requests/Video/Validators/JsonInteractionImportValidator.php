<?php declare(strict_types=1);

namespace App\Http\Requests\Video\Validators;

final class JsonInteractionImportValidator
{
    /** @param array<string, mixed> $payload */
    public function validate(array $payload): bool
    {
        return $payload !== [];
    }
}
