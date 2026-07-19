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
            import { show, update as updateVideo } from "@/actions/App/Http/Controllers/VideoController";
            TS);

        $this->assertSame([
            ['class' => 'App\Http\Controllers\VideoController', 'method' => 'show'],
            ['class' => 'App\Http\Controllers\VideoController', 'method' => 'update'],
        ], $result['actions']);
        $this->assertFalse($result['unresolved']);
    }

    #[Test]
    public function a_default_action_import_references_the_whole_controller(): void
    {
        $result = $this->scanner()->scan(<<<'TS'
            import VideoController from "@/actions/App/Http/Controllers/VideoController";
            TS);

        $this->assertSame([['class' => 'App\Http\Controllers\VideoController', 'method' => null]], $result['actions']);
    }

    #[Test]
    public function a_mixed_default_and_named_action_import_yields_both(): void
    {
        $result = $this->scanner()->scan(<<<'TS'
            import VideoController, { show } from "../../actions/App/Http/Controllers/VideoController";
            TS);

        $this->assertSame([
            ['class' => 'App\Http\Controllers\VideoController', 'method' => 'show'],
            ['class' => 'App\Http\Controllers\VideoController', 'method' => null],
        ], $result['actions']);
    }

    #[Test]
    public function type_only_imports_still_count_as_references(): void
    {
        $result = $this->scanner()->scan(<<<'TS'
            import type { show } from "@/actions/App/Http/Controllers/VideoController";
            import { type store } from "@/actions/App/Http/Controllers/QuestionController";
            TS);

        $this->assertSame([
            ['class' => 'App\Http\Controllers\VideoController', 'method' => 'show'],
            ['class' => 'App\Http\Controllers\QuestionController', 'method' => 'store'],
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
    public function ziggy_route_calls_yield_literal_route_names(): void
    {
        $result = $this->scanner()->scan(<<<'TS'
            const url = route('videos.show', props.video.id);
            axios.post(this.route("videos.publish"));
            TS);

        $this->assertSame(['videos.show', 'videos.publish'], $result['routeNames']);
        $this->assertFalse($result['unresolved']);
    }

    #[Test]
    public function a_dynamic_route_argument_flips_unresolved(): void
    {
        $result = $this->scanner()->scan(<<<'TS'
            const url = route(`videos.${action}`);
            TS);

        $this->assertSame([], $result['routeNames']);
        $this->assertTrue($result['unresolved']);
    }

    #[Test]
    public function a_variable_route_argument_flips_unresolved(): void
    {
        $this->assertTrue($this->scanner()->scan('const url = route(name);')['unresolved']);
    }

    #[Test]
    public function whitespace_before_the_call_parenthesis_still_matches(): void
    {
        // `route ('x')` is valid JS/TS — a missed literal under-selects tests, and a missed
        // dynamic argument would skip the fail-safe entirely.
        $this->assertSame(['videos.show'], $this->scanner()->scan("route ('videos.show');")['routeNames']);
        $this->assertTrue($this->scanner()->scan('route (name);')['unresolved']);
    }

    #[Test]
    public function ziggys_argless_fluent_form_is_not_dynamic(): void
    {
        $result = $this->scanner()->scan(<<<'TS'
            if (route().current('videos.*')) { }
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
    public function duplicate_references_deduplicate(): void
    {
        $result = $this->scanner()->scan(<<<'TS'
            import { show } from "@/actions/App/Http/Controllers/VideoController";
            import { show as again } from "@/actions/App/Http/Controllers/VideoController";
            route('videos.show'); route('videos.show');
            TS);

        $this->assertCount(1, $result['actions']);
        $this->assertSame(['videos.show'], $result['routeNames']);
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
            axios.get('/api/videos?page=2');
            fetch("/videos/123#stats");
            TS);

        $this->assertSame([
            ['uri' => '/api/videos', 'method' => 'get'],
            ['uri' => '/videos/123', 'method' => null],
        ], $result['uris']);
    }

    #[Test]
    public function a_verb_named_call_pins_the_http_method_and_wrappers_stay_unpinned(): void
    {
        $result = $this->scanner()->scan(<<<'TS'
            axios.post('/videos');
            api.PUT("/videos/9");
            load('/videos/7');
            TS);

        $this->assertSame([
            ['uri' => '/videos', 'method' => 'post'],
            ['uri' => '/videos/9', 'method' => 'put'],
            ['uri' => '/videos/7', 'method' => null],
        ], $result['uris']);
    }

    #[Test]
    public function non_root_relative_strings_are_not_uri_candidates(): void
    {
        $result = $this->scanner()->scan(<<<'TS'
            import { show } from "@/actions/App/Http/Controllers/VideoController";
            const url = "https://example.com/path";
            const name = 'videos.show';
            TS);

        $this->assertSame([], $result['uris']);
    }

    #[Test]
    public function a_template_literal_endpoint_wildcards_its_interpolations(): void
    {
        $result = $this->scanner()->scan(<<<'TS'
            fetch(`/videos/${id}`);
            axios.post(`/videos/${id}/questions?draft=${draft}`);
            TS);

        $this->assertSame([
            ['uri' => '/videos/*', 'method' => null],
            ['uri' => '/videos/*/questions', 'method' => 'post'],
        ], $result['uris']);
    }

    #[Test]
    public function a_template_literal_with_whitespace_is_markup_not_an_endpoint(): void
    {
        $result = $this->scanner()->scan(<<<'TS'
            const html = `/videos <b>${title}</b>`;
            const rooted = `${base}/videos`;
            TS);

        $this->assertSame([], $result['uris']);
    }

    #[Test]
    public function duplicate_uri_literals_deduplicate_per_method(): void
    {
        $result = $this->scanner()->scan("fetch('/videos'); request('/videos'); post('/videos');");

        // The two unpinned references collapse; the verb-pinned one is a distinct reference.
        $this->assertSame([
            ['uri' => '/videos', 'method' => null],
            ['uri' => '/videos', 'method' => 'post'],
        ], $result['uris']);
    }
}
