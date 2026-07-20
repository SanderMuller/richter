<?php declare(strict_types=1);

namespace App\Enums;

use Laravel\Pennant\Feature;

/** A project convention wrapping Pennant in an enum, e.g. `FeatureToggle::BETA_DASHBOARD->isActive()`. */
enum FeatureToggle: string
{
    case BETA_DASHBOARD = 'beta-dashboard';

    public function isActive(): bool
    {
        return Feature::active($this->value);
    }
}
