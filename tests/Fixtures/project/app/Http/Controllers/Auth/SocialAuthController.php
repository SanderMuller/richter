<?php declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use Illuminate\Http\RedirectResponse;

final class SocialAuthController
{
    public function login(): RedirectResponse
    {
        return redirect('/');
    }
}
