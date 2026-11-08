<?php

use App\Http\Controllers\DisasterDashboardController;
use Illuminate\Support\Facades\Route;

Route::get('/', [DisasterDashboardController::class, 'home'])->name('home');
Route::get('/overview', [DisasterDashboardController::class, 'overview'])->name('disaster.overview');
Route::get('/map', [DisasterDashboardController::class, 'map'])->name('disaster.map');
Route::get('/events', [DisasterDashboardController::class, 'events'])->name('disaster.events');
Route::get('/weather', [DisasterDashboardController::class, 'weather'])->name('disaster.weather');
Route::get('/freshness', [DisasterDashboardController::class, 'freshness'])->name('disaster.freshness');
Route::get('/api/disaster-dashboard', [DisasterDashboardController::class, 'api'])->name('api.disaster.dashboard');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';

