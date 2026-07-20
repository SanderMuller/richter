<?php declare(strict_types=1);

namespace SanderMuller\Richter\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use SanderMuller\Richter\Tests\TestCase;
use SanderMuller\Richter\Tracers\FrontendReferenceScanner;

final class FrontendReferenceScannerTest extends TestCase
{
    private function scanner(): FrontendReferenceScanner
    {
        return new FrontendReferenceScanner();
    }

    #[Test]
    public function wayfinder_action_imports_yield_the_controller_fqcn_per_named_method(): void
    {
        $result = $this->scanner()->scan(<<<'TS'
            import { show, update as updatePost } from "@/actions/App/Http/Controllers/PostController";
            TS);

        $this->assertSame([
            ['class' => 'App\Http\Controllers\PostController', 'method' => 'show'],
            ['class' => 'App\Http\Controllers\PostController', 'method' => 'update'],
        ], $result['actions']);
        $this->assertFalse($result['unresolved']);
    }

    #[Test]
    public function a_default_action_import_references_the_whole_controller(): void
    {
        $result = $this->scanner()->scan(<<<'TS'
            import PostController from "@/actions/App/Http/Controllers/PostController";
            TS);

        $this->assertSame([['class' => 'App\Http\Controllers\PostController', 'method' => null]], $result['actions']);
    }

    #[Test]
    public function a_mixed_default_and_named_action_import_yields_both(): void
    {
        $result = $this->scanner()->scan(<<<'TS'
            import PostController, { show } from "../../actions/App/Http/Controllers/PostController";
            TS);

        $this->assertSame([
            ['class' => 'App\Http\Controllers\PostController', 'method' => 'show'],
            ['class' => 'App\Http\Controllers\PostController', 'method' => null],
        ], $result['actions']);
    }

    #[Test]
    public function type_only_imports_still_count_as_references(): void
    {
        $result = $this->scanner()->scan(<<<'TS'
            import type { show } from "@/actions/App/Http/Controllers/PostController";
            import { type store } from "@/actions/App/Http/Controllers/ReviewController";
            TS);

        $this->assertSame([
            ['class' => 'App\Http\Controllers\PostController', 'method' => 'show'],
            ['class' => 'App\Http\Controllers\ReviewController', 'method' => 'store'],
        ], $result['actions']);
    }

    #[Test]
    public function wayfinder_route_imports_join_path_segments_and_leaf_into_a_route_name(): void
    {
        $result = $this->scanner()->scan(<<<'TS'
            import { index, show as showPayment } from "@/routes/clients/payments";
            import { home } from "@/routes";
            TS);

        $this->assertSame(['clients.payments.index', 'clients.payments.show', 'home'], $result['routeNames']);
    }

    #[Test]
    public function a_default_import_of_a_routes_module_is_not_a_reference(): void
    {
        // `routes/` collides with frontend-router conventions (`import routes from './routes'`) —
        // with no leaf name to derive, this must yield nothing rather than guess.
        $result = $this->scanner()->scan(<<<'TS'
            import routes from "./routes";
            import * as allRoutes from "@/routes/clients";
            TS);

        $this->assertSame([], $result['routeNames']);
        $this->assertFalse($result['unresolved']);
    }

    #[Test]
    public function a_single_segment_actions_module_is_not_wayfinder(): void
    {
        $result = $this->scanner()->scan(<<<'TS'
            import { doThing } from "./actions/helpers";
            TS);

        $this->assertSame([], $result['actions']);
    }

    #[Test]
    public function an_extension_suffixed_action_import_still_resolves(): void
    {
        $result = $this->scanner()->scan(<<<'TS'
            import { show } from "@/actions/App/Http/Controllers/PostController.ts";
            TS);

        $this->assertSame([['class' => 'App\Http\Controllers\PostController', 'method' => 'show']], $result['actions']);
    }

    #[Test]
    public function an_extension_suffixed_route_import_still_resolves(): void
    {
        $result = $this->scanner()->scan(<<<'TS'
            import { index } from "@/routes/posts.ts";
            TS);

        $this->assertSame(['posts.index'], $result['routeNames']);
    }

    #[Test]
    public function ziggy_route_calls_yield_literal_route_names(): void
    {
        $result = $this->scanner()->scan(<<<'TS'
            const url = route('posts.show', props.post.id);
            axios.post(this.route("posts.publish"));
            TS);

        $this->assertSame(['posts.show', 'posts.publish'], $result['routeNames']);
        $this->assertFalse($result['unresolved']);
    }

    #[Test]
    public function a_dynamic_route_argument_flips_unresolved(): void
    {
        $result = $this->scanner()->scan(<<<'TS'
            const url = route(`posts.${action}`);
            TS);

        $this->assertSame([], $result['routeNames']);
        $this->assertTrue($result['unresolved']);
    }

