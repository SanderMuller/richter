<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Analysis\Hazard;
use SanderMuller\Richter\Analysis\HazardFindings;
use SanderMuller\Richter\Analysis\Hazards\HazardLanes;
use SanderMuller\Richter\Changes\ChangedFileSymbols;
use SanderMuller\Richter\Tests\TestCase;

final class HazardLanesTest extends TestCase
{
    /** @return list<Hazard> */
    private function lanes(string $file, string $head, string $base): array
    {
        return HazardLanes::for($file, isNew: false, headSrc: $head, baseSrc: $base)[0];
    }

    /** @return list<string> */
    private function addedTokens(string $file, string $head, string $base): array
    {
        return HazardLanes::for($file, isNew: false, headSrc: $head, baseSrc: $base)[1];
    }

    private function controller(string $body, string $constructor = ''): string
    {
        return "<?php\nnamespace App\\Http\\Controllers;\nuse Illuminate\\Support\\Facades\\Gate;\nclass PostController\n{\n{$constructor}\n    public function update(\$post)\n    {\n{$body}\n    }\n}\n";
    }

    // ---------------------------------------------------------------- auth

    #[Test]
    public function an_authorize_call_dropped_from_a_surviving_method_is_tier_three(): void
    {
        $hazards = $this->lanes(
            'app/Http/Controllers/PostController.php',
            $this->controller('        return $post;'),
            $this->controller("        \$this->authorize('update', \$post);\n        return \$post;"),
        );

        $this->assertCount(1, $hazards);
        $this->assertSame('auth', $hazards[0]->lane);
        $this->assertSame(3, $hazards[0]->tier);
        $this->assertSame('CWE-862', $hazards[0]->cwe);
        $this->assertSame('App\Http\Controllers\PostController::update', $hazards[0]->member);
    }

    #[Test]
    public function a_gate_facade_check_dropped_is_tier_three(): void
    {
        $hazards = $this->lanes(
            'app/Http/Controllers/PostController.php',
            $this->controller('        return $post;'),
            $this->controller("        abort_if(Gate::denies('publish', \$post), 403);\n        return \$post;"),
        );

        $this->assertSame([3], array_column($hazards, 'tier'));
        $this->assertStringContainsString('ability:publish', $hazards[0]->evidence);
    }

    #[Test]
    public function rewriting_a_guard_without_losing_the_ability_draws_nothing(): void
    {
        // `Gate::denies('publish')` becomes `$user->cannot('publish')`. The ability is the same, so
        // comparing CALL SHAPES rather than abilities would report a removal that never happened.
        $hazards = $this->lanes(
            'app/Http/Controllers/PostController.php',
            $this->controller("        \$post->user()->cannot('publish', \$post);\n        return \$post;"),
            $this->controller("        abort_if(Gate::denies('publish', \$post), 403);\n        return \$post;"),
        );

        $this->assertSame([], $hazards);
    }

    #[Test]
    public function auth_middleware_gone_from_a_controller_constructor_is_tier_three(): void
    {
        $hazards = $this->lanes(
            'app/Http/Controllers/PostController.php',
            $this->controller('        return $post;', "    public function __construct()\n    {\n    }\n"),
            $this->controller('        return $post;', "    public function __construct()\n    {\n        \$this->middleware('auth');\n    }\n"),
        );

        $this->assertSame([3], array_column($hazards, 'tier'));
        $this->assertSame('CWE-306', $hazards[0]->cwe);
        $this->assertStringContainsString('middleware:auth', $hazards[0]->evidence);
    }

    #[Test]
    public function a_middleware_richter_does_not_recognise_as_a_guard_draws_nothing(): void
    {
        // Naming an application's own middleware a guard would be a guess, and a guess here is the
        // one thing this family never does.
        $hazards = $this->lanes(
            'app/Http/Controllers/PostController.php',
            $this->controller('        return $post;', "    public function __construct()\n    {\n    }\n"),
            $this->controller('        return $post;', "    public function __construct()\n    {\n        \$this->middleware('track-visits');\n    }\n"),
        );

        $this->assertSame([], $hazards);
    }

