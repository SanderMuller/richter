<?php declare(strict_types=1);

use Illuminate\Foundation\Application;

/**
 * Guarantees `LARAVEL_VERSION` before Larastan's stub-file extension reads it.
 *
 * Larastan defines that constant in its own bootstrap, from an application it boots — for a package
 * that means a Testbench application resolved through `CreatesApplication`. On this repository's
 * Linux CI runner the constant is nevertheless undefined by the time
 * `LarastanStubFilesExtension::getFiles()` reads it, and the whole analysis aborts with "Undefined
 * constant". WHY that boot does not leave the constant behind there is not established: the same
 * dependency versions, the same PHP minor and the same command all resolve an
 * `Illuminate\Foundation\Application` locally, and a CI probe confirmed every precondition Larastan
 * tests — the trait, the resolver, the resolved application — holds on the runner too.
 *
 * So this does not fix that mechanism; it removes the dependency on it. The version is a class
 * constant that needs no booted application, and Larastan's own definition is guarded by
 * `if (! defined(...))`, so defining it first is a no-op wherever its bootstrap already works.
 */
if (! defined('LARAVEL_VERSION') && class_exists(Application::class)) {
    define('LARAVEL_VERSION', Application::VERSION);
}
