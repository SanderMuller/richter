<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Graph\CodeGraphBuilder;
use SanderMuller\Richter\Tests\TestCase;

/**
 * A form request the container builds for a parameter reaches the method that receives it. Built
 * over a temp project, so the member-declaration pass and the caller walk run as production runs them.
 */
final class ContainerResolvedParameterReachTest extends TestCase
{
    private string $projectRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->projectRoot = sys_get_temp_dir() . '/richter-contextual-attribute-' . bin2hex(random_bytes(8));
        mkdir("{$this->projectRoot}/app/Http/Controllers", recursive: true);
        mkdir("{$this->projectRoot}/app/Http/Requests/Post", recursive: true);
        copy(__DIR__ . '/../Fixtures/project/app/Http/Requests/Post/StorePostRequest.php', "{$this->projectRoot}/app/Http/Requests/Post/StorePostRequest.php");
    }

    protected function tearDown(): void
    {
        new Filesystem()->deleteDirectory($this->projectRoot);

        parent::tearDown();
    }

    #[Test]
    public function a_change_to_the_rules_reaches_the_method_that_receives_the_request(): void
    {
        file_put_contents("{$this->projectRoot}/app/Http/Controllers/PostController.php", <<<'PHP'
            <?php declare(strict_types=1);

            namespace App\Http\Controllers;

            use App\Http\Attributes\ValidatedInput;
            use App\Http\Requests\Post\StorePostRequest;

            final class PostController
            {
                public function store(#[ValidatedInput(StorePostRequest::class)] object $input): void {}
            }
            PHP);

        $callers = new CodeGraphBuilder()->build($this->projectRoot)->callersOf(['App\Http\Requests\Post\StorePostRequest::rules']);

        $this->assertContains('App\Http\Controllers\PostController::store', array_column($callers, 'node'));
    }

    #[Test]
    public function a_change_to_the_rules_reaches_the_method_with_a_typed_request_parameter(): void
    {
        file_put_contents("{$this->projectRoot}/app/Http/Controllers/PostController.php", <<<'PHP'
            <?php declare(strict_types=1);

            namespace App\Http\Controllers;

            use App\Http\Requests\Post\StorePostRequest;

            final class PostController
            {
                public function store(StorePostRequest $request): void {}
            }
            PHP);

        $callers = new CodeGraphBuilder()->build($this->projectRoot)->callersOf(['App\Http\Requests\Post\StorePostRequest::rules']);

        $this->assertContains('App\Http\Controllers\PostController::store', array_column($callers, 'node'));
    }
}
