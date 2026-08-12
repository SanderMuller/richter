<?php declare(strict_types=1);

use App\Http\Controllers\Post\DashboardSearchController;
use App\Http\Controllers\Post\ReviewController;
use Illuminate\Support\Facades\Route;

Route::get('/posts/{post}/reviews', [ReviewController::class, 'show'])->name('posts.reviews.show');
Route::get('/posts/{post}/edit', [ReviewController::class, 'edit'])->middleware('auth')->name('posts.edit');
Route::get('/dashboard/search', [DashboardSearchController::class, 'index'])->name('dashboard.search');
Route::get('/posts/{post}/interactive', static fn () => 'interactive')->middleware('features:interactive-post')->name('posts.interactive');

// Legacy string action, namespaced relative to a root the provider adds — the action reaches the
// graph partially qualified, and only the suffix rewrite lands it on the controller that exists.
Route::namespace('Auth')->group(static function (): void {
    Route::get('/auth/login', 'SocialAuthController@login')->name('auth.login');
});
