<?php declare(strict_types=1);

namespace Acme\Support;

use Acme\Services\SettingsMappingService;

/** Reached only through a static call, so nothing reads this body without the second-hop walk. */
final class ReportRegistry
{
    public static function boot(): string
    {
        $builder = new SettingsMappingService();

        return $builder->assemble();
    }
}
