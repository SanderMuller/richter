<?php

declare(strict_types=1);

use SanderMuller\BoostCore\Config\BoostConfig;
use SanderMuller\BoostCore\Enums\Agent;
use SanderMuller\BoostCore\Enums\Tag;

/**
 * boost-core configuration — which AI agents `vendor/bin/boost sync` writes to,
 * which dependency vendors' shipped skills are synced, and which skill tags
 * are active.
 *
 * `withAllowedVendors()` is an explicit allowlist: a dependency's skills sync
 * ONLY if its package name is listed here. The boost umbrellas + the
 * `sandermuller/boost-skills` skill library are listed below — your package
 * installs whichever umbrella its category uses; any not installed is a
 * harmless no-op. Add other skill-shipping dependency vendors as you adopt them.
 *
 * `withTags()` filters `sandermuller/boost-skills`: with no tags you still get
 * the universal skills; each tag adds its capability-specific set (e.g. `php`
 * adds backend-quality / pre-release, `jira` adds the jira-* skills). Re-run
 * `vendor/bin/boost install` to change agents/vendors/tags interactively, or
 * hand-edit this file; then run `vendor/bin/boost sync`.
 *
 * Docs: https://github.com/sandermuller/boost-core
 */
return BoostConfig::configure()
    ->withAgents([
        Agent::CLAUDE_CODE,
        Agent::COPILOT,
        Agent::CODEX,
    ])
    ->withAllowedVendors([
        'sandermuller/boost-skills',
        'sandermuller/package-boost-laravel',
        'sandermuller/package-boost-php',
    ])
    ->withTags([
        Tag::Php,
        Tag::Laravel,
        Tag::Github,
        'release-automation',
    ])
    ->withDisabledEmitters([]);
