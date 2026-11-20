<?php declare(strict_types=1);

namespace App\Models\Concerns;

trait WithAudits
{
    public function auditName(): string
    {
        return static::class;
    }

    public static function auditChannel(): string
    {
        return 'audits';
    }
}
