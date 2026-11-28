<?php declare(strict_types=1);

namespace SanderMuller\Richter\Support;

use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use SanderMuller\Richter\Tracers\ClassHierarchyTracer;

/**
 * Which class-likes can call a non-public method of a given one.
 *
 * {@see ClassHierarchyTracer} answers "which classes can an object of this type be", and leaves
 * traits out, because a trait is copied and never dispatched to. This asks the other question, where
 * a trait is the centre of it: a private or protected method is callable from every class that holds
 * it, a trait's method is held by every class that uses the trait, and a class holds the methods of
 * every trait it uses. So the two are kept apart rather than one widened to carry both.
 *
 * @internal
 */
final class ClassFamilies
{
    /** Past this many members a family is treated as unlistable: one shared trait can pull in most of an app. */
    private const int CAP = 200;

    /** @var array<string, array{parent: string|null, traits: list<string>, file: string}> named class-like => what it extends and uses, and where it is declared */
    private array $members = [];

    /**
     * @var array<string, true> class-likes whose callers cannot be listed from their names: one an
     *   anonymous class extends or uses, and one that renames or re-exposes a trait method (`as`, `insteadof`)
     */
    private array $open = [];

    /**
     * @param  list<ClassLike>  $classLikes  every class-like in one file, any depth
     * @param  string  $file  the file that declares them
     */
    public function collect(array $classLikes, string $file): void
    {
        foreach ($classLikes as $node) {
            $fqcn = $node->namespacedName?->toString();
            $parent = $node instanceof Class_ && $node->extends instanceof Name ? AppFiles::resolveName($node->extends) : null;
            $traits = [];

            foreach ($node->getTraitUses() as $traitUse) {
                foreach ($traitUse->traits as $trait) {
                    $traits[] = AppFiles::resolveName($trait);
                }

                if ($traitUse->adaptations !== [] && $fqcn !== null) {
                    $this->open[$fqcn] = true;
                }
            }

            if ($fqcn !== null) {
                $this->members[$fqcn] = ['parent' => $parent, 'traits' => $traits, 'file' => $file];

                continue;
            }

            // An anonymous class has no name to list, so what it extends or uses has a caller this cannot read.
            foreach ($parent === null ? $traits : [$parent, ...$traits] as $base) {
                $this->open[$base] = true;
            }
        }
    }

    /** The file that declares a named class-like, or null when it was never collected. */
    public function fileOf(string $fqcn): ?string
    {
        return $this->members[$fqcn]['file'] ?? null;
    }

    /**
     * Every class-like whose code can call a non-public method `$start` declares, or null when that
     * set cannot be listed.
     *
     * The set follows trait use in both directions. For a protected method, `$withHierarchy` adds
     * the parent chain and the subclasses of each class as well.
     *
     * It is a superset on purpose: following trait use both ways can pull in a class that only
     * shares a trait. A caller that reads every member errs toward "cannot prove", never toward a
     * caller it did not read.
     *
     * Null when a member was never collected (a vendor parent or trait, a file that did not parse),
     * when an anonymous class extends or uses one, and when one renames a trait method: each of those
     * has code, or a name, this set cannot list. Null past {@see self::CAP} members too.
     *
     * @return list<string>|null
     */
    public function closedFamilyOf(string $start, bool $withHierarchy): ?array
    {
        [$children, $users] = $this->reverseMaps();
        $family = [];
        $queue = [$start];

        for ($head = 0; isset($queue[$head]); ++$head) {
            $member = $queue[$head];

            if (isset($family[$member])) {
                continue;
            }

            if (! isset($this->members[$member]) || isset($this->open[$member]) || count($family) >= self::CAP) {
                return null;
            }

            $family[$member] = true;
            array_push($queue, ...$this->members[$member]['traits'], ...$users[$member] ?? []);

            if ($withHierarchy) {
                $parent = $this->members[$member]['parent'];
                array_push($queue, ...$parent === null ? [] : [$parent], ...$children[$member] ?? []);
            }
        }

        return array_keys($family);
    }

    /** @return array{array<string, list<string>>, array<string, list<string>>} subclasses per class, and users per trait */
    private function reverseMaps(): array
    {
        $children = [];
        $users = [];

        foreach ($this->members as $fqcn => $member) {
            if ($member['parent'] !== null) {
                $children[$member['parent']][] = $fqcn;
            }

            foreach ($member['traits'] as $trait) {
                $users[$trait][] = $fqcn;
            }
        }

        return [$children, $users];
    }
}
