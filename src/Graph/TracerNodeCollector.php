<?php declare(strict_types=1);

namespace SanderMuller\Richter\Graph;

use PhpParser\Node;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\TraitUse;
use PhpParser\Node\Stmt\Use_;
use PhpParser\NodeVisitorAbstract;
use SanderMuller\Richter\Support\AppFiles;

/**
 * Buckets the nodes every per-file tracer needs — the file's methods, trait-uses and class-likes.
 *
 * Handed to {@see AppFiles::parseResolved()} so it fills DURING name
 * resolution rather than costing a second descent per file. One instance per file: it accumulates, so
 * a reused one would hand the next file the previous file's nodes.
 *
 * @internal
 */
final class TracerNodeCollector extends NodeVisitorAbstract
{
    /** @var list<ClassMethod> */
    public array $classMethods = [];

    /** @var list<TraitUse> */
    public array $traitUses = [];

    /** @var list<ClassLike> */
    public array $classLikes = [];

    /**
     * The file's `use` statements. Name resolution rewrites Name NODES, so every tracer that reads a
     * type off the AST needs nothing more — but a type written in a DOCBLOCK is a string the resolver
     * never saw, and resolving `@var Post $post` needs the aliases the file imported.
     *
     * @var list<Use_>
     */
    public array $uses = [];

    public function enterNode(Node $node): null
    {
        if ($node instanceof ClassMethod) {
            $this->classMethods[] = $node;
        } elseif ($node instanceof TraitUse) {
            $this->traitUses[] = $node;
        } elseif ($node instanceof ClassLike) {
            $this->classLikes[] = $node;
        } elseif ($node instanceof Use_) {
            $this->uses[] = $node;
        }

        return null;
    }
}
