<?php

use Illuminate\Support\Facades\Route;
use LaravelModularDDD\Http\Controllers\HealthController;
use LaravelModularDDD\Http\Controllers\MetricsController;

/*
|--------------------------------------------------------------------------
| Health Check Routes
|--------------------------------------------------------------------------
|
| These routes provide health check endpoints for monitoring system health
| in production environments. Each endpoint checks specific components.
|
*/

Route::prefix('health')->name('health.')->group(function () {
    // Overall system health
    Route::get('/', [HealthController::class, 'index'])->name('index');

    // Component-specific health checks
    Route::get('/database', [HealthController::class, 'database'])->name('database');
    Route::get('/event-store', [HealthController::class, 'eventStore'])->name('event-store');
    Route::get('/snapshots', [HealthController::class, 'snapshots'])->name('snapshots');
    Route::get('/projections', [HealthController::class, 'projections'])->name('projections');
    Route::get('/cache', [HealthController::class, 'cache'])->name('cache');
    Route::get('/queues', [HealthController::class, 'queues'])->name('queues');
    Route::get('/transactions', [HealthController::class, 'transactions'])->name('transactions');
    Route::get('/modules', [HealthController::class, 'modules'])->name('modules');

    // System metrics
    Route::get('/metrics', [HealthController::class, 'metrics'])->name('metrics');
});

/*
|--------------------------------------------------------------------------
| Performance Metrics Routes
|--------------------------------------------------------------------------
|
| Detailed performance metrics and monitoring endpoints for operational
| insights and alerting systems.
|
*/

Route::prefix('metrics')->name('metrics.')->group(function () {
    // Comprehensive metrics
    Route::get('/', [MetricsController::class, 'index'])->name('index');
    Route::get('/insights', [MetricsController::class, 'insights'])->name('insights');
    Route::get('/prometheus', [MetricsController::class, 'prometheus'])->name('prometheus');
    Route::get('/realtime', [MetricsController::class, 'realtime'])->name('realtime');

    // Specific metric types
    Route::get('/{type}', [MetricsController::class, 'show'])->name('show');

    // Maintenance
    Route::delete('/cleanup', [MetricsController::class, 'cleanup'])->name('cleanup');
});

/*
|--------------------------------------------------------------------------
| Legacy Health Check Routes (for load balancers)
|--------------------------------------------------------------------------
|
| Simple health check endpoints that many load balancers and monitoring
| tools expect at standard paths.
|
*/

// Simple health check endpoint for load balancers
Route::get('/health', [HealthController::class, 'index']);
Route::get('/healthz', [HealthController::class, 'index']); // Kubernetes style
Route::get('/status', [HealthController::class, 'index']);
Route::get('/ping', [HealthController::class, 'index']);

// Readiness and liveness probes for Kubernetes
Route::get('/ready', [HealthController::class, 'index']);
Route::get('/live', [HealthController::class, 'index']);