<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Analysis\PublicWriteAuthCrossCheck;
use SanderMuller\Richter\Graph\CodeGraph;
use SanderMuller\Richter\Tests\TestCase;

/**
 * Brain classifies a route's exposure from the NAME of its middleware, so an app that subclasses
 * Laravel's `Authenticate` — the default skeleton shape — has every route behind it reported
 * `[public]`, and every mutating verb drawn as a `high` "requires no authentication". This walks the
 * ancestry that match cannot, as evidence beside the finding, never as a suppression.
 */
final class PublicWriteAuthCrossCheckTest extends TestCase
{
    private const string ROUTE = 'route::DELETE::/categories/{category}';

    private const string SUBCLASSED_AUTH = 'Acme\\Http\\Middleware\\AuthenticateUser';

    /** @return array<string, array{exposure: string, riskLevel: string, issues: list<array{type: string, severity: string, message: string}>}> */
    private function publicWriteSecurity(string $route = self::ROUTE): array
    {
        return [$route => [
            'exposure' => 'public',
            'riskLevel' => 'high',
            'issues' => [['type' => 'PUBLIC_WRITE', 'severity' => 'high', 'message' => 'Mutating route requires no authentication']],
        ]];
    }

    private function graphWithMiddleware(string ...$middleware): CodeGraph
    {
        return new CodeGraph(array_values(array_map(
            static fn (string $fqcn): array => ['source' => self::ROUTE, 'target' => "middleware::{$fqcn}", 'type' => 'route-to-middleware'],
            $middleware,
        )), hasUnparseableFiles: false);
    }

    #[Test]
    public function it_contradicts_the_finding_when_the_route_runs_a_subclassed_auth_middleware(): void
    {
        $crossCheck = new PublicWriteAuthCrossCheck($this->graphWithMiddleware(self::SUBCLASSED_AUTH));

        $this->assertSame(
            [self::ROUTE => [self::SUBCLASSED_AUTH]],
            $crossCheck->authMiddlewareByEntryPoint($this->publicWriteSecurity()),
        );
    }

    #[Test]
    public function it_says_nothing_about_middleware_that_does_not_authenticate(): void
    {
        // A guess here would be worse than silence: this note contradicts a security finding.
        $crossCheck = new PublicWriteAuthCrossCheck($this->graphWithMiddleware('Acme\\Http\\Middleware\\EnsureTokenIsValid'));

        $this->assertSame([], $crossCheck->authMiddlewareByEntryPoint($this->publicWriteSecurity()));
    }

    #[Test]
    public function it_says_nothing_about_a_middleware_class_that_does_not_exist(): void
    {
        // An unresolved alias or a package that is not installed is not evidence of anything, so
        // Brain's finding stands unchallenged.
        $crossCheck = new PublicWriteAuthCrossCheck($this->graphWithMiddleware('Acme\\Http\\Middleware\\NotInstalled'));

        $this->assertSame([], $crossCheck->authMiddlewareByEntryPoint($this->publicWriteSecurity()));
    }

    #[Test]
    public function it_recognises_the_framework_middleware_itself(): void
    {
        $crossCheck = new PublicWriteAuthCrossCheck($this->graphWithMiddleware('Illuminate\\Auth\\Middleware\\Authenticate'));

        $this->assertSame(
            [self::ROUTE => ['Illuminate\\Auth\\Middleware\\Authenticate']],
            $crossCheck->authMiddlewareByEntryPoint($this->publicWriteSecurity()),
        );
    }

    #[Test]
    public function it_only_speaks_where_brain_flagged_a_public_write(): void
    {
        // The cross-check exists to contradict one finding; a route without it needs no note.
        $crossCheck = new PublicWriteAuthCrossCheck($this->graphWithMiddleware(self::SUBCLASSED_AUTH));

        $security = [self::ROUTE => ['exposure' => 'public', 'riskLevel' => 'low', 'issues' => []]];

        $this->assertSame([], $crossCheck->authMiddlewareByEntryPoint($security));
    }

    #[Test]
    public function it_never_speaks_for_a_non_route_entry_point(): void
    {
        $crossCheck = new PublicWriteAuthCrossCheck($this->graphWithMiddleware(self::SUBCLASSED_AUTH));

        $this->assertSame([], $crossCheck->authMiddlewareByEntryPoint($this->publicWriteSecurity('command::app:purge')));
    }
}
