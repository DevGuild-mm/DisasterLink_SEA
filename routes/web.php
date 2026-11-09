<?php

use App\Http\Controllers\Admin\DisasterReportAdminController;
use App\Http\Controllers\Admin\EmergencyCoordinationAdminController;
use App\Http\Controllers\DisasterDashboardController;
use App\Http\Controllers\DisasterIntelligenceController;
use App\Http\Controllers\DisasterReportController;
use App\Http\Controllers\EmergencyCoordinationController;
use Illuminate\Support\Facades\Route;

Route::get('/', [DisasterDashboardController::class, 'home'])->name('home');
Route::get('/overview', [DisasterDashboardController::class, 'overview'])->name('disaster.overview');
Route::get('/map', [DisasterDashboardController::class, 'map'])->name('disaster.map');
Route::get('/events', [DisasterDashboardController::class, 'events'])->name('disaster.events');
Route::get('/events/{id}', [DisasterDashboardController::class, 'eventDetail'])->name('disaster.event.detail');
Route::get('/countries/{country?}', [DisasterDashboardController::class, 'countries'])->name('disaster.countries');
Route::get('/intelligence', [DisasterIntelligenceController::class, 'index'])->name('disaster.intelligence');
Route::get('/coordination', [EmergencyCoordinationController::class, 'index'])->name('coordination.index');
Route::get('/weather', [DisasterDashboardController::class, 'weather'])->name('disaster.weather');
Route::get('/freshness', [DisasterDashboardController::class, 'freshness'])->name('disaster.freshness');
Route::get('/api/disaster-dashboard', [DisasterDashboardController::class, 'api'])->name('api.disaster.dashboard')->middleware('throttle:60,1');
Route::get('/api/countries/{country?}', [DisasterDashboardController::class, 'apiCountries'])->name('api.disaster.countries')->middleware('throttle:60,1');
Route::get('/api/intelligence', [DisasterIntelligenceController::class, 'api'])->name('api.disaster.intelligence')->middleware('throttle:60,1');
Route::get('/api/coordination', [EmergencyCoordinationController::class, 'api'])->name('api.coordination')->middleware('throttle:60,1');
Route::get('/api/shelters', [EmergencyCoordinationController::class, 'apiShelters'])->name('api.shelters')->middleware('throttle:60,1');
Route::post('/volunteers/register', [EmergencyCoordinationController::class, 'registerVolunteer'])->name('volunteers.register')->middleware('throttle:15,1');
Route::post('/rescue-requests', [EmergencyCoordinationController::class, 'storeRescueRequest'])->name('rescue.store')->middleware('throttle:15,1');

Route::middleware(['auth'])->group(function () {
    Route::get('/reports/create', [DisasterReportController::class, 'create'])->name('reports.create');
    Route::post('/reports', [DisasterReportController::class, 'store'])->name('reports.store')->middleware('throttle:15,1');
    Route::get('/my-reports', [DisasterReportController::class, 'myReports'])->name('reports.my');
    Route::delete('/my-reports/{report}', [DisasterReportController::class, 'withdraw'])->name('reports.withdraw');
});

Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/reports', [DisasterReportAdminController::class, 'index'])->name('reports.index');
    Route::patch('/reports/{report}/status', [DisasterReportAdminController::class, 'updateStatus'])->name('reports.update_status');
    Route::post('/shelters', [EmergencyCoordinationAdminController::class, 'storeShelter'])->name('shelters.store');
    Route::put('/shelters/{shelter}', [EmergencyCoordinationAdminController::class, 'updateShelter'])->name('shelters.update');
    Route::post('/resources', [EmergencyCoordinationAdminController::class, 'storeResource'])->name('resources.store');
    Route::post('/resources/{resource}/distribute', [EmergencyCoordinationAdminController::class, 'distributeResource'])->name('resources.distribute');
    Route::patch('/rescue-requests/{rescueRequest}/status', [EmergencyCoordinationAdminController::class, 'updateRescueStatus'])->name('rescue.update_status');
    Route::patch('/volunteers/{volunteer}/status', [EmergencyCoordinationAdminController::class, 'updateVolunteerStatus'])->name('volunteers.update_status');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';