    #[Test]
    public function a_concatenated_route_name_marks_the_scan_unresolved(): void
    {
        // `route('posts.' + action)` starts with a quote, so the first-character check alone
        // stays silent — the captured partial name ('posts.') matches nothing and would
        // silently drop, the forbidden falsely-determined result for a dynamic argument.
        $this->assertTrue($this->scanner()->scan("route('posts.' + action);")['unresolved']);
    }

    #[Test]
    public function a_route_call_with_a_options_object_after_the_name_stays_resolved(): void
    {
        $result = $this->scanner()->scan("route('posts.show', { post: 1 });");

        $this->assertSame(['posts.show'], $result['routeNames']);
        $this->assertFalse($result['unresolved']);
    }

    #[Test]
    public function whitespace_before_the_call_parenthesis_still_matches(): void
    {
        // `route ('x')` is valid JS/TS — a missed literal under-selects tests, and a missed
        // dynamic argument would skip the fail-safe entirely.
        $this->assertSame(['posts.show'], $this->scanner()->scan("route ('posts.show');")['routeNames']);
        $this->assertTrue($this->scanner()->scan('route (name);')['unresolved']);
    }

    #[Test]
    public function ziggys_argless_fluent_form_is_not_dynamic(): void
    {
        $result = $this->scanner()->scan(<<<'TS'
            if (route().current('posts.*')) { }
            TS);

        $this->assertSame([], $result['routeNames']);
        $this->assertFalse($result['unresolved']);
    }

    #[Test]
    public function an_unrelated_function_ending_in_route_never_matches(): void
    {
        $result = $this->scanner()->scan(<<<'TS'
            const x = myroute('not.a.route');
            const y = $route('also.not');
            TS);

        $this->assertSame([], $result['routeNames']);
        $this->assertFalse($result['unresolved']);
    }

    #[Test]
    public function a_route_argument_naming_a_same_module_const_string_resolves(): void
    {
        $result = $this->scanner()->scan(<<<'TS'
            const PLAYER_ROUTE = 'posts.player';
            route(PLAYER_ROUTE);
            TS);

        $this->assertSame(['posts.player'], $result['routeNames']);
        $this->assertFalse($result['unresolved']);
    }

    #[Test]
    public function a_route_argument_via_a_flat_const_object_member_resolves(): void
    {
        $result = $this->scanner()->scan(<<<'TS'
            const ROUTES = { player: 'posts.player', list: "posts.index" } as const;
            route(ROUTES.player, { id: 1 });
            TS);

        $this->assertSame(['posts.player'], $result['routeNames']);
        $this->assertFalse($result['unresolved']);
    }

    #[Test]
    public function a_route_argument_via_a_string_enum_member_resolves(): void
    {
        $result = $this->scanner()->scan(<<<'TS'
            enum RouteName { Player = 'posts.player' }
            route(RouteName.Player);
            TS);

        $this->assertSame(['posts.player'], $result['routeNames']);
        $this->assertFalse($result['unresolved']);
    }

    #[Test]
    public function an_undeclared_identifier_still_taints_the_scan(): void
    {
        // No declaration to resolve against, so the argument stays dynamic.
        $this->assertTrue($this->scanner()->scan('route(someName);')['unresolved']);
    }

    #[Test]
    public function a_let_declared_name_does_not_resolve(): void
    {
        // `let`/`var` are reassignable, so the initializer is not the runtime value with
        // certainty — only `const` resolves.
        $result = $this->scanner()->scan(<<<'TS'
            let name = 'posts.player';
            route(name);
            TS);

        $this->assertTrue($result['unresolved']);
    }

    #[Test]
    public function a_redeclared_const_is_ambiguous_and_does_not_resolve(): void
    {
        // "Exactly one declaration" is the rule — two candidates means no certain answer.
        $result = $this->scanner()->scan(<<<'TS'
            const P = 'posts.player';
            const P = 'posts.index';
            route(P);
            TS);

        $this->assertTrue($result['unresolved']);
    }

    #[Test]
    public function a_nested_object_member_does_not_resolve(): void
    {
        // Only flat object/enum bodies are readable without a parser; matching a property inside
        // a nested body would be a guess (it may belong to a different sub-object entirely).
        $result = $this->scanner()->scan(<<<'TS'
            const ROUTES = { posts: { player: 'posts.player' } };
            route(ROUTES.player);
            TS);

        $this->assertTrue($result['unresolved']);
    }

    #[Test]
    public function an_imported_constant_does_not_resolve(): void
    {
        // Same-module only — an imported constant's value is not visible to a regex scanner.
        $result = $this->scanner()->scan(<<<'TS'
            import { ROUTES } from './routes-config';
            route(ROUTES.player);
            TS);

        $this->assertTrue($result['unresolved']);
    }

