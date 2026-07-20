<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use App\Models\Post;
use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Graph\NodeNormalizer;
use SanderMuller\Richter\Tests\TestCase;

final class NodeNormalizerTest extends TestCase
{
    #[Test]
    public function it_keys_a_class_node_by_its_fqcn(): void
    {
        $this->assertSame(Post::class, NodeNormalizer::canonicalId('model::App\Models\Post', ['fqcn' => Post::class]));
    }

    #[Test]
    public function it_appends_the_method_for_a_member_node(): void
    {
        // A mangled deep-call node and an FQCN-cased action node for the same member collapse to one id.
        $data = ['fqcn' => Post::class, 'method' => 'query'];

        $this->assertSame('App\Models\Post::query', NodeNormalizer::canonicalId('app_models_post::query', $data));
        $this->assertSame('App\Models\Post::query', NodeNormalizer::canonicalId('action::App\Models\Post::query', $data));
    }

    #[Test]
    public function it_strips_a_leading_backslash_from_the_fqcn(): void
    {
        $this->assertSame('App\Jobs\ImportJob::handle', NodeNormalizer::canonicalId('app_jobs_importjob::handle', ['fqcn' => '\App\Jobs\ImportJob', 'method' => 'handle']));
    }

    #[Test]
    public function it_keeps_a_node_without_a_namespaced_fqcn_verbatim(): void
    {
        // Routes, middleware, and short-name nodes Brain could not fully resolve have no class FQCN to
        // normalise to — their id (carrying the entry-point prefix the impact analysis keys on) is kept
        // so no edge is silently dropped.
        $this->assertSame('route::GET::/css/fonts', NodeNormalizer::canonicalId('route::GET::/css/fonts', ['method' => 'GET', 'uri' => '/css/fonts']));
        $this->assertSame('middleware::web', NodeNormalizer::canonicalId('middleware::web', ['fqcn' => 'web']));
        $this->assertSame('action::DashboardController::__invoke', NodeNormalizer::canonicalId('action::DashboardController::__invoke', ['fqcn' => 'DashboardController', 'method' => '__invoke']));
    }

    #[Test]
    public function it_keeps_a_node_with_an_empty_or_missing_fqcn_verbatim(): void
    {
        $this->assertSame('command::app:do-thing', NodeNormalizer::canonicalId('command::app:do-thing', []));
        $this->assertSame('channel::abc123', NodeNormalizer::canonicalId('channel::abc123', ['fqcn' => '']));
    }

    #[Test]
    public function it_keys_a_class_node_with_no_usable_method_by_the_bare_fqcn(): void
    {
        // A class-level node carries an fqcn but no method; a non-string method (a malformed data bag)
        // must fall back to the class id, never `App\Models\Post::1`.
        $this->assertSame(Post::class, NodeNormalizer::canonicalId('model::App\Models\Post', ['fqcn' => Post::class]));
        $this->assertSame(Post::class, NodeNormalizer::canonicalId('model::App\Models\Post', ['fqcn' => Post::class, 'method' => null]));
        $this->assertSame(Post::class, NodeNormalizer::canonicalId('model::App\Models\Post', ['fqcn' => Post::class, 'method' => 123]));
    }
}
