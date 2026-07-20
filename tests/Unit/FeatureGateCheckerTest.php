<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Changes\ChangedSymbols;
use SanderMuller\Richter\Tests\TestCase;
use SanderMuller\Richter\Tracers\FeatureGateChecker;

final class FeatureGateCheckerTest extends TestCase
{
    /** @param  list<string>  $featureGateMethods */
    private function checker(array $featureGateMethods = []): FeatureGateChecker
    {
        return new FeatureGateChecker($featureGateMethods);
    }

    #[Test]
    public function a_feature_active_check_with_a_string_flag_is_noted(): void
    {
        $findings = $this->checker()->findingsFor(<<<'PHP'
            <?php
            use Laravel\Pennant\Feature;

            class X {
                public function run(): bool
                {
                    return Feature::active('ai-coach');
                }
            }
            PHP);

        $this->assertSame(["checks feature flag 'ai-coach' — behaviour behind this flag only runs where it is active"], $findings);
    }

    #[Test]
    public function a_backed_enum_flag_resolves_to_its_value(): void
    {
        // The fixture enum autoloads (composer maps App\ to the fixture project), so the case
        // resolves to its backing string instead of rendering verbatim.
        $findings = $this->checker()->findingsFor(<<<'PHP'
            <?php
            use App\Enums\ExperimentFlag;
            use Laravel\Pennant\Feature;

            class X {
                public function run(): void
                {
                    Feature::when(ExperimentFlag::NewCheckout, fn () => null, fn () => null);
                }
            }
            PHP);

        $this->assertStringContainsString("'new-checkout'", $findings[0]);
    }

    #[Test]
    public function an_unresolvable_enum_flag_renders_verbatim_instead_of_disappearing(): void
    {
        $findings = $this->checker()->findingsFor(<<<'PHP'
            <?php
            use App\Enums\DoesNotExist;
            use Laravel\Pennant\Feature;

            class X {
                public function run(): bool
                {
                    return Feature::inactive(DoesNotExist::SomeCase);
                }
            }
            PHP);

        $this->assertStringContainsString("'DoesNotExist::SomeCase'", $findings[0]);
    }

    #[Test]
    public function a_non_pennant_feature_class_is_ignored(): void
    {
        $findings = $this->checker()->findingsFor(<<<'PHP'
            <?php
            use App\Support\Feature as Toggles;

            class X {
                public function run(): bool
                {
                    return Toggles::active('something') && \App\Other\Feature::active('x');
                }
            }
            PHP);

        $this->assertSame([], $findings);
    }

    #[Test]
    public function duplicate_flags_note_once_and_sort(): void
    {
        $findings = $this->checker()->findingsFor(<<<'PHP'
            <?php
            use Laravel\Pennant\Feature;

            class X {
                public function a(): bool { return Feature::active('zeta'); }
                public function b(): bool { return Feature::inactive('zeta'); }
                public function c(): bool { return Feature::active('alpha'); }
            }
            PHP);

        $this->assertCount(2, $findings);
        $this->assertStringContainsString("'alpha'", $findings[0]);
        $this->assertStringContainsString("'zeta'", $findings[1]);
    }

    #[Test]
    public function a_fluent_scoped_check_is_detected_through_its_receiver_chain(): void
    {
        $findings = $this->checker()->findingsFor(<<<'PHP'
            <?php
            use Laravel\Pennant\Feature;

            class X {
                public function run(object $user): bool
                {
                    return Feature::for($user)->active('ai-coach');
                }
            }
            PHP);

        $this->assertCount(1, $findings);
        $this->assertStringContainsString("'ai-coach'", $findings[0]);
    }

    #[Test]
    public function a_fluent_chain_on_a_non_feature_root_is_ignored(): void
    {
        $findings = $this->checker()->findingsFor(<<<'PHP'
            <?php
            use App\Support\Toggles;

            class X {
                public function run(object $user): bool
                {
                    return Toggles::for($user)->active('something');
                }
            }
            PHP);

        $this->assertSame([], $findings);
    }

