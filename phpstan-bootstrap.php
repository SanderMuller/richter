<?php declare(strict_types=1);

use Composer\InstalledVersions;
use Illuminate\Foundation\Application;

/**
 * Guarantees `LARAVEL_VERSION` before Larastan's stub-file extension reads it.
 *
 * Larastan defines that constant in its own bootstrap, from an application it boots — for a package
 * that means a Testbench application resolved through `CreatesApplication`. Two things go wrong with
 * that:
 *
 * 1. PHPStan instantiates its container services before the bootstrap files run in a fresh process,
 *    so the constant can still be missing when a service that reads it is built
 *    (larastan/larastan#2534). The same file is also allowed to leave the constant undefined
 *    silently: the packages branch is guarded on a trait existing, and nothing is thrown when no
 *    branch matches (larastan/larastan#2077).
 * 2. `LarastanStubFilesExtension::getFiles()` reads the constant without a `defined()` guard, so that
 *    silence surfaces as a fatal error thrown from stub collection before a single file is analysed
 *    (larastan/larastan#2480). The one-line guard was proposed in #2505 and closed unmerged, so the
 *    crash is still present in 3.10.0.
 *
 * On this repository it aborted every run on the Linux CI runner and intermittently in a local
 * parallel run, while `--debug`, which analyses in one process, was reliably fine.
 *
 * So this does not repair that mechanism; it removes the dependency on it. Larastan's own definition
 * is guarded by `if (! defined(...))`, making this a no-op wherever its bootstrap already works.
 *
 * TWO sources, because the first is not always reachable. `Application::VERSION` is the exact value
 * Larastan would have used, and needs no application, but reading it needs the class to autoload, and
 * an earlier version of this file that gave up when it did not was itself intermittent — the same
 * flake it was written to fix, one layer down. Composer's installed-versions map needs no autoload at
 * all and is the fallback; its `v` prefix is stripped, since Larastan compares the value against
 * directory names like `11` and `12` with `version_compare`.
 */
if (! defined('LARAVEL_VERSION')) {
    $laravelVersion = null;

    if (class_exists(Application::class)) {
        $laravelVersion = Application::VERSION;
    } elseif (class_exists(InstalledVersions::class)) {
        $laravelVersion = ltrim((string) InstalledVersions::getPrettyVersion('laravel/framework'), 'v');
    }

    if (is_string($laravelVersion) && $laravelVersion !== '') {
        define('LARAVEL_VERSION', $laravelVersion);
    }
}