    #[Test]
    public function a_form_request_authorize_reduced_to_return_true_is_tier_three(): void
    {
        $head = "<?php\nnamespace App\\Http\\Requests;\nuse Illuminate\\Foundation\\Http\\FormRequest;\nclass StorePostRequest extends FormRequest\n{\n    public function authorize(): bool { return true; }\n}\n";
        $base = "<?php\nnamespace App\\Http\\Requests;\nuse Illuminate\\Foundation\\Http\\FormRequest;\nclass StorePostRequest extends FormRequest\n{\n    public function authorize(): bool { return \$this->user()->can('create'); }\n}\n";

        $hazards = $this->lanes('app/Http/Requests/StorePostRequest.php', $head, $base);

        $this->assertSame([3], array_column($hazards, 'tier'));
        $this->assertStringContainsString('return true;', $hazards[0]->evidence);
        // Nothing was NAMED, so nothing elsewhere in the diff can be this same guard arriving — an
        // empty token is what stops the whole-diff guard from silencing it.
        $this->assertSame([], $hazards[0]->removedTokens);
    }

    #[Test]
    public function an_authorize_that_already_returned_true_draws_nothing(): void
    {
        $source = "<?php\nnamespace App\\Http\\Requests;\nuse Illuminate\\Foundation\\Http\\FormRequest;\nclass StorePostRequest extends FormRequest\n{\n    public function authorize(): bool { return true; }\n    public function rules(): array { return ['title' => 'required']; }\n}\n";

        $this->assertSame([], $this->lanes('app/Http/Requests/StorePostRequest.php', $source, $source));
    }

    #[Test]
    public function a_deleted_policy_class_reports_once_not_once_per_method(): void
    {
        $base = "<?php\nnamespace App\\Policies;\nclass PostPolicy\n{\n    public function view(\$user, \$post): bool { return true; }\n    public function update(\$user, \$post): bool { return false; }\n    public function delete(\$user, \$post): bool { return false; }\n}\n";

        $hazards = $this->lanes('app/Policies/PostPolicy.php', '', $base);

        $this->assertCount(1, $hazards);
        $this->assertSame('App\Policies\PostPolicy', $hazards[0]->member);
        $this->assertSame('the policy class is gone', $hazards[0]->evidence);
    }

    #[Test]
    public function a_removed_policy_method_is_the_auth_lanes_not_the_contract_lanes(): void
    {
        // It is a guard, so it belongs at tier 3. Reporting the same deletion twice, at two tiers,
        // would read as two problems.
        $head = "<?php\nnamespace App\\Policies;\nclass PostPolicy\n{\n    public function view(\$user, \$post): bool { return true; }\n}\n";
        $base = "<?php\nnamespace App\\Policies;\nclass PostPolicy\n{\n    public function view(\$user, \$post): bool { return true; }\n    public function update(\$user, \$post): bool { return false; }\n}\n";

        $hazards = $this->lanes('app/Policies/PostPolicy.php', $head, $base);

        $this->assertSame(['auth'], array_column($hazards, 'lane'));
        $this->assertSame([3], array_column($hazards, 'tier'));
    }

    #[Test]
    public function a_guard_that_moved_between_two_methods_in_one_file_is_not_a_removal(): void
    {
        // The token is present in both file-wide sets, so a file-level difference would announce
        // nothing and the source method would report a removal. Counting additions per member is what
        // makes the destination visible to the whole-diff guard.
        $head = "<?php\nnamespace App\\Http\\Controllers;\nclass PostController\n{\n    public function update(\$post) { \$this->assertMayUpdate(\$post); return \$post; }\n    private function assertMayUpdate(\$post) { \$this->authorize('update', \$post); }\n}\n";
        $base = "<?php\nnamespace App\\Http\\Controllers;\nclass PostController\n{\n    public function update(\$post) { \$this->authorize('update', \$post); return \$post; }\n    private function assertMayUpdate(\$post) { }\n}\n";

        [$hazards, $tokens] = HazardLanes::for('app/Http/Controllers/PostController.php', isNew: false, headSrc: $head, baseSrc: $base);

        $this->assertContains('ability:update', $tokens);
        $this->assertSame(['auth'], array_column($hazards, 'lane'));
        $this->assertSame([], HazardFindings::for(
            [new ChangedFileSymbols('app/Http/Controllers/PostController.php', 'App\Http\Controllers\PostController', [], cosmeticOnly: false, hazards: $hazards, addedHazardTokens: $tokens)],
            enabledOverride: true,
        ));
    }

