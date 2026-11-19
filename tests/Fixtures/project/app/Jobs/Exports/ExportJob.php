<?php declare(strict_types=1);

namespace App\Jobs\Exports;

use Illuminate\Contracts\Queue\ShouldQueue;

abstract class ExportJob implements ShouldQueue
{
    /** @param  list<string>  $rows */
    public function __construct(protected readonly array $rows = []) {}
}