    #[Test]
    public function a_resolved_reference_beside_an_unresolvable_one_keeps_the_file_tainted(): void
    {
        // The contract test: a resolvable reference still contributes its name, but the
        // file-level fail-safe stays on as long as any dynamic argument remains unresolvable.
        $result = $this->scanner()->scan(<<<'TS'
            const PLAYER_ROUTE = 'posts.player';
            route(PLAYER_ROUTE);
            route(other);
            TS);

        $this->assertSame(['posts.player'], $result['routeNames']);
        $this->assertTrue($result['unresolved']);
    }

    #[Test]
    public function duplicate_references_deduplicate(): void
    {
        $result = $this->scanner()->scan(<<<'TS'
            import { show } from "@/actions/App/Http/Controllers/PostController";
            import { show as again } from "@/actions/App/Http/Controllers/PostController";
            route('posts.show'); route('posts.show');
            TS);

        $this->assertCount(1, $result['actions']);
        $this->assertSame(['posts.show'], $result['routeNames']);
    }

    #[Test]
    public function a_source_without_references_is_a_determined_empty_answer(): void
    {
        $result = $this->scanner()->scan(<<<'TS'
            import { ref } from "vue";
            const count = ref(0);
            TS);

        $this->assertSame(['actions' => [], 'routeNames' => [], 'uris' => [], 'unresolved' => false], $result);
    }

    #[Test]
    public function root_relative_string_literals_are_uri_candidates_with_query_and_fragment_stripped(): void
    {
        $result = $this->scanner()->scan(<<<'TS'
            axios.get('/api/posts?page=2');
            fetch("/posts/123#stats");
            TS);

        $this->assertSame([
            ['uri' => '/api/posts', 'method' => 'get'],
            ['uri' => '/posts/123', 'method' => null],
        ], $result['uris']);
    }

    #[Test]
    public function a_verb_named_call_pins_the_http_method_regardless_of_the_verbs_case(): void
    {
        // The verb may sit on the callee's `.method` segment (`axios.post`) or on the callee
        // itself (a bare, allowlisted verb name) — either way, case never blocks the pin.
        $result = $this->scanner()->scan(<<<'TS'
            axios.post('/posts');
            $http.PUT("/posts/9");
            TS);

        $this->assertSame([
            ['uri' => '/posts', 'method' => 'post'],
            ['uri' => '/posts/9', 'method' => 'put'],
        ], $result['uris']);
    }

    #[Test]
    public function a_non_allowlisted_wrapper_callee_is_not_a_candidate_until_registered(): void
    {
        // plan 038: candidacy now gates on the callee, not just call-argument position — a
        // generic HTTP wrapper (`api.PUT`, bare `load`) that used to seed unpinned no longer
        // does, until the project registers it via `richter.frontend.http_callees`.
        $result = $this->scanner()->scan(<<<'TS'
            api.PUT('/posts/9');
            load('/posts/7');
            TS);

        $this->assertSame([], $result['uris']);
    }

    #[Test]
    public function a_string_literal_outside_call_argument_position_is_not_a_uri_candidate(): void
    {
        // Assignments, object-property values and array heads are data/navigation, not endpoint
        // calls — this is the false-positive flood a large real-world consumer demonstrated: a
        // constants file or nav-link config whose strings happen to match real route templates.
        $result = $this->scanner()->scan(<<<'TS'
            const API = '/api/posts';
            const NAV = [{ href: '/posts' }];
            export default { uri: "/posts/9" };
            TS);

        $this->assertSame([], $result['uris']);
    }

    #[Test]
    public function the_request_method_url_second_argument_idiom_is_a_documented_recall_loss(): void
    {
        // Callee gating means candidacy can only be checked at the callee immediately before
        // `(` — a literal in a later argument position (the `request(method, url)` idiom) can no
        // longer be tied to that callee via a bare `,` anchor, so it is dropped rather than
        // risking the same anchor matching an unrelated, non-allowlisted call's argument.
        $this->assertSame([], $this->scanner()->scan("request('GET', '/posts');")['uris']);
    }

    #[Test]
    public function a_template_literal_outside_call_argument_position_is_not_a_candidate(): void
    {
        // The called form (`fetch(`/posts/${id}`)`) is already pinned above; only the uncalled
        // assignment is excluded here.
        $result = $this->scanner()->scan(<<<'TS'
            const t = `/posts/${id}`;
            TS);

        $this->assertSame([], $result['uris']);
    }

    #[Test]
    public function an_options_object_url_property_is_a_documented_recall_loss(): void
    {
        // Deliberately traded away: an options object's `url` property is indistinguishable from
        // any other property value at the regex level.
        $result = $this->scanner()->scan("axios({ url: '/posts', method: 'post' });");

        $this->assertSame([], $result['uris']);
    }