    #[Test]
    public function an_untouched_duplicate_guard_is_not_offered_as_an_added_token(): void
    {
        // Two methods check the same ability; the diff drops it from one. The token still exists in
        // the head, but the diff did not ADD it — offering it would let the untouched sibling suppress
        // the real removal, which is the whole-diff guard silencing exactly what it exists to pass.
        $body = static fn (string $update): string => "<?php\nnamespace App\\Http\\Controllers;\nclass PostController\n{\n    public function update(\$post) { {$update} return \$post; }\n    public function publish(\$post) { \$this->authorize('update', \$post); return \$post; }\n}\n";

        [$hazards, $tokens] = HazardLanes::for(
            'app/Http/Controllers/PostController.php',
            isNew: false,
            headSrc: $body(''),
            baseSrc: $body("\$this->authorize('update', \$post);"),
        );

        $this->assertNotContains('ability:update', $tokens);
        $this->assertSame(['App\Http\Controllers\PostController::update'], array_column($hazards, 'member'));
    }

    #[Test]
    public function a_removed_policy_method_is_named_by_its_constant_as_well_as_its_name(): void
    {
        // A caller may spell the ability either way. A project following the policy-constant
        // convention writes only `can(PostPolicy::DELETE)`, so a token holding the bare method name
        // alone could never match it, and moving the ability to a gate would read as a removed guard.
        // The constant is linked to the method by its literal VALUE, read from the policy's own
        // source — not by matching `DELETE` against `delete` by shape, which would be a guess.
        $head = "<?php\nnamespace App\\Policies;\nclass PostPolicy\n{\n    public const DELETE = 'delete';\n    public function view(\$user, \$post): bool { return true; }\n}\n";
        $base = "<?php\nnamespace App\\Policies;\nclass PostPolicy\n{\n    public const DELETE = 'delete';\n    public function view(\$user, \$post): bool { return true; }\n    public function delete(\$user, \$post): bool { return false; }\n}\n";

        $hazards = $this->lanes('app/Policies/PostPolicy.php', $head, $base);

        $this->assertSame(['ability:delete', 'ability:App\Policies\PostPolicy::DELETE'], $hazards[0]->removedTokens);
    }

    #[Test]
    public function a_policy_ability_moved_to_a_gate_in_the_same_diff_is_not_a_removal(): void
    {
        // End to end through the whole-diff guard: the policy method goes, and the controller starts
        // checking the same constant. That is a move, not a removal.
        $policyHead = "<?php\nnamespace App\\Policies;\nclass PostPolicy\n{\n    public const DELETE = 'delete';\n}\n";
        $policyBase = "<?php\nnamespace App\\Policies;\nclass PostPolicy\n{\n    public const DELETE = 'delete';\n    public function delete(\$user, \$post): bool { return false; }\n}\n";
        $controller = static fn (string $check): string => "<?php\nnamespace App\\Http\\Controllers;\nuse App\\Policies\\PostPolicy;\nclass PostController\n{\n    public function destroy(\$post) { {$check} return \$post; }\n}\n";

        [$policyHazards, $policyTokens] = HazardLanes::for('app/Policies/PostPolicy.php', isNew: false, headSrc: $policyHead, baseSrc: $policyBase);
        [$controllerHazards, $controllerTokens] = HazardLanes::for(
            'app/Http/Controllers/PostController.php',
            isNew: false,
            headSrc: $controller('$this->user()->can(PostPolicy::DELETE, $post);'),
            baseSrc: $controller(''),
        );

        $surviving = HazardFindings::for([
            new ChangedFileSymbols('app/Policies/PostPolicy.php', 'App\Policies\PostPolicy', [], cosmeticOnly: false, hazards: $policyHazards, addedHazardTokens: $policyTokens),
            new ChangedFileSymbols('app/Http/Controllers/PostController.php', 'App\Http\Controllers\PostController', [], cosmeticOnly: false, hazards: $controllerHazards, addedHazardTokens: $controllerTokens),
        ], enabledOverride: true);

        $this->assertSame([], $surviving);
    }

    #[Test]
    public function a_guard_the_head_side_still_holds_is_offered_as_an_added_token(): void
    {
        // The whole-diff guard needs this: the controller's removal is suppressed by whatever file
        // the ability arrived in, and this is how that file announces it.
        $tokens = $this->addedTokens(
            'app/Http/Requests/StorePostRequest.php',
            "<?php\nnamespace App\\Http\\Requests;\nclass StorePostRequest\n{\n    public function authorize(): bool { return \$this->user()->can('update'); }\n}\n",
            "<?php\nnamespace App\\Http\\Requests;\nclass StorePostRequest\n{\n    public function authorize(): bool { return true; }\n}\n",
        );

        $this->assertContains('ability:update', $tokens);
    }

