<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Tests\TestCase;
use SanderMuller\Richter\Tracers\InertiaPageChecker;

final class InertiaPageCheckerTest extends TestCase
{
    private function checker(): InertiaPageChecker
    {
        return new InertiaPageChecker(self::fixtureProjectPath());
    }

    #[Test]
    public function a_facade_render_resolves_the_page_file(): void
    {
        $findings = $this->checker()->findingsFor(<<<'PHP'
            <?php
            use Inertia\Inertia;

            class PostController
            {
                public function show(): mixed
                {
                    return Inertia::render('Posts/Show', ['post' => 1]);
                }
            }
            PHP);

        $this->assertSame(
            ["renders Inertia page 'Posts/Show' (resources/js/Pages/Posts/Show.vue) — that page is part of this change's surface"],
            $findings,
        );
    }

    #[Test]
    public function the_inertia_helper_and_an_aliased_facade_both_resolve(): void
    {
        $findings = $this->checker()->findingsFor(<<<'PHP'
            <?php
            use Inertia\Inertia as I;

            class PostController
            {
                public function show(): mixed
                {
                    return I::render('Posts/Show');
                }

                public function edit(): mixed
                {
                    return inertia('Posts/Show');
                }
            }
            PHP);

        $this->assertCount(1, $findings);
        $this->assertStringContainsString("'Posts/Show' (resources/js/Pages/Posts/Show.vue)", $findings[0]);
    }

    #[Test]
    public function a_missing_page_file_is_noted_rather_than_guessed(): void
    {
        $findings = $this->checker()->findingsFor(<<<'PHP'
            <?php
            use Inertia\Inertia;

            class GhostController
            {
                public function show(): mixed
                {
                    return Inertia::render('Ghost/Missing');
                }
            }
            PHP);

        $this->assertSame(
            ["renders Inertia page 'Ghost/Missing' — no page file found under resources/js/Pages"],
            $findings,
        );
    }

    #[Test]
    public function a_render_outside_the_changed_members_is_not_noted(): void
    {
        $source = <<<'PHP'
            <?php
            use Inertia\Inertia;

            class PostController
            {
                public function show(): mixed
                {
                    return Inertia::render('Posts/Show');
                }
            }
            PHP;

        $this->assertSame([], $this->checker()->findingsFor($source, [[20, 30]]));
        $this->assertCount(1, $this->checker()->findingsFor($source, [[6, 10]]));
    }

    #[Test]
    public function a_dynamic_component_name_is_silently_not_a_finding(): void
    {
        $findings = $this->checker()->findingsFor(<<<'PHP'
            <?php
            use Inertia\Inertia;

            class PostController
            {
                public function show(string $page): mixed
                {
                    return Inertia::render($page);
                }
            }
            PHP);

        $this->assertSame([], $findings);
    }

    #[Test]
    public function an_unrelated_render_call_never_matches(): void
    {
        $findings = $this->checker()->findingsFor(<<<'PHP'
            <?php
            use Illuminate\Support\Facades\View;

            class ReportController
            {
                public function show(): mixed
                {
                    return View::render('Posts/Show');
                }
            }
            PHP);

        $this->assertSame([], $findings);
    }
}
