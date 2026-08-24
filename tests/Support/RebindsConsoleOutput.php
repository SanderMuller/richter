<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Support;

use Illuminate\Console\OutputStyle;
use Illuminate\Foundation\Application;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Installs a stand-in for `laravel/pao`'s console writer: a container binding for `OutputStyle` whose
 * `writeln()` runs every message through `Laravel\Pao\OutputCleaner::clean()`, copied verbatim.
 *
 * All seven transformations, deliberately. A shorter stand-in was tried first and was worse than useless:
 * it reproduced the whitespace half and missed the glyph stripping, which is the destructive part and the
 * reason the arrow and warning signs vanish from a report.
 *
 * @internal
 */
trait RebindsConsoleOutput
{
    /** Verbatim from laravel/pao 1.x, `src/OutputCleaner.php`. */
    protected function cleanLikePao(string $output): string
    {
        $output = (string) preg_replace('/\e\[[0-9;]*[A-Za-z]/', '', $output);
        $output = (string) preg_replace('/[\x00-\x09\x0B\x0C\x0E-\x1F\x7F]/', '', $output);
        $output = (string) preg_replace('/\x{FFFD}/u', '', $output);
        $output = (string) preg_replace('/[─━│┌┐└┘├┤┬┴┼▓░▒═║╔╗╚╝╠╣╦╩╬➜▶►⚠✖✔●◆■▪→←↑↓▕⨯✕]+/u', '', $output);
        $output = (string) preg_replace('/\.{3,}/', '..', $output);
        $output = (string) preg_replace('/[ \t]+/', ' ', $output);

        return (string) preg_replace('/\n\s*\n/', "\n", $output);
    }

    /**
     * Binds it the way Pao's service provider does, so the command resolves it through the container.
     *
     * Do NOT combine this with `withoutMockingConsoleOutput()`. That helper UNBINDS `OutputStyle` and
     * constructs one directly (`PendingCommand::withoutMockingConsoleOutput()`), so the binding never
     * applies and every assertion here passes whatever the code does — a vacuous test that looks green.
     * `Artisan::call()` plus `Artisan::output()` works without it.
     */
    protected function installPaoLikeCleaner(): void
    {
        $app = $this->app;
        $this->assertInstanceOf(Application::class, $app);

        $clean = $this->cleanLikePao(...);

        $app->bind(OutputStyle::class, static function (Application $app, array $params) use ($clean): OutputStyle {
            $input = $params['input'];
            $output = $params['output'];
            assert($input instanceof InputInterface && $output instanceof OutputInterface);

            return new class ($input, $output, $clean) extends OutputStyle {
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
            };
        });
    }
}