    #[Test]
    public function a_policy_constant_ability_survives_a_rewrite_between_call_shapes(): void
    {
        // `cannot(Policy::DELETE)` becomes `can(Policy::DELETE)` — the same ability, the same policy,
        // inside the same callback. Keying the token on string literals alone made a class constant
        // fall back to the CALL's name, so `call:cannot` and `call:can` could never match and every
        // such rewrite read as a removed guard at tier 3. A codebase following Laravel's own
        // policy-constant convention has no string abilities at all, so the defence was off for all
        // of them.
        $body = static fn (string $check): string => "<?php\nnamespace App\\Http\\Controllers;\nuse App\\Policies\\PostPolicy;\nclass PostController\n{\n    public function destroy(\$post) { {$check} return \$post; }\n}\n";

        $hazards = $this->lanes(
            'app/Http/Controllers/PostController.php',
            $body('$this->user()->can(PostPolicy::DELETE, $post);'),
            $body('$this->user()->cannot(PostPolicy::DELETE, $post);'),
        );

        $this->assertSame([], $hazards);
    }

    #[Test]
    public function an_ability_inside_a_closure_that_becomes_an_arrow_function_still_matches(): void
    {
        // The reported shape: the check lives inside an `->authorize(function () { … })` callback that
        // the diff rewrites as an arrow function, inverting `cannot … return false` to `can`. Both
        // forms have to be traversed, or the base token is found and the head one is not — which reads
        // as a removal just as surely as the constant fallback did.
        $base = "<?php\nnamespace App\\Livewire;\nuse App\\Policies\\PostPolicy;\nclass PostIndex\n{\n    public function actions()\n    {\n        return \$this->action()->authorize(function (\$post): bool {\n            if (auth()->user()->cannot(PostPolicy::DELETE, \$post)) {\n                return false;\n            }\n\n            return \$this->stillEditable(\$post);\n        });\n    }\n}\n";
        $head = "<?php\nnamespace App\\Livewire;\nuse App\\Policies\\PostPolicy;\nclass PostIndex\n{\n    public function actions()\n    {\n        return \$this->action()->authorize(fn (\$post): bool => auth()->user()->can(PostPolicy::DELETE, \$post));\n    }\n}\n";

        $this->assertSame([], $this->lanes('app/Livewire/PostIndex.php', $head, $base));
    }

    #[Test]
    public function swapping_one_constant_ability_for_another_is_a_removal(): void
    {
        // The under-fire with the same root as the false positive: two DIFFERENT abilities on the same
        // call shape both fell back to `call:can`, so exchanging one for the other cancelled out and
        // reported nothing. Distinct tokens make the swap visible.
        $body = static fn (string $ability): string => "<?php\nnamespace App\\Http\\Controllers;\nuse App\\Policies\\PostPolicy;\nclass PostController\n{\n    public function destroy(\$post) { \$this->user()->can(PostPolicy::{$ability}, \$post); return \$post; }\n}\n";

        $hazards = $this->lanes(
            'app/Http/Controllers/PostController.php',
            $body('VIEW'),
            $body('DELETE'),
        );

        $this->assertSame([3], array_column($hazards, 'tier'));
        $this->assertStringContainsString('PostPolicy::DELETE', $hazards[0]->evidence);
    }

    #[Test]
    public function a_policy_constant_ability_genuinely_removed_still_fires(): void
    {
        // The inverse must keep working: the check going away entirely is a real removal.
        $body = static fn (string $check): string => "<?php\nnamespace App\\Http\\Controllers;\nuse App\\Policies\\PostPolicy;\nclass PostController\n{\n    public function destroy(\$post) { {$check} return \$post; }\n}\n";

        $hazards = $this->lanes(
            'app/Http/Controllers/PostController.php',
            $body(''),
            $body('$this->user()->cannot(PostPolicy::DELETE, $post);'),
        );

        $this->assertSame([3], array_column($hazards, 'tier'));
        $this->assertStringContainsString('ability:App\Policies\PostPolicy::DELETE', $hazards[0]->evidence);
    }

