<?php declare(strict_types=1);

namespace SanderMuller\Richter\Console;

use Illuminate\Console\Command;
use SanderMuller\Richter\Analysis\ImpactAnalyzer;
use SanderMuller\Richter\Analysis\ImpactFormatter;
use SanderMuller\Richter\Graph\CodeGraphBuilder;

final class ImpactCommand extends Command
{
    /** @var string */
    protected $signature = 'richter:impact {symbol : An FQCN or substring to analyse, e.g. "App\\Models\\User"}';

    /** @var string */
    protected $description = 'Show the static blast radius (callers and dependencies) of a code symbol';

    public function handle(CodeGraphBuilder $builder): int
    {
        $symbol = (string) $this->argument('symbol');

        $this->info('Building code graph…');
        $result = new ImpactAnalyzer($builder->build())->impact($symbol);

        $this->line(ImpactFormatter::impact($result));

        return self::SUCCESS;
    }
}
