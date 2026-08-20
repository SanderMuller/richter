<?php declare(strict_types=1);

namespace SanderMuller\Richter\Support;

/**
 * Why a reported node is in the section it is in.
 *
 * A list of class names alone leaves a reader with no way to tell a trait user from an override
 * implementor, which is exactly the classification the walk already made. Read straight off the
 * walk's own via-type map, so the reason can never disagree with the list it annotates.
 *
 * @internal
 */
final class ReachReasons
{
    /**
     * @param  list<string>  $nodes  the reported nodes, in report order
     * @param  array<string, array<string, true>>  $reach  every edge type that reached each node
     * @param  list<string>  $types  the types this section is about
     * @return array<string, list<string>> node => the types that put it here, sorted
     */
    public static function forNodes(array $nodes, array $reach, array $types): array
    {
        $reasons = [];

        foreach ($nodes as $node) {
            $matched = array_keys(array_intersect_key($reach[$node] ?? [], array_flip($types)));
            sort($matched);
            $reasons[$node] = $matched;
        }

        return $reasons;
    }
}