    #[Test]
    public function a_policy_constant_ability_is_tokenised_on_its_resolved_class(): void
    {
        // Two files referencing the same constant must produce the same token, or a guard moving
        // between them cannot be recognised. The class is resolved, so an import and a fully
        // qualified reference agree.
        $imported = "<?php\nnamespace App\\Http\\Requests;\nuse App\\Policies\\PostPolicy;\nclass DeletePostRequest\n{\n    public function authorize(): bool { return \$this->user()->can(PostPolicy::DELETE); }\n}\n";
        $qualified = "<?php\nnamespace App\\Http\\Requests;\nclass DeletePostRequest\n{\n    public function authorize(): bool { return \$this->user()->can(\\App\\Policies\\PostPolicy::DELETE); }\n}\n";
        $without = "<?php\nnamespace App\\Http\\Requests;\nclass DeletePostRequest\n{\n    public function authorize(): bool { return true; }\n}\n";

        $fromImported = HazardLanes::for('app/Http/Requests/DeletePostRequest.php', isNew: false, headSrc: $imported, baseSrc: $without)[1];
        $fromQualified = HazardLanes::for('app/Http/Requests/DeletePostRequest.php', isNew: false, headSrc: $qualified, baseSrc: $without)[1];

        $this->assertSame(['ability:App\Policies\PostPolicy::DELETE'], $fromImported);
        $this->assertSame($fromImported, $fromQualified);
    }

    #[Test]
    public function a_can_call_on_something_that_is_not_a_user_is_not_a_guard(): void
    {
        // `$encoder->can('json')` is a capability question about an encoder. Counting every method
        // named `can` as authorization would report a tier-3 hazard for deleting it — the exact false
        // positive that teaches a reader to stop reading the report.
        $hazards = $this->lanes(
            'app/Http/Controllers/PostController.php',
            $this->controller('        return $post;'),
            $this->controller("        \$encoder->can('json');\n        return \$post;"),
        );

        $this->assertSame([], $hazards);
    }

    #[Test]
    public function a_can_call_on_a_user_receiver_is_a_guard(): void
    {
        $hazards = $this->lanes(
            'app/Http/Controllers/PostController.php',
            $this->controller('        return $post;'),
            $this->controller("        \$request->user()->cannot('publish', \$post);\n        return \$post;"),
        );

        $this->assertSame([3], array_column($hazards, 'tier'));
        $this->assertStringContainsString('ability:publish', $hazards[0]->evidence);
    }

    #[Test]
    public function a_policy_method_moved_to_a_gate_call_is_not_a_removal(): void
    {
        // The policy method's token has to be shaped like the ability every other lane emits, or the
        // whole-diff guard can never match it and a genuine move reports as a removal for ever.
        $policyHead = "<?php\nnamespace App\\Policies;\nclass PostPolicy\n{\n    public function view(\$user, \$post): bool { return true; }\n}\n";
        $policyBase = "<?php\nnamespace App\\Policies;\nclass PostPolicy\n{\n    public function view(\$user, \$post): bool { return true; }\n    public function update(\$user, \$post): bool { return false; }\n}\n";

        [$policyHazards] = HazardLanes::for('app/Policies/PostPolicy.php', isNew: false, headSrc: $policyHead, baseSrc: $policyBase);
        [, $controllerTokens] = HazardLanes::for(
            'app/Http/Controllers/PostController.php',
            isNew: false,
            headSrc: $this->controller("        \$this->authorize('update', \$post);\n        return \$post;"),
            baseSrc: $this->controller('        return $post;'),
        );

        $this->assertSame([['ability:update']], array_column($policyHazards, 'removedTokens'));
        $this->assertContains('ability:update', $controllerTokens);
    }

    #[Test]
    public function a_deleted_class_with_no_members_still_reports(): void
    {
        // Derived from the member map, an empty class has no rows to derive a deletion from — and
        // every `new` on it still breaks.
        $base = "<?php\nnamespace App\\Support;\nclass EmptyMarker\n{\n}\n";

        $hazards = $this->lanes('app/Support/EmptyMarker.php', '', $base);

        $this->assertSame(['App\Support\EmptyMarker'], array_column($hazards, 'member'));
        $this->assertSame('the class is gone', $hazards[0]->evidence);
    }

    // --------------------------------------------------------------- model

