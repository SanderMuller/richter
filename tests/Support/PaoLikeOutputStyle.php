<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Support;

use Illuminate\Console\OutputStyle;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The console writer {@see RebindsConsoleOutput} binds: every message passes through the cleaner.
 *
 * @internal
 */
final class PaoLikeOutputStyle extends OutputStyle
{
    /** @var callable(string): string */
    private $clean;

    /** @param  callable(string): string  $clean */
    public function __construct(InputInterface $input, OutputInterface $output, callable $clean)
    {
        parent::__construct($input, $output);
        $this->clean = $clean;
    }

    /**
     * `mixed` because the parent declares no type, so anything narrower is a fatal — and a
     * native type is what satisfies the missing-type rule where a docblock does not.
     */
    public function writeln(mixed $messages, int $type = 0): void
    {
        $lines = is_iterable($messages) ? [...$messages] : [$messages];
        $text = implode("\n", array_map(
            static fn (mixed $line): string => is_string($line) ? $line : '',
            $lines,
        ));

        parent::writeln(($this->clean)($text), $type);
    }
}
