<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use Iterator;
use LaraMint\LaravelBrain\Analysis\MiddlewareRegistry;
use LaraMint\LaravelBrain\Analysis\RouteAnalyzer;
use LaraMint\LaravelBrain\Analysis\RouteDefinition;
use LaraMint\LaravelBrain\Analysis\SecurityAnalyzer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Analysis\PublicWriteAuthCrossCheck;
use SanderMuller\Richter\Tests\TestCase;

/**
 * A contract test over the dependency, not over richter: it pins the Brain behaviour
 * {@see PublicWriteAuthCrossCheck} exists to compensate for, so an
 * upstream change to it fails here rather than silently making that lane dead code or a wrong note.
 *
 * The lane's own tests hand it a `PUBLIC_WRITE` finding, because that is the input it takes. Which
 * routes actually carry one is Brain's answer, and only this file asks Brain.
 *
 * Both directions matter. Brain widening its walk to the other three bases is the day the lane can
 * shrink; Brain narrowing it back is the day the lane covers more again. Either way the answer
 * should arrive as a red test, not as a report nobody can explain.
 */
final class BrainSecurityContractTest extends TestCase
{
    private const string AUTHENTICATE_DESCENDANT = 'Acme\\Http\\Middleware\\AuthenticateUser';

    protected function setUp(): void
    {
        parent::setUp();

        // `RouteDefinition` is declared inside `RouteAnalyzer.php`, so PSR-4 cannot autoload it by
        // its own name. Loading the class the file is named for defines it — and asserting that
        // makes an upstream move of either one a readable failure rather than a "class not found".
        $this->assertTrue(class_exists(RouteAnalyzer::class));
        $this->assertTrue(class_exists(RouteDefinition::class, autoload: false));
    }

    /**
     * Brain's own verdict for one mutating route carrying $middlewares, keyed as it keys it.
     *
     * @param  list<string>  $middlewares
     * @return array<array-key, mixed>
     */
    private function brainVerdictFor(array $middlewares, MiddlewareRegistry $registry): array
    {
        $route = new RouteDefinition(
            method: 'DELETE',
            uri: 'articles/{article}',
            controller: 'Acme\\Http\\Controllers\\ArticleController',
            action: 'destroy',
            middlewares: $middlewares,
            name: 'articles.destroy',
            file: 'routes/web.php',
            line: 1,
        );

        $results = new SecurityAnalyzer()->analyze(
            [$route],
            $registry,
            [],
            __DIR__ . '/../Fixtures/acme-project',
        );

        $verdict = $results['route::DELETE::articles/{article}'] ?? null;
        $this->assertIsArray($verdict, 'Brain returned no verdict for the route under test');

        return $verdict;
    }

    /** @param list<string> $middlewares */
    private function exposureOf(array $middlewares, MiddlewareRegistry $registry = new MiddlewareRegistry([], [], [])): string
    {
        $exposure = $this->brainVerdictFor($middlewares, $registry)['exposure'] ?? null;
        $this->assertIsString($exposure);

        return $exposure;
    }

    /** @param list<string> $middlewares */
    private function drawsPublicWrite(array $middlewares, MiddlewareRegistry $registry = new MiddlewareRegistry([], [], [])): bool
    {
        $issues = $this->brainVerdictFor($middlewares, $registry)['issues'] ?? [];
        $this->assertIsArray($issues);

        return array_any(
            $issues,
            static fn (mixed $issue): bool => is_array($issue) && ($issue['type'] ?? null) === 'PUBLIC_WRITE',
        );
    }

    #[Test]
    public function brain_classifies_an_authenticate_descendant_itself(): void
    {
        // Laravel Brain 2.5.0 walks the `extends` chain for this one base, so richter's cross-check
        // has nothing to contradict here — this is the half of the lane that went quiet.
        $this->assertSame('authed', $this->exposureOf([self::AUTHENTICATE_DESCENDANT]));
        $this->assertFalse($this->drawsPublicWrite([self::AUTHENTICATE_DESCENDANT]));
    }

    /**
     * The lane's whole remaining reason: the walk stops at `Authenticate`, so these still read
     * `public` and still draw the finding richter answers with its own ancestry walk.
     */
    #[Test]
    #[DataProvider('descendantsOfTheOtherAuthBases')]
    public function brain_still_reads_a_descendant_of_the_other_auth_bases_as_public(string $middleware): void
    {
        $this->assertSame('public', $this->exposureOf([$middleware]));
        $this->assertTrue($this->drawsPublicWrite([$middleware]));
    }

    /** @return Iterator<string, array{string}> */
    public static function descendantsOfTheOtherAuthBases(): Iterator
    {
        yield 'basic auth' => ['Acme\\Http\\Middleware\\RequireBasicCredentials'];
        yield 'email verification' => ['Acme\\Http\\Middleware\\VerifyCustomerEmail'];
        yield 'signed URL' => ['Acme\\Http\\Middleware\\CheckSignedLink'];
        yield 'signed URL, one class further out' => ['Acme\\Http\\Middleware\\CheckExpiringLink'];
    }

    #[Test]
    public function brain_reads_an_unregistered_alias_by_its_own_name(): void
    {
        // The framework's own aliases live in Laravel, not in the app's Kernel or bootstrap file, so
        // they usually reach Brain unresolved and match its patterns as written.
        $this->assertSame('authed', $this->exposureOf(['auth']));
        $this->assertSame('authed', $this->exposureOf(['verified']));
    }

    #[Test]
    public function brain_loses_the_verified_alias_once_the_app_registers_it(): void
    {
        // Registering the alias hands Brain the FQCN instead of the word, and
        // `Illuminate\Auth\Middleware\EnsureEmailIsVerified` matches no pattern, no basename and no
        // chain. `auth` survives the same treatment because its class IS the base the walk ends on.
        $registry = new MiddlewareRegistry([], [], [
            'auth' => 'Illuminate\\Auth\\Middleware\\Authenticate',
            'verified' => 'Illuminate\\Auth\\Middleware\\EnsureEmailIsVerified',
        ]);

        $this->assertSame('authed', $this->exposureOf(['auth'], $registry));
        $this->assertSame('public', $this->exposureOf(['verified'], $registry));
    }

    #[Test]
    public function brain_does_not_expand_a_named_middleware_group(): void
    {
        // The other shape neither side sees: the guard is real, and it reaches the route through a
        // group nobody resolves. Richter declines to expand groups too — mapping one onto every
        // route would report every route in the app for a change to any member.
        $registry = new MiddlewareRegistry([], ['api' => [self::AUTHENTICATE_DESCENDANT]], []);

        $this->assertSame('public', $this->exposureOf(['api'], $registry));
        $this->assertTrue($this->drawsPublicWrite(['api'], $registry));
    }
}