    private function model(string $members): string
    {
        return "<?php\nnamespace App\\Models;\nclass Post\n{\n{$members}\n}\n";
    }

    #[Test]
    public function fillable_gaining_an_entry_widens_mass_assignment_at_tier_two(): void
    {
        $hazards = $this->lanes(
            'app/Models/Post.php',
            $this->model("    protected \$fillable = ['title', 'status'];"),
            $this->model("    protected \$fillable = ['title'];"),
        );

        $this->assertSame(['model'], array_column($hazards, 'lane'));
        $this->assertSame([2], array_column($hazards, 'tier'));
        $this->assertSame('CWE-915', $hazards[0]->cwe);
        $this->assertStringContainsString('status', $hazards[0]->evidence);
    }

    #[Test]
    public function hidden_losing_an_entry_is_tier_three(): void
    {
        $hazards = $this->lanes(
            'app/Models/Post.php',
            $this->model("    protected \$hidden = ['secret'];"),
            $this->model("    protected \$hidden = ['secret', 'internal_note'];"),
        );

        $this->assertSame([3], array_column($hazards, 'tier'));
        $this->assertSame('CWE-200', $hazards[0]->cwe);
        $this->assertStringContainsString('internal_note', $hazards[0]->evidence);
    }

    #[Test]
    public function guarded_emptied_is_the_widest_possible_write_surface(): void
    {
        $hazards = $this->lanes(
            'app/Models/Post.php',
            $this->model('    protected $guarded = [];'),
            $this->model("    protected \$guarded = ['*'];"),
        );

        $this->assertSame([2], array_column($hazards, 'tier'));
        $this->assertStringContainsString('*', $hazards[0]->evidence);
    }

    #[Test]
    public function a_cast_changed_on_a_surviving_key_is_tier_two(): void
    {
        $hazards = $this->lanes(
            'app/Models/Post.php',
            $this->model("    protected \$casts = ['published_at' => 'string'];"),
            $this->model("    protected \$casts = ['published_at' => 'datetime'];"),
        );

        $this->assertSame([2], array_column($hazards, 'tier'));
        $this->assertStringContainsString('published_at', $hazards[0]->evidence);
    }

    #[Test]
    public function a_cast_only_added_draws_nothing(): void
    {
        // An addition is the parity lane's business and already classifies additive.
        $this->assertSame([], $this->lanes(
            'app/Models/Post.php',
            $this->model("    protected \$casts = ['published_at' => 'datetime', 'archived' => 'bool'];"),
            $this->model("    protected \$casts = ['published_at' => 'datetime'];"),
        ));
    }

    #[Test]
    public function a_non_enumerable_declaration_yields_nothing(): void
    {
        // A spread cannot be compared key by key, and half a comparison is not a comparison.
        $this->assertSame([], $this->lanes(
            'app/Models/Post.php',
            $this->model("    protected \$fillable = [...self::BASE, 'status'];"),
            $this->model('    protected $fillable = [...self::BASE];'),
        ));
    }

    // ------------------------------------------------------------ contract

    #[Test]
    public function a_removed_public_method_is_tier_two_and_a_private_one_is_nothing(): void
    {
        $head = "<?php\nnamespace App\\Services;\nclass Publisher\n{\n    public function keep(): void {}\n}\n";
        $base = "<?php\nnamespace App\\Services;\nclass Publisher\n{\n    public function keep(): void {}\n    public function publish(): void {}\n    private function helper(): void {}\n}\n";

        $hazards = $this->lanes('app/Services/Publisher.php', $head, $base);

        $this->assertSame(['App\Services\Publisher::publish'], array_column($hazards, 'member'));
        $this->assertSame([2], array_column($hazards, 'tier'));
    }

    #[Test]
    public function a_surviving_members_changed_parameter_list_is_tier_one(): void
    {
        $head = "<?php\nnamespace App\\Services;\nclass Publisher\n{\n    public function publish(int \$id, bool \$force = false): void {}\n}\n";
        $base = "<?php\nnamespace App\\Services;\nclass Publisher\n{\n    public function publish(int \$id): void {}\n}\n";

        $hazards = $this->lanes('app/Services/Publisher.php', $head, $base);

        $this->assertSame([1], array_column($hazards, 'tier'));
        $this->assertStringContainsString('parameter list changed', $hazards[0]->evidence);
    }

