<?php declare(strict_types=1);

namespace SanderMuller\Richter\Console;

use Illuminate\Console\Command;
use SanderMuller\Richter\Analysis\ImpactAnalyzer;
use SanderMuller\Richter\Analysis\ImpactFormatter;
use SanderMuller\Richter\Analysis\JsonPresenter;
use SanderMuller\Richter\Graph\CodeGraphBuilder;
use Throwable;

final class ImpactCommand extends Command
{
    /** @var string */
    protected $signature = 'richter:impact {symbol : An FQCN or substring to analyse, e.g. "App\\Models\\User"} {--json : Emit the blast radius as JSON on stdout}';

    /** @var string */
    protected $description = 'Show the static blast radius (callers and dependencies) of a code symbol';

    public function handle(CodeGraphBuilder $builder): int
    {
        $symbol = (string) $this->argument('symbol');

        if ($this->option('json')) {
            return $this->handleJson($builder, $symbol);
        }

        $this->info('Building code graph…');
        $result = new ImpactAnalyzer($builder->build())->impact($symbol);

        $this->line(ImpactFormatter::impact($result));

        return self::SUCCESS;
    }

    /**
     * JSON mode emits nothing but the JSON document on stdout (no progress line), so the output is a
     * single parseable value. Any error becomes `{"error": …}` rather than a leaked stack trace.
     */
    private function handleJson(CodeGraphBuilder $builder, string $symbol): int
    {
        try {
            $result = new ImpactAnalyzer($builder->build())->impact($symbol);

            $this->line(JsonPresenter::encode(JsonPresenter::impact($result)));

            return self::SUCCESS;
        } catch (Throwable $throwable) {
            $this->line(JsonPresenter::encode(['error' => $throwable->getMessage()]));

            return self::FAILURE;
        }
    }
}
