<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use Iterator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Analysis\PublicWriteAuthCrossCheck;
use SanderMuller\Richter\Graph\CodeGraph;
use SanderMuller\Richter\Tests\TestCase;

/**
 * Brain reads a middleware by name and, since 2.5.0, by an `extends` walk that terminates on
 * `Illuminate\Auth\Middleware\Authenticate`. A middleware descending from one of the three other
 * framework auth middlewares, under a name of its own, matches none of that: every route behind it
 * is reported `[public]`, and every mutating verb draws a "requires no authentication" issue.
 * This walks the ancestry of all four bases, as evidence beside the finding, never as a suppression.
 *
 * The `Authenticate` cases below are kept even though Brain now answers them itself — they pin the
 * base this lane shares with Brain, and a lane that only agrees where Brain is silent is a lane
 * nobody can tell is working.
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

    /**
     * The remainder Brain's own `extends` walk does not cover, and therefore the only reason this
     * lane still runs: it terminates on `Authenticate`, so a descendant of the other three bases is
     * classified `[public]` and draws the false finding this note contradicts.
     */
    #[Test]
    #[DataProvider('descendantsOfTheOtherAuthBases')]
    public function it_contradicts_the_finding_for_a_descendant_of_any_auth_base(string $middleware): void
    {
        $crossCheck = new PublicWriteAuthCrossCheck($this->graphWithMiddleware($middleware));

        $this->assertSame(
            [self::ROUTE => [$middleware]],
            $crossCheck->authMiddlewareByEntryPoint($this->publicWriteSecurity()),
        );
    }

    /** @return Iterator<string, array{string}> */
    public static function descendantsOfTheOtherAuthBases(): Iterator
    {
        yield 'basic auth' => ['Acme\\Http\\Middleware\\RequireBasicCredentials'];
        yield 'email verification' => ['Acme\\Http\\Middleware\\VerifyCustomerEmail'];
        yield 'signed URL' => ['Acme\\Http\\Middleware\\CheckSignedLink'];
        // Two hops from the base: `is_subclass_of()` is transitive and this pins that it stays so.
        yield 'signed URL, one class further out' => ['Acme\\Http\\Middleware\\CheckExpiringLink'];
    }

    /**
     * The fixtures carry this lane's whole premise: they must sit OUTSIDE the one base Brain's own
     * `extends` walk terminates on, or the cases above assert nothing Brain does not already answer.
     * A fixture re-parented onto `Authenticate` would keep every test above green while making them
     * meaningless, and that is exactly the day this lane could be deleted by accident.
     *
     * This does not run Brain — a dependency-contract test over `SecurityAnalyzer` needs a routed
     * fixture app and belongs in its own plan. It pins the half that is richter's to keep true.
     */
    #[Test]
    #[DataProvider('descendantsOfTheOtherAuthBases')]
    public function the_fixtures_stay_outside_the_base_brain_resolves_itself(string $middleware): void
    {
        $this->assertTrue(class_exists($middleware), "{$middleware} must exist for the case above to mean anything");
        $this->assertFalse(
            is_subclass_of($middleware, 'Illuminate\\Auth\\Middleware\\Authenticate'),
            "{$middleware} descends from Authenticate, which Brain classifies itself — the case above proves nothing",
        );
    }

    /**
     * A route may carry several, and the note lists every one it recognises rather than the first
     * hit. The order is the graph's, not the route's: {@see CodeGraph::outgoingTargetsOfType()}
     * sorts, so the note reads the same on two runs of one unchanged route.
     */
    #[Test]
    public function it_names_every_auth_middleware_on_one_route_and_drops_the_rest(): void
    {
        $crossCheck = new PublicWriteAuthCrossCheck($this->graphWithMiddleware(
            'Acme\\Http\\Middleware\\VerifyCustomerEmail',
            'Acme\\Http\\Middleware\\EnsureTokenIsValid',
            self::SUBCLASSED_AUTH,
        ));

        $this->assertSame(
            [self::ROUTE => [self::SUBCLASSED_AUTH, 'Acme\\Http\\Middleware\\VerifyCustomerEmail']],
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
