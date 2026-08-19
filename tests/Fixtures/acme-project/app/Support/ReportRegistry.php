<?php declare(strict_types=1);

namespace Acme\Support;

use Acme\Services\SettingsMappingService;
use Acme\Services\SweepService;

/** Reached only through a static call, so nothing reads this body without the second-hop walk. */
final class ReportRegistry
{
    public static function boot(): string
    {
        $builder = new SettingsMappingService();

        return $builder->assemble();
    }

    /** Same namespace, so no import — the shape Brain could not resolve before v2.4.0. */
    public static function targets(): array
    {
        return [new ExportTarget('one'), new ExportTarget('two')];
    }

    /** Nothing calls this statically, so only the class-scope walk reads its body. */
    public static function sweep(): string
    {
        $service = new SweepService();

        return $service->run();
    }
}
