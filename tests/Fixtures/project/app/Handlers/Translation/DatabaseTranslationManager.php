<?php declare(strict_types=1);

namespace App\Handlers\Translation;

final class DatabaseTranslationManager
{
    public function get(string $key): string
    {
        return $key;
    }
}
