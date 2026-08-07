<?php declare(strict_types=1);

namespace Acme\Services;

use Acme\Support\ClientFactory;

/** The parent holds the work; the subclass inherits assemble() without overriding it. */
class SettingsApiService
{
    public function assemble(): string
    {
        return ClientFactory::create('settings');
    }
}
