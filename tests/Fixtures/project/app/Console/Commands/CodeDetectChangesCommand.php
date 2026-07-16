<?php declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

final class CodeDetectChangesCommand extends Command
{
    /** @var string */
    protected $signature = 'code:detect-changes {--base=origin/develop : Git ref to diff against}';

    /** @var string */
    protected $description = 'Fixture command for the test-reference index';

    public function handle(): int
    {
        return self::SUCCESS;
    }
}
