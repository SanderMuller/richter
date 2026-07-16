<?php declare(strict_types=1);

namespace App\Transformers\Api\v2\Question;

use App\Models\Question;

final class ExternalQuestion
{
    public function __construct(private readonly Question $question) {}

    /** @return array<string, mixed> */
    public function transform(): array
    {
        return ['id' => $this->question->getKey()];
    }
}
