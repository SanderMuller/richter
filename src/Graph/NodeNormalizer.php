<?php declare(strict_types=1);

namespace SanderMuller\Richter\Graph;

/**
 * Collapses Laravel Brain's three id schemes — FQCN-cased entity nodes (`model::App\Models\Video`),
 * mangled deep-call nodes (`app_models_video::query`), and prefixed-mangled nodes (`enum::app_enums_x`)
 * — onto one FQCN-keyed id, read from each node's own `data['fqcn']` / `data['method']`. Post-hoc
 * tracer edges then address symbols by plain FQCN and join without reproducing Brain's private id
 * mangling (which differs from a naive backslash swap, so mirroring it drifts silently).
 *
 * Nodes whose `fqcn` is not a namespaced class — routes, middleware, channels, commands, and the
 * occasional short-name node Brain couldn't fully resolve — keep Brain's id verbatim: they carry the
 * entry-point prefixes the impact analysis keys on, and have no FQCN to normalise to.
 */
final class NodeNormalizer
{
    /** @param  array<array-key, mixed>  $data  a Brain node's data bag */
    public static function canonicalId(string $id, array $data): string
    {
        $fqcn = $data['fqcn'] ?? null;

        // Only a real namespaced class normalises; a null/empty/short fqcn (routes, middleware, the
        // `web` middleware alias, unresolved short names) keeps Brain's own id so nothing is dropped.
        if (! is_string($fqcn) || ! str_contains($fqcn, '\\')) {
            return $id;
        }

        $fqcn = ltrim($fqcn, '\\');
        $method = $data['method'] ?? null;

        return is_string($method) && $method !== '' ? "{$fqcn}::{$method}" : $fqcn;
    }
}