    #[Test]
    public function an_aggregate_check_with_an_array_of_flags_notes_each_flag(): void
    {
        $findings = $this->checker()->findingsFor(<<<'PHP'
            <?php
            use Laravel\Pennant\Feature;

            class X {
                public function run(): bool
                {
                    return Feature::allAreActive(['alpha', 'beta']);
                }
            }
            PHP);

        $this->assertCount(2, $findings);
        $this->assertStringContainsString("'alpha'", $findings[0]);
        $this->assertStringContainsString("'beta'", $findings[1]);
    }

    #[Test]
    public function line_ranges_scope_the_scan_to_the_changed_members(): void
    {
        $source = <<<'PHP'
            <?php
            use Laravel\Pennant\Feature;

            class X {
                public function untouched(): bool
                {
                    return Feature::active('beta');
                }

                public function changed(): string
                {
                    return 'no flag here';
                }
            }
            PHP;

        // Only the changed() span (lines 10-13): the untouched sibling's check must not leak in.
        $this->assertSame([], $this->checker()->findingsFor($source, [[10, 13]]));
        // The untouched() span (lines 5-8) does contain the check.
        $this->assertCount(1, $this->checker()->findingsFor($source, [[5, 8]]));
    }

    #[Test]
    public function unparseable_source_yields_no_findings(): void
    {
        $this->assertSame([], $this->checker()->findingsFor('<?php class {'));
    }

    #[Test]
    public function a_blade_feature_directive_is_noted(): void
    {
        $findings = $this->checker()->bladeFindingsFor(<<<'BLADE'
            @feature('ai-coach')
                <x-coach-panel />
            @endfeature
            BLADE);

        $this->assertSame(["checks feature flag 'ai-coach' — behaviour behind this flag only runs where it is active"], $findings);
    }

    #[Test]
    public function a_blade_view_without_directives_yields_nothing(): void
    {
        $this->assertSame([], $this->checker()->bladeFindingsFor('<div>{{ $post->title }}</div>'));
    }

    #[Test]
    public function a_changed_member_with_a_flag_check_carries_the_finding_end_to_end(): void
    {
        $head = "<?php\nnamespace App\\Services;\nuse Laravel\\Pennant\\Feature;\nclass X {\n    public function run(): bool\n    {\n        return Feature::active('ai-coach');\n    }\n}\n";
        $base = "<?php\nnamespace App\\Services;\nclass X {\n    public function run(): bool\n    {\n        return true;\n    }\n}\n";
        $hunk = [
            'added' => [['line' => 7, 'text' => "        return Feature::active('ai-coach');"]],
            'removed' => [['line' => 5, 'text' => '        return true;']],
        ];

        $symbols = ChangedSymbols::classifyFile('app/Services/X.php', $head, $base, $hunk);

        $this->assertContains(
            "checks feature flag 'ai-coach' — behaviour behind this flag only runs where it is active",
            $symbols->findings,
        );
    }

    #[Test]
    public function a_configured_enum_wrapper_call_resolves_to_its_backing_flag(): void
    {
        $findings = $this->checker(['App\\Enums\\FeatureToggle::isActive'])->findingsFor(<<<'PHP'
            <?php
            use App\Enums\FeatureToggle;

            class X {
                public function run(): bool
                {
                    return FeatureToggle::BETA_DASHBOARD->isActive();
                }
            }
            PHP);

        $this->assertSame(["checks feature flag 'beta-dashboard' — behaviour behind this flag only runs where it is active"], $findings);
    }

    #[Test]
    public function an_unresolvable_configured_wrapper_class_renders_verbatim_instead_of_disappearing(): void
    {
        $findings = $this->checker(['App\\Enums\\DoesNotExist::isActive'])->findingsFor(<<<'PHP'
            <?php
            use App\Enums\DoesNotExist;

            class X {
                public function run(): bool
                {
                    return DoesNotExist::SOME_CASE->isActive();
                }
            }
            PHP);

        $this->assertStringContainsString("'DoesNotExist::SOME_CASE'", $findings[0]);
    }

