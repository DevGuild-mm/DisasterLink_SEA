<?php

use App\Http\Controllers\Admin\AdminDashboardController;
use App\Http\Controllers\Admin\DataSourceController;
use App\Http\Controllers\Admin\DisasterReportAdminController;
use App\Http\Controllers\Admin\EmergencyContactAdminController;
use App\Http\Controllers\Admin\EmergencyCoordinationAdminController;
use App\Http\Controllers\Admin\SafetyGuideAdminController;
use App\Http\Controllers\DisasterDashboardController;
use App\Http\Controllers\DisasterIntelligenceController;
use App\Http\Controllers\DisasterReportController;
use App\Http\Controllers\EmergencyCoordinationController;
use App\Http\Controllers\PreparednessController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', [DisasterDashboardController::class, 'home'])->name('home');
Route::get('/overview', [DisasterDashboardController::class, 'overview'])->name('disaster.overview');
Route::get('/map', [DisasterDashboardController::class, 'map'])->name('disaster.map');
Route::get('/events', [DisasterDashboardController::class, 'events'])->name('disaster.events');
Route::get('/events/{id}', [DisasterDashboardController::class, 'eventDetail'])->name('disaster.event.detail');
Route::get('/countries/{country?}', [DisasterDashboardController::class, 'countries'])->name('disaster.countries')->middleware('module:countries');
Route::get('/intelligence', [DisasterIntelligenceController::class, 'index'])->name('disaster.intelligence')->middleware('module:intelligence');
Route::get('/coordination', [EmergencyCoordinationController::class, 'index'])->name('coordination.index')->middleware('module:coordination');
Route::get('/preparedness', [PreparednessController::class, 'index'])->name('preparedness.index');
// The two old public pages moved into /preparedness (permanent redirects; alert emails already sent link to them).
Route::get('/safety-guides', [PreparednessController::class, 'legacyGuides']);
Route::get('/emergency-contacts', [PreparednessController::class, 'legacyContacts']);
Route::get('/weather', [DisasterDashboardController::class, 'weather'])->name('disaster.weather');
Route::get('/api/disaster-dashboard', [DisasterDashboardController::class, 'api'])->name('api.disaster.dashboard')->middleware('throttle:60,1');
Route::get('/api/countries/{country?}', [DisasterDashboardController::class, 'apiCountries'])->name('api.disaster.countries')->middleware(['module:countries', 'throttle:60,1']);
Route::get('/api/intelligence', [DisasterIntelligenceController::class, 'api'])->name('api.disaster.intelligence')->middleware(['module:intelligence', 'throttle:60,1']);
Route::get('/api/coordination', [EmergencyCoordinationController::class, 'api'])->name('api.coordination')->middleware(['module:coordination', 'throttle:60,1']);
Route::get('/api/shelters', [EmergencyCoordinationController::class, 'apiShelters'])->name('api.shelters')->middleware(['module:coordination', 'throttle:60,1']);
Route::post('/volunteers/register', [EmergencyCoordinationController::class, 'registerVolunteer'])->name('volunteers.register')->middleware(['module:coordination', 'throttle:15,1']);
Route::post('/rescue-requests', [EmergencyCoordinationController::class, 'storeRescueRequest'])->name('rescue.store')->middleware(['module:coordination', 'throttle:15,1']);

Route::middleware(['module:reports', 'auth'])->group(function () {
    Route::get('/reports/create', [DisasterReportController::class, 'create'])->name('reports.create');
    Route::post('/reports', [DisasterReportController::class, 'store'])->name('reports.store')->middleware('throttle:15,1');
    Route::get('/my-reports', [DisasterReportController::class, 'myReports'])->name('reports.my');
    Route::delete('/my-reports/{report}', [DisasterReportController::class, 'withdraw'])->name('reports.withdraw');
});

Route::middleware(['auth', 'verified', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [AdminDashboardController::class, 'index'])->name('dashboard');

    Route::post('/safety-guides', [SafetyGuideAdminController::class, 'store'])->name('safety-guides.store');
    Route::put('/safety-guides/{guide}', [SafetyGuideAdminController::class, 'update'])->name('safety-guides.update');
    Route::delete('/safety-guides/{guide}', [SafetyGuideAdminController::class, 'destroy'])->name('safety-guides.destroy');
    Route::post('/emergency-contacts', [EmergencyContactAdminController::class, 'store'])->name('emergency-contacts.store');
    Route::put('/emergency-contacts/{contact}', [EmergencyContactAdminController::class, 'update'])->name('emergency-contacts.update');
    Route::delete('/emergency-contacts/{contact}', [EmergencyContactAdminController::class, 'destroy'])->name('emergency-contacts.destroy');

    Route::get('/data-sources', [DataSourceController::class, 'index'])->name('data-sources.index');
    Route::post('/data-sources/refresh', [DataSourceController::class, 'refresh'])->name('data-sources.refresh')->middleware('throttle:3,1');

    Route::middleware('module:reports')->group(function () {
        Route::get('/reports', [DisasterReportAdminController::class, 'index'])->name('reports.index');
        Route::patch('/reports/{report}/status', [DisasterReportAdminController::class, 'updateStatus'])->name('reports.update_status');
    });

    Route::middleware('module:coordination')->group(function () {
        Route::post('/shelters', [EmergencyCoordinationAdminController::class, 'storeShelter'])->name('shelters.store');
        Route::put('/shelters/{shelter}', [EmergencyCoordinationAdminController::class, 'updateShelter'])->name('shelters.update');
        Route::post('/resources', [EmergencyCoordinationAdminController::class, 'storeResource'])->name('resources.store');
        Route::post('/resources/{resource}/distribute', [EmergencyCoordinationAdminController::class, 'distributeResource'])->name('resources.distribute');
        Route::patch('/rescue-requests/{rescueRequest}/status', [EmergencyCoordinationAdminController::class, 'updateRescueStatus'])->name('rescue.update_status');
        Route::patch('/volunteers/{volunteer}/status', [EmergencyCoordinationAdminController::class, 'updateVolunteerStatus'])->name('volunteers.update_status');
    });
});

Route::middleware(['auth', 'verified'])->group(function () {
    // The starter dashboard page is gone; old links land on the Welcome page (admins on /admin).
    Route::get('dashboard', fn (Request $request) => redirect($request->user()->isAdmin() ? '/admin' : '/'))->name('dashboard');
});

require __DIR__.'/settings.php';
