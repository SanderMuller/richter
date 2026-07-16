<?php declare(strict_types=1);

namespace App\Rules;

final class Document
{
    public function passes(string $attribute, mixed $value): bool
    {
        return $value !== null;
    }
}
