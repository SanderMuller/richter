<?php declare(strict_types=1);

namespace App\Actions\Video;

final class ValidateJsonImport
{
    public function execute(string $json): bool
    {
        return json_validate($json);
    }
}
