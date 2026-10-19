<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use SanderMuller\Richter\Tests\TestCase;

/**
 * The suite's own guard: a test that fakes nothing runs no process. Without it, `git status` in a test
 * reads the untracked files of whatever checkout hosts the suite.
 */
final class TestProcessIsolationTest extends TestCase
{
    #[Test]
    public function a_process_no_fake_answers_fails_the_test_instead_of_running(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('without a matching fake');

        Process::run(['git', 'status', '--porcelain']);
    }
}