    #[Test]
    public function a_rename_surfaces_as_a_removal_rather_than_a_guess(): void
    {
        // Pairing a removal with an addition is a guess. The removal is the honest floor.
        $head = "<?php\nnamespace App\\Services;\nclass Publisher\n{\n    public function publishNow(): void {}\n}\n";
        $base = "<?php\nnamespace App\\Services;\nclass Publisher\n{\n    public function publish(): void {}\n}\n";

        $hazards = $this->lanes('app/Services/Publisher.php', $head, $base);

        $this->assertSame(['App\Services\Publisher::publish'], array_column($hazards, 'member'));
    }

    // ------------------------------------------------------------ boundary

    private function request(string $rules): string
    {
        return "<?php\nnamespace App\\Http\\Requests;\nclass StorePostRequest\n{\n    public function rules(): array\n    {\n        return {$rules};\n    }\n}\n";
    }

    #[Test]
    public function a_dropped_constraint_on_a_surviving_field_is_tier_two(): void
    {
        $hazards = $this->lanes(
            'app/Http/Requests/StorePostRequest.php',
            $this->request("['title' => 'string|max:255']"),
            $this->request("['title' => 'required|string|max:255']"),
        );

        $this->assertSame(['boundary'], array_column($hazards, 'lane'));
        $this->assertSame([2], array_column($hazards, 'tier'));
        $this->assertSame('CWE-20', $hazards[0]->cwe);
        $this->assertStringContainsString('required', $hazards[0]->evidence);
    }

    #[Test]
    public function the_array_rule_syntax_is_read_the_same_way(): void
    {
        $hazards = $this->lanes(
            'app/Http/Requests/StorePostRequest.php',
            $this->request("['avatar' => ['image']]"),
            $this->request("['avatar' => ['image', 'mimes:png']]"),
        );

        $this->assertStringContainsString('mimes', $hazards[0]->evidence);
    }

    #[Test]
    public function a_field_removed_entirely_belongs_to_the_parity_lane_not_this_one(): void
    {
        $this->assertSame([], $this->lanes(
            'app/Http/Requests/StorePostRequest.php',
            $this->request("['title' => 'required']"),
            $this->request("['title' => 'required', 'subtitle' => 'required|max:80']"),
        ));
    }

    #[Test]
    public function a_rule_list_that_cannot_be_read_in_full_draws_nothing(): void
    {
        // `Rule::unique(…)` makes the list unknowable, and comparing a partial list against a full
        // one would invent removals.
        $this->assertSame([], $this->lanes(
            'app/Http/Requests/StorePostRequest.php',
            $this->request("['title' => ['required', Rule::unique('posts')]]"),
            $this->request("['title' => ['required', 'max:80', Rule::unique('posts')]]"),
        ));
    }

    #[Test]
    public function a_data_array_passed_beside_the_rules_is_not_read_as_rules(): void
    {
        // `Validator::make($data, $rules)` — the rules are the SECOND argument. Reading whichever
        // argument happens to be an array literal would let an edit to the data invent a hazard.
        $controller = static fn (string $data): string => "<?php\nnamespace App\\Http\\Controllers;\nuse Illuminate\\Support\\Facades\\Validator;\nclass PostController\n{\n    public function store() { return Validator::make({$data}, ['title' => 'required|max:80']); }\n}\n";

        $hazards = $this->lanes(
            'app/Http/Controllers/PostController.php',
            $controller("['title' => 'image']"),
            $controller("['title' => 'required|image']"),
        );

        $this->assertSame([], $hazards);
    }

    #[Test]
    public function a_queued_jobs_changed_constructor_is_tier_two(): void
    {
        $head = "<?php\nnamespace App\\Jobs;\nuse Illuminate\\Contracts\\Queue\\ShouldQueue;\nclass ImportJob implements ShouldQueue\n{\n    public function __construct(public int \$postId, public bool \$force) {}\n}\n";
        $base = "<?php\nnamespace App\\Jobs;\nuse Illuminate\\Contracts\\Queue\\ShouldQueue;\nclass ImportJob implements ShouldQueue\n{\n    public function __construct(public int \$postId) {}\n}\n";

        $hazards = $this->lanes('app/Jobs/ImportJob.php', $head, $base);

        $this->assertContains('boundary', array_column($hazards, 'lane'));
        $this->assertContains('App\Jobs\ImportJob::__construct', array_column($hazards, 'member'));
    }

