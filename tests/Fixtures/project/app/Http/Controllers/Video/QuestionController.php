<?php declare(strict_types=1);

namespace App\Http\Controllers\Video;

use App\Http\Resources\Api\v2\Video\QuestionResource;
use App\Jobs\ProcessVideoJob;
use App\Models\Video;
use App\Policies\VideoPolicy;
use Illuminate\Contracts\View\View;

final class QuestionController
{
    public function show(Video $video): QuestionResource
    {
        $video->load([Video::QUESTIONS]);

        dispatch(new ProcessVideoJob($video));

        return QuestionResource::make($video);
    }

    public function edit(Video $video): View
    {
        request()->user()?->can(VideoPolicy::UPDATE, $video);

        return view('videos.show', ['video' => $video]);
    }
}
