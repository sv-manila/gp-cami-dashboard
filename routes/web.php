<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\ApiPlaygroundController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PipelineController;
use App\Http\Controllers\QualityController;
use App\Http\Controllers\ReviewController;
use App\Http\Middleware\DevOnly;
use Illuminate\Support\Facades\Route;

Route::middleware(DevOnly::class)->group(function () {
    // Stats board + smart search.
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/export/{format}', [DashboardController::class, 'export'])
        ->whereIn('format', ['csv', 'json'])->name('search.export');

    Route::get('/profile/{identity}', [DashboardController::class, 'show'])->name('profile.show');
    Route::get('/stats/exact/{table}', [DashboardController::class, 'exactCount'])->name('stats.exact');
    Route::get('/match/{kind}/{id}', [DashboardController::class, 'matchJson'])
        ->whereIn('kind', ['credential', 'exclusion'])->whereNumber('id')->name('match.json');

    // Steward tools.
    Route::get('/review', [ReviewController::class, 'index'])->name('review');
    Route::get('/compare', [ReviewController::class, 'compare'])->name('review.compare');
    Route::get('/quality', [QualityController::class, 'index'])->name('quality');

    // Operations.
    Route::get('/pipeline', [PipelineController::class, 'index'])->name('pipeline');
    Route::get('/accounts', [AccountController::class, 'index'])->name('accounts');
    Route::get('/accounts/{account}', [AccountController::class, 'show'])
        ->whereNumber('account')->name('accounts.show');

    // Docs + executable API examples.
    Route::view('/features', 'welcome')->name('features');
    Route::post('/api-try', [ApiPlaygroundController::class, 'proxy'])->name('api.try');
});