    #[Test]
    public function non_root_relative_strings_are_not_uri_candidates(): void
    {
        $result = $this->scanner()->scan(<<<'TS'
            import { show } from "@/actions/App/Http/Controllers/PostController";
            const url = "https://example.com/path";
            const name = 'posts.show';
            TS);

        $this->assertSame([], $result['uris']);
    }

    #[Test]
    public function a_template_literal_endpoint_wildcards_its_interpolations(): void
    {
        $result = $this->scanner()->scan(<<<'TS'
            fetch(`/posts/${id}`);
            axios.post(`/posts/${id}/reviews?draft=${draft}`);
            TS);

        $this->assertSame([
            ['uri' => '/posts/*', 'method' => null],
            ['uri' => '/posts/*/reviews', 'method' => 'post'],
        ], $result['uris']);
    }

    #[Test]
    public function a_template_literal_with_whitespace_is_markup_not_an_endpoint(): void
    {
        $result = $this->scanner()->scan(<<<'TS'
            const html = `/posts <b>${title}</b>`;
            const rooted = `${base}/posts`;
            TS);

        $this->assertSame([], $result['uris']);
    }

    #[Test]
    public function duplicate_uri_literals_deduplicate_per_method(): void
    {
        $result = $this->scanner()->scan("fetch('/posts'); window.fetch('/posts'); axios.post('/posts');");

        // The two unpinned references (bare and via `window`) collapse; the verb-pinned one is a
        // distinct reference.
        $this->assertSame([
            ['uri' => '/posts', 'method' => null],
            ['uri' => '/posts', 'method' => 'post'],
        ], $result['uris']);
    }

    #[Test]
    public function non_http_callees_yield_no_uri_candidates(): void
    {
        // The three false-positive shapes a consumer reported: an i18n helper, a project-custom
        // helper wrapping an unrelated concern, and a logging call — none of these are HTTP/route
        // callees, so none should seed a route.
        $result = $this->scanner()->scan(<<<'TS'
            translate('/preferences');
            someHelper('/api/v2/reports', 'other');
            console.log('/{post} opened');
            TS);

        $this->assertSame([], $result['uris']);
    }

    #[Test]
    public function real_http_and_route_callees_still_seed_with_the_method_pinned_where_derivable(): void
    {
        $result = $this->scanner()->scan(<<<'TS'
            fetch('/posts');
            axios.get('/api/reports');
            route('posts.show', { post });
            useFetch(`/posts/${id}`);
            TS);

        $this->assertSame([
            ['uri' => '/posts', 'method' => null],
            ['uri' => '/api/reports', 'method' => 'get'],
            ['uri' => '/posts/*', 'method' => null],
        ], $result['uris']);
        // `route('posts.show', ...)` is the pre-existing, callee-specific Ziggy branch (out of
        // scope for this gate) — its argument is a route name, not root-relative, so it never
        // reaches uriCandidates() at all.
        $this->assertSame(['posts.show'], $result['routeNames']);
    }

    #[Test]
    public function playwright_and_cypress_navigation_callees_seed_by_default(): void
    {
        // FrontendTestIndex advertises Playwright/Cypress spec support, and `page.goto(...)` /
        // `cy.visit(...)` are how those specs reference routes — both are built-in, not
        // project-custom, so they seed without any `http_callees` configuration.
        $result = $this->scanner()->scan(<<<'TS'
            await page.goto('/posts/7');
            cy.visit('/posts');
            TS);

        $this->assertSame([
            ['uri' => '/posts/7', 'method' => null],
            ['uri' => '/posts', 'method' => null],
        ], $result['uris']);
    }

    #[Test]
    public function a_configured_http_callee_extends_the_allowlist(): void
    {
        $scanner = new FrontendReferenceScanner(['myHttpClient']);

        $this->assertSame(
            [['uri' => '/posts', 'method' => 'post']],
            $scanner->scan("myHttpClient.post('/posts');")['uris'],
        );

        // The same call is not a candidate without the configured extension.
        $this->assertSame([], $this->scanner()->scan("myHttpClient.post('/posts');")['uris']);
    }

    #[Test]
    public function object_literal_and_non_call_position_strings_stay_excluded_under_gating(): void
    {
        // plan 019/031 behaviour holds: assignments, object-property values and array heads are
        // data/navigation, not endpoint calls, regardless of callee gating.
        $result = $this->scanner()->scan(<<<'TS'
            const API = '/posts';
            const NAV = [{ href: '/posts' }];
            export default { uri: "/posts/9" };
            TS);

        $this->assertSame([], $result['uris']);
    }
}
