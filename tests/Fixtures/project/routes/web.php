<?php declare(strict_types=1);

use App\Http\Controllers\Video\DashboardSearchController;
use App\Http\Controllers\Video\QuestionController;
use Illuminate\Support\Facades\Route;

Route::get('/videos/{video}/questions', [QuestionController::class, 'show'])->name('videos.questions.show');
Route::get('/videos/{video}/edit', [QuestionController::class, 'edit'])->middleware('auth')->name('videos.edit');
Route::get('/dashboard/search', [DashboardSearchController::class, 'index'])->name('dashboard.search');
Route::get('/videos/{video}/interactive', static fn () => 'interactive')->middleware('features:interactive-video')->name('videos.interactive');
