<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Support;

use SanderMuller\Richter\Graph\SecondHopWalk;

/**
 * Stands in for the body walk {@see SecondHopWalk} calls: records what
 * it was asked to read, and answers with the pre-set result for that round. Lets a test assert
 * *which methods the walk decides to read*, which is the whole of that class's design.
 */
final class RecordingBodyWalk
{
    /** @var list<list<string>> */
    public array $asked = [];

    public string $askedFor = '';

    /**
     * @param  list<array{source: string, target: string, type: string}>  $edges  what the walk finds
     * @param  int  $unread  how many of the asked-for methods it could not read
     */
    public function __construct(private readonly array $edges = [], private readonly int $unread = 0) {}

    /**
     * @param  list<string>  $nodes
     * @return array{edges: list<array{source: string, target: string, type: string}>, unread: int}
     */
    public function __invoke(array $nodes, string $projectRoot): array
    {
        sort($nodes);
        $this->asked[] = $nodes;
        $this->askedFor = $projectRoot;

        return ['edges' => $this->edges, 'unread' => $this->unread];
    }
}
