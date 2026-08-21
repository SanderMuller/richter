<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Command\Command;
use Throwable;

/**
 * Whether a test drives a console entry point — a `command::` node or a `schedule::` one.
 *
 * Both carry the same thing: a command signature. The schedule is how a command RUNS, not what it is,
 * so a test invoking it by name references the scheduled surface as surely as the invoked one. The
 * only divergence is a scheduled CLOSURE, whose name is a label rather than a signature.
 *
 * Lives beside {@see TestReferenceIndex} rather than inside it: that class sits at the
 * static-analysis complexity ceiling, the same reason {@see RouteHandlerReferences} was lifted out.
 */
final readonly class ConsoleReferences
{
    /**
     * @param  array<string, list<string>>  $artisanNames  artisan command name => test files naming it
     * @param  array<string, list<string>>  $classes  imported FQCN => test files importing it
     */
    public function __construct(private array $artisanNames, private array $classes) {}

    /** @return array{referenced: bool, tests: list<string>}|null */
    public function resolveCommand(string $node): ?array
    {
        $name = $this->signatureName(substr($node, strlen('command::')));

        if ($name === '') {
            return null;
        }

        $referenced = isset($this->artisanNames[$name]);
        $tests = $this->artisanNames[$name] ?? [];

        try {
            $command = Artisan::all()[$name] ?? null;
        } catch (Throwable) {
            // Console kernel unavailable — a class-import reference can't be ruled out. An artisan
            // string match already in hand is still a determined (positive) answer.
            return $referenced ? ['referenced' => true, 'tests' => $this->unique($tests)] : null;
        }

        if ($command instanceof Command && isset($this->classes[$command::class])) {
            $referenced = true;
            $tests = [...$tests, ...$this->classes[$command::class]];
        }

        return ['referenced' => $referenced, 'tests' => $this->unique($tests)];
    }

    /**
     * A scheduled entry point.
     *
     * A scheduled COMMAND resolves exactly like an invoked one. A scheduled CLOSURE —
     * `$schedule->call(…)->name('nightly-report')` — does not: its name is a label, so there is no
     * class to look for and no artisan name to match. Answering `false` there would be a claim about
     * something that cannot be resolved at all, which is why this answers null instead and still lets
     * a literal mention in a test count.
     *
     * @return array{referenced: bool, tests: list<string>}|null
     */
    public function resolveSchedule(string $node): ?array
    {
        $name = $this->signatureName(substr($node, strlen('schedule::')));

        if ($name === '') {
            return null;
        }

        // Key existence, NOT a non-empty list: a source added without a file counts for the boolean
        // and cannot contribute a path, so its bucket is present but empty. Reading emptiness as
        // "never mentioned" would drop exactly those references.
        $mentioned = isset($this->artisanNames[$name]);
        $named = $mentioned ? ['referenced' => true, 'tests' => $this->unique($this->artisanNames[$name])] : null;

        try {
            $command = Artisan::all()[$name] ?? null;
        } catch (Throwable) {
            return $named;
        }

        if (! $command instanceof Command) {
            return $named;
        }

        return $this->resolveCommand('command::' . $name);
    }

    /** The first whitespace-delimited token of a command signature — its name, without its arguments. */
    private function signatureName(string $signature): string
    {
        return preg_split('/\\s/', trim($signature), 2)[0] ?? '';
    }

    /**
     * @param  list<string>  $files
     * @return list<string>
     */
    private function unique(array $files): array
    {
        $unique = array_values(array_unique($files));
        sort($unique);

        return $unique;
    }
}
