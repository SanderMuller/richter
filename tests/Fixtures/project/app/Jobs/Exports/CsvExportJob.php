<?php declare(strict_types=1);

namespace App\Jobs\Exports;

final class CsvExportJob extends ExportJob
{
    /** @param  list<string>  $rows */
    public static function forRows(array $rows): self
    {
        return new self($rows);
    }

    public function handle(): void
    {
        implode(',', $this->rows);
    }
}
