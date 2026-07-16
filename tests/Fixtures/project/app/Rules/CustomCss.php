<?php declare(strict_types=1);

namespace App\Rules;

final class CustomCss
{
    public function passes(string $attribute, mixed $value): bool
    {
        return is_string($value);
    }
}