    #[Test]
    public function an_imported_validator_facade_is_still_recognised(): void
    {
        // The facade stays the bare `Validator` token in the AST. Matching on that rather than the
        // resolved name would miss every file that imports it, so a dropped `required` would fire no
        // hazard and a tested route would go on to read LOW.
        $controller = static fn (string $rules): string => "<?php\nnamespace App\\Http\\Controllers;\nuse Illuminate\\Support\\Facades\\Validator;\nclass PostController\n{\n    public function store(\$request) { return Validator::make(\$request->all(), {$rules}); }\n}\n";

        $hazards = $this->lanes(
            'app/Http/Controllers/PostController.php',
            $controller("['title' => 'string']"),
            $controller("['title' => 'required|string']"),
        );

        $this->assertSame(['boundary'], array_column($hazards, 'lane'));
        $this->assertStringContainsString('required', $hazards[0]->evidence);
    }

    #[Test]
    public function a_class_that_drops_should_queue_while_changing_its_constructor_reports_once(): void
    {
        // The base side was queued, so jobs are already on the wire against the old signature — the
        // class no longer declaring itself queued does not un-queue them. Checking only the head side
        // would defer nothing and report the same change at two tiers.
        $head = "<?php\nnamespace App\\Work;\nclass Importer\n{\n    public function __construct(public int \$id, public bool \$force) {}\n}\n";
        $base = "<?php\nnamespace App\\Work;\nuse Illuminate\\Contracts\\Queue\\ShouldQueue;\nclass Importer implements ShouldQueue\n{\n    public function __construct(public int \$id) {}\n}\n";

        $hazards = $this->lanes('app/Work/Importer.php', $head, $base);

        $this->assertSame(['boundary'], array_column($hazards, 'lane'));
        $this->assertSame([2], array_column($hazards, 'tier'));
    }

    #[Test]
    public function a_queued_constructor_change_is_one_hazard_not_two(): void
    {
        // The boundary lane owns it at tier 2 — jobs already on the queue were serialised against the
        // old signature. The contract lane defers rather than reporting the same change at tier 1.
        $job = static fn (string $params): string => "<?php\nnamespace App\\Jobs;\nuse Illuminate\\Contracts\\Queue\\ShouldQueue;\nclass ImportJob implements ShouldQueue\n{\n    public function __construct({$params}) {}\n}\n";

        $hazards = $this->lanes('app/Jobs/ImportJob.php', $job('public int $postId, public bool $force'), $job('public int $postId'));

        $this->assertCount(1, $hazards);
        $this->assertSame('boundary', $hazards[0]->lane);
        $this->assertSame(2, $hazards[0]->tier);
    }

    #[Test]
    public function an_unqueued_classes_constructor_change_stays_with_the_contract_lane(): void
    {
        $service = static fn (string $params): string => "<?php\nnamespace App\\Services;\nclass Publisher\n{\n    public function __construct({$params}) {}\n}\n";

        $hazards = $this->lanes('app/Services/Publisher.php', $service('int $id, bool $force = false'), $service('int $id'));

        $this->assertSame(['contract'], array_column($hazards, 'lane'));
        $this->assertSame([1], array_column($hazards, 'tier'));
    }

    // ------------------------------------------------------------ refusals

    #[Test]
    public function a_new_file_fires_no_lane_because_every_predicate_is_a_comparison(): void
    {
        $source = "<?php\nnamespace App\\Models;\nclass Post\n{\n    protected \$fillable = ['title'];\n}\n";

        $this->assertSame([[], []], HazardLanes::for('app/Models/Post.php', isNew: true, headSrc: $source, baseSrc: null));
    }

    #[Test]
    public function an_unparseable_side_yields_nothing_rather_than_half_a_comparison(): void
    {
        $this->assertSame([], $this->lanes(
            'app/Models/Post.php',
            "<?php\nnamespace App\\Models;\nclass Post { protected \$fillable = ['title', 'status'];",
            $this->model("    protected \$fillable = ['title'];"),
        ));
    }

    #[Test]
    public function a_file_outside_app_is_never_classified(): void
    {
        $this->assertSame([], $this->lanes(
            'routes/web.php',
            "<?php\nRoute::get('/posts', [PostController::class, 'index']);\n",
            "<?php\nRoute::get('/posts', [PostController::class, 'index'])->middleware('auth');\n",
        ));
    }
}