    #[Test]
    public function an_enum_wrapper_call_without_a_matching_config_entry_is_not_annotated(): void
    {
        $findings = $this->checker()->findingsFor(<<<'PHP'
            <?php
            use App\Enums\FeatureToggle;

            class X {
                public function run(): bool
                {
                    return FeatureToggle::BETA_DASHBOARD->isActive();
                }
            }
            PHP);

        $this->assertSame([], $findings);
    }

    #[Test]
    public function a_non_enum_receiver_is_never_guessed_as_a_wrapper_call(): void
    {
        $findings = $this->checker(['App\\Enums\\FeatureToggle::isActive'])->findingsFor(<<<'PHP'
            <?php
            class X {
                public function run(object $service): bool
                {
                    return $service->isActive();
                }
            }
            PHP);

        $this->assertSame([], $findings);
    }

    #[Test]
    public function a_wrapper_call_outside_the_changed_line_ranges_is_not_annotated(): void
    {
        $source = <<<'PHP'
            <?php
            use App\Enums\FeatureToggle;

            class X {
                public function untouched(): bool
                {
                    return FeatureToggle::BETA_DASHBOARD->isActive();
                }

                public function changed(): string
                {
                    return 'no flag here';
                }
            }
            PHP;

        // Only the changed() span (lines 10-13): the untouched sibling's wrapper call must not leak in.
        $this->assertSame([], $this->checker(['App\\Enums\\FeatureToggle::isActive'])->findingsFor($source, [[10, 13]]));
        // The untouched() span (lines 5-8) does contain the wrapper call.
        $this->assertCount(1, $this->checker(['App\\Enums\\FeatureToggle::isActive'])->findingsFor($source, [[5, 8]]));
    }

    #[Test]
    public function the_facade_and_blade_paths_still_annotate_alongside_a_configured_wrapper(): void
    {
        // Regression: configuring a wrapper allowlist must not disturb the built-in Feature-facade
        // detection or the @feature Blade directive.
        $findings = $this->checker(['App\\Enums\\FeatureToggle::isActive'])->findingsFor(<<<'PHP'
            <?php
            use Laravel\Pennant\Feature;

            class X {
                public function run(): bool
                {
                    return Feature::active('ai-coach');
                }
            }
            PHP);

        $this->assertSame(["checks feature flag 'ai-coach' — behaviour behind this flag only runs where it is active"], $findings);

        $bladeFindings = $this->checker(['App\\Enums\\FeatureToggle::isActive'])->bladeFindingsFor(<<<'BLADE'
            @feature('ai-coach')
                <x-coach-panel />
            @endfeature
            BLADE);

        $this->assertSame(["checks feature flag 'ai-coach' — behaviour behind this flag only runs where it is active"], $bladeFindings);
    }

    #[Test]
    public function an_untouched_siblings_flag_check_never_implies_the_change_is_gated(): void
    {
        // gated() carries the flag check in BOTH head and base; only other() changed. Reporting
        // the flag would hand the reviewer a wrong blast-radius signal.
        $head = "<?php\nnamespace App\\Services;\nuse Laravel\\Pennant\\Feature;\nclass X {\n    public function gated(): bool\n    {\n        return Feature::active('beta');\n    }\n\n    public function other(): int\n    {\n        return 2;\n    }\n}\n";
        $base = "<?php\nnamespace App\\Services;\nuse Laravel\\Pennant\\Feature;\nclass X {\n    public function gated(): bool\n    {\n        return Feature::active('beta');\n    }\n\n    public function other(): int\n    {\n        return 1;\n    }\n}\n";
        $hunk = [
            'added' => [['line' => 12, 'text' => '        return 2;']],
            'removed' => [['line' => 12, 'text' => '        return 1;']],
        ];

        $symbols = ChangedSymbols::classifyFile('app/Services/X.php', $head, $base, $hunk);

        $this->assertSame([], $symbols->findings);
    }
}
