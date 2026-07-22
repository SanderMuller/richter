<?php declare(strict_types=1);

namespace App\Livewire;

/**
 * Neutral fixture for plan 036: an ordinary Livewire component — not a bus dispatch target — used
 * to prove a change reaching no dispatchable narrows even when the graph has unresolved dispatches
 * elsewhere.
 */
final class StatusPanel
{
    public function refresh(): void {}
}
