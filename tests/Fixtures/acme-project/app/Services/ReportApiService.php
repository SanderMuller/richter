<?php declare(strict_types=1);

namespace Acme\Services;

use Acme\Support\ClientFactory;

/** The parent holds the work: the subclass inherits build() without overriding it. */
class ReportApiService
{
    public function build(): string
    {
        return ClientFactory::create('reports');
    }
}
