<?php declare(strict_types=1);

namespace SanderMuller\Richter\Analysis;

use Illuminate\Support\Facades\Artisan;
use SanderMuller\Richter\Graph\CodeGraph;
use Symfony\Component\Console\Command\Command;
use Throwable;

/**
 * Whether a test drives a console entry point — a `command::` node or a `schedule::` one.
 *
 * A `command::` node carries a signature. A `schedule::` node does NOT — Brain ids one as
 * `schedule::md5(type.target.frequency)`, an opaque hash — so it has to be resolved through the
 * graph's edge to whatever it runs. A test driving that command references the scheduled surface as
 * surely as the invoked one.
 *
 * Lives beside {@see TestReferenceIndex} rather than inside it: that class sits at the
 * static-analysis complexity ceiling, the same reason {@see RouteHandlerReferences} was lifted out.
 */
final readonly class ConsoleReferences
{
    /**
     * @param  array<string, list<string>>  $artisanNames  artisan command name => test files naming it
     * @param  array<string, list<string>>  $classes  imported FQCN => test files importing it
     * @param  CodeGraph|null  $graph  needed to follow a schedule to the command it runs; without one
     *   a schedule cannot be answered at all
     */
    public function __construct(private array $artisanNames, private array $classes, private ?CodeGraph $graph = null) {}

    /** @return array{referenced: bool, tests: list<string>}|null */
    public function resolveCommand(string $node): ?array
    {
        return $this->resolveName($this->signatureName(substr($node, strlen('command::'))));
    }

    /** @return array{referenced: bool, tests: list<string>}|null */
    private function resolveName(string $name): ?array
    {
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
     * A scheduled entry point, resolved through what it RUNS.
     *
     * Its own id is an opaque hash, so nothing can be read out of the text: parsing it as a signature
     * would resolve every real schedule against nothing, and would let an unrelated artisan string
     * that happened to match a hash mark the surface referenced.
     *
     * A schedule reaching no command is a scheduled CLOSURE, or one the graph could not follow.
     * Neither can be answered, and `false` would claim no test references something never resolved.
     *
     * @return array{referenced: bool, tests: list<string>}|null
     */
    public function resolveSchedule(string $node): ?array
    {
        if (! $this->graph instanceof CodeGraph) {
            return null;
        }

        $commands = array_values(array_filter(
            array_column($this->graph->dependenciesOf([$node], 1), 'node'),
            static fn (string $target): bool => str_starts_with($target, 'command::'),
        ));

        if ($commands === []) {
            return null;
        }

        $referenced = false;
        $tests = [];

        foreach ($commands as $command) {
            $resolved = $this->resolveCommand($command);

            // One unanswerable target makes the schedule unanswerable: the tests it runs may be
            // exactly the ones the missing answer would have named.
            if ($resolved === null) {
                return null;
            }

            $referenced = $referenced || $resolved['referenced'];
            $tests = [...$tests, ...$resolved['tests']];
        }

        return ['referenced' => $referenced, 'tests' => $this->unique($tests)];
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
