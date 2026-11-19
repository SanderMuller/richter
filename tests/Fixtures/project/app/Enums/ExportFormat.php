<?php declare(strict_types=1);

namespace App\Enums;

use App\Jobs\Exports\CsvExportJob;
use App\Jobs\Exports\ExportJob;
use App\Jobs\Exports\PdfExportJob;

enum ExportFormat: string
{
    case Csv = 'csv';
    case Pdf = 'pdf';

    public const self DEFAULT = self::Csv;

    /** @param  list<string>  $rows */
    public function job(array $rows): ExportJob
    {
        return match ($this) {
            self::Csv => new CsvExportJob($rows),
            self::Pdf => new PdfExportJob($rows),
        };
    }
}
