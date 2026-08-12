<?php declare(strict_types=1);

namespace App\Pages;

use SanderMuller\Richter\Tracers\ViewRenderTracer;

/**
 * A page component in the other shape {@see ViewRenderTracer} reads: it names its view in a property
 * and renders nothing itself, the base class does. No route resolves to it and no `view()` call is
 * written anywhere in it, so reading calls alone leaves its Blade file with no caller at all.
 */
final class SettingsPage
{
    protected static string $view = 'pages.settings';
}
