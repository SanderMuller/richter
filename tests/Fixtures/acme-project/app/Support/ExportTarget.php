<?php declare(strict_types=1);

namespace Acme\Support;

/** A value object with nothing but a promoted constructor, built by its same-namespace sibling. */
final readonly class ExportTarget
{
    public function __construct(public string $name) {}
}
