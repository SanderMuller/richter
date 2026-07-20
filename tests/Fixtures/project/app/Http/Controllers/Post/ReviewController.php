<?php declare(strict_types=1);

namespace App\Http\Controllers\Post;

use App\Http\Resources\Api\v2\Post\ReviewResource;
use App\Jobs\ProcessPostJob;
use App\Models\Post;
use App\Policies\PostPolicy;
use Illuminate\Contracts\View\View;

final class ReviewController
{
    public function show(Post $post): ReviewResource
    {
        $post->load([Post::REVIEWS]);

        dispatch(new ProcessPostJob($post));

        return ReviewResource::make($post);
    }

    public function edit(Post $post): View
    {
        request()->user()?->can(PostPolicy::UPDATE, $post);

        return view('posts.show', ['post' => $post]);
    }
}
