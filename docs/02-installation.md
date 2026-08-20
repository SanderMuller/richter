# Installation

```bash
composer require --dev sandermuller/richter
```

Requires PHP 8.4+ and Laravel 12 or 13.

## The `laravel/mcp` constraint

`laravel/mcp` is optional. It lights up the [MCP server](13-mcp-server.md), but when present it must fall in the supported `^0.8||^0.9` range; Richter declares a conflict with anything outside it.

`laravel/boost` only pulls a compatible `laravel/mcp` from v2, and Composer will not upgrade a package Richter does not depend on. An existing `laravel/boost` v1 install therefore has to take that major in the same command, or the install fails on the `laravel/mcp` conflict:

```bash
composer require --dev sandermuller/richter laravel/boost:* -W
```

## Publish the config

Optional, and only needed once you want to tune something:

```bash
php artisan vendor:publish --tag=richter-config
```

Every key is documented in the [configuration reference](16-configuration.md). Richter runs on its defaults without a published config file, but it is accurate only once it knows your app's shape. See [Set up your project](03-project-setup.md).

## The commands

```bash
php artisan list richter
```

| Command | Page |
|---|---|
| `richter:detect-changes` | [Detecting change impact](04-detect-changes.md) |
| `richter:impact` | [Blast radius of a symbol](09-impact.md) |
| `richter:trace` | [Shortest path between symbols](10-trace.md) |
| `richter:affected-tests` | [Affected-test selection](11-affected-tests.md) |
| `richter:benchmark`, `richter:benchmark:add` | [Benchmarking](17-benchmark.md) |

Each one runs through `php artisan`, so it boots your application to build the code graph. That means it needs whatever booting normally requires, typically an `.env` file and an `APP_KEY`, which matters most [in CI](08-ci-gating.md).
