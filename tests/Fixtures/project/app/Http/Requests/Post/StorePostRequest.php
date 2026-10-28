<?php declare(strict_types=1);

namespace App\Http\Requests\Post;

use Illuminate\Foundation\Http\FormRequest;

final class StorePostRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => 'required|string|max:255',
            'subtitle' => 'present|nullable|string',
            'body' => 'nullable|string',
        ];
    }
}
