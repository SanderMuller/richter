<?php declare(strict_types=1);

namespace App\Http\Controllers\Post;

use App\Http\Requests\Post\StorePostRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PostController
{
    public function store(StorePostRequest $request): JsonResponse
    {
        return new JsonResponse($request->validated(), 201);
    }

    public function update(Request $request): JsonResponse
    {
        return new JsonResponse($request->validate([
            'title' => 'required|string',
            'excerpt' => 'sometimes|required|string',
        ]));
    }
}
