<?php declare(strict_types=1);

use Composer\InstalledVersions;
use Illuminate\Foundation\Application;

/**
 * Guarantees `LARAVEL_VERSION` before Larastan's stub-file extension reads it.
 *
 * Larastan defines that constant in its own bootstrap, from an application it boots — for a package
 * that means a Testbench application resolved through `CreatesApplication`. That boot does not always
 * leave the constant behind: `LarastanStubFilesExtension::getFiles()` then reads an undefined constant
 * and the whole analysis aborts before a single file is examined. It happens every time on this
 * repository's Linux CI runner and intermittently in a local parallel run, while `--debug`, which
 * analyses in one process, is reliably fine. WHY is not established — a probe on the runner confirmed
 * every precondition Larastan tests holds there, including a resolved
 * `Illuminate\Foundation\Application`.
 *
 * So this does not repair that mechanism; it removes the dependency on it. Larastan's own definition
 * is guarded by `if (! defined(...))`, making this a no-op wherever its bootstrap already works.
 *
 * TWO sources, because the first is not always reachable. `Application::VERSION` is the exact value
 * Larastan would have used, but reading it needs the class to autoload, and an earlier version of this
 * file that gave up when it did not was itself intermittent — the same flake it was written to fix,
 * one layer down. Composer's installed-versions map needs no autoload at all and is the fallback; its
 * `v` prefix is stripped, since Larastan compares the value against directory names like `11` and `12`
 * with `version_compare`.
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
