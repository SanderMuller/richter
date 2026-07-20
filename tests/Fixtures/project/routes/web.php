<?php declare(strict_types=1);

use App\Http\Controllers\Post\DashboardSearchController;
use App\Http\Controllers\Post\ReviewController;
use Illuminate\Support\Facades\Route;

Route::get('/posts/{post}/reviews', [ReviewController::class, 'show'])->name('posts.reviews.show');
Route::get('/posts/{post}/edit', [ReviewController::class, 'edit'])->middleware('auth')->name('posts.edit');
Route::get('/dashboard/search', [DashboardSearchController::class, 'index'])->name('dashboard.search');
Route::get('/posts/{post}/interactive', static fn () => 'interactive')->middleware('features:interactive-post')->name('posts.interactive');
