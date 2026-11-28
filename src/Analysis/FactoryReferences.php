<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

/**
 * Which test files name a model factory class, by import or by qualified name.
 *
 * A test can build a model through `PostFactory::new()` and never import the model, so a changed
 * factory has to find its tests by the factory class as well ({@see UnreadFileImpact}). Kept apart
 * from {@see TestReferenceIndex}'s class map, which holds application classes only.
 *
 * A regex scan, like the rest of the index, for names under `Database\Factories\` only. Strings
 * and comments can over-match, which selects a test too many, or withdraws the selection when the
 * match sits in a support file whose users cannot be listed.
 *
 * @internal
 */
final class FactoryReferences
{
    /** @var array<string, list<string>> factory FQCN => the test files naming it */
    private array $tests = [];

    public function add(string $source, string $file): void
    {
        $named = [];

        if (preg_match_all('/(?<![\w$\\\\])\\\\?(Database\\\\Factories\\\\(?:[A-Za-z_]\w*\\\\)*[A-Za-z_]\w*)/', $source, $matches) > 0) {
            $named = $matches[1];
        }

        // `use Database\Factories\{PostFactory, CommentFactory as Comments};`
        if (preg_match_all('/^use\s+(Database\\\\Factories\\\\[^;{]*)\{([^}]+)}\s*;/m', $source, $groups, PREG_SET_ORDER) > 0) {
            foreach ($groups as $group) {
                foreach (explode(',', $group[2]) as $member) {
                    $named[] = $group[1] . trim((string) preg_replace('/\s+as\s+\w+$/i', '', trim($member)));
                }
            }
        }

        foreach (array_unique($named) as $factory) {
            $this->tests[$factory][] = $file;
        }
    }

    /** @return list<string> */
    public function testsNaming(string $factory): array
    {
        return $this->tests[ltrim($factory, '\\')] ?? [];
    }
}
