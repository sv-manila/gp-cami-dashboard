<?php

use App\Http\Controllers\DashboardController;
use App\Http\Middleware\DevOnly;
use Illuminate\Support\Facades\Route;

Route::middleware(DevOnly::class)->group(function () {
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/profile/{identity}', [DashboardController::class, 'show'])->name('profile.show');
    Route::view('/features', 'welcome')->name('features');
});
