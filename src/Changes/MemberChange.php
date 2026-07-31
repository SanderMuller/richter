<?php declare(strict_types=1);

namespace SanderMuller\Richter\Changes;

use SanderMuller\Richter\Analysis\ImpactAnalyzer;
use SanderMuller\Richter\Tracers\ConstantReferenceTracer;

/**
 * One changed member of a class, so {@see ImpactAnalyzer} seeds on the member that changed, not the
 * whole file. `resolvable` means the graph has a member-level node for this kind: methods (from
 * Brain), and class constants / enum cases (from {@see ConstantReferenceTracer}).
 * Properties and class-level modifiers have no member node, so they collapse to the class node → a
 * coarse, low-confidence class seed.
 */
final readonly class MemberChange
{
    public const string KIND_METHOD = 'method';

    public const string KIND_PROPERTY = 'property';

    public const string KIND_CONSTANT = 'constant';

    public const string KIND_ENUM_CASE = 'enum_case';

    /** A class-level modifier/attribute change (e.g. adding `final`) with no member touched. */
    public const string KIND_CLASS = 'class';

    public const string CHANGE_ADDED = 'added';

    public const string CHANGE_MODIFIED = 'modified';

    public const string CHANGE_REMOVED = 'removed';

    public function __construct(
        public string $name,
        public string $kind,
        public string $change,
        public bool $resolvable,
    ) {}

    public function isAdditive(): bool
    {
        return $this->change === self::CHANGE_ADDED;
    }
}
