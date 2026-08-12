<?php declare(strict_types=1);

namespace App\Livewire;

use Illuminate\Contracts\View\View;
use SanderMuller\Richter\Tracers\ViewRenderTracer;

/**
 * A Livewire component: no route resolves to it, so a route-anchored walk never reads this body.
 * It renders its view by literal name, which is what {@see ViewRenderTracer}
 * reads to give that view a caller. Also serves plan 036 as a non-dispatch-target change.
 */
final class StatusPanel
{
    public function refresh(): void {}

    public function render(): View
    {
        return view('livewire.status-panel');
    }
}
