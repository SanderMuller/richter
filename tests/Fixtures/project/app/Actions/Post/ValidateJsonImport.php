<?php declare(strict_types=1);

namespace App\Actions\Post;

final class ValidateJsonImport
{
    public function execute(string $json): bool
    {
        return json_validate($json);
    }
}
