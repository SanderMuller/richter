<?php declare(strict_types=1);

namespace Acme\Support;

/** Reached only through the facade in front of it, so nothing connects to it without the resolution edge. */
final class ReportBuilder
{
    public function assemble(): string
    {
        return new ExportTarget('assembled')->name;
    }
}
