<?php declare(strict_types=1);

namespace App\Jobs\Exports;

final class PdfExportJob extends ExportJob
{
    public function handle(): void
    {
        count($this->rows);
    }
}
