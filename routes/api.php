<?php

use App\Http\Controllers\Api\V1\AuthSessionController;
use App\Http\Controllers\Api\V1\ConnectorProviderController;
use App\Http\Controllers\Api\V1\DataSourceController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\MockConnectorController;
use App\Http\Controllers\Api\V1\SiteController;
use App\Http\Controllers\Api\V1\TenantController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', HealthController::class)->name('api.v1.health');

    Route::get('/auth/csrf-cookie', [AuthSessionController::class, 'csrfCookie'])
        ->name('api.v1.auth.csrf-cookie');

    Route::middleware('throttle:login')->group(function (): void {
        Route::post('/auth/login', [AuthSessionController::class, 'store'])->name('api.v1.auth.login');
    });

    Route::middleware('auth')->group(function (): void {
        Route::post('/auth/logout', [AuthSessionController::class, 'destroy'])->name('api.v1.auth.logout');
        Route::get('/auth/me', [AuthSessionController::class, 'me'])->name('api.v1.auth.me');

        Route::get('/tenants', [TenantController::class, 'index'])->name('api.v1.tenants.index');
        Route::post('/tenants', [TenantController::class, 'store'])
            ->middleware('throttle:api-writes')
            ->name('api.v1.tenants.store');
        Route::get('/tenants/{id}', [TenantController::class, 'show'])
            ->whereNumber('id')
            ->name('api.v1.tenants.show');

        Route::get('/sites', [SiteController::class, 'index'])->name('api.v1.sites.index');
        Route::post('/sites', [SiteController::class, 'store'])
            ->middleware('throttle:api-writes')
            ->name('api.v1.sites.store');
        Route::get('/sites/{id}', [SiteController::class, 'show'])
            ->whereNumber('id')
            ->name('api.v1.sites.show');
        Route::patch('/sites/{id}', [SiteController::class, 'update'])
            ->middleware('throttle:api-writes')
            ->whereNumber('id')
            ->name('api.v1.sites.update');
        Route::delete('/sites/{id}', [SiteController::class, 'destroy'])
            ->middleware('throttle:api-writes')
            ->whereNumber('id')
            ->name('api.v1.sites.destroy');

        Route::get('/mock-connectors', [MockConnectorController::class, 'index'])
            ->name('api.v1.mock-connectors.index');
        Route::get('/mock-connectors/{key}', [MockConnectorController::class, 'show'])
            ->name('api.v1.mock-connectors.show');

        Route::get('/connector-providers', [ConnectorProviderController::class, 'index'])
            ->name('api.v1.connector-providers.index');
        Route::get('/connector-providers/{key}', [ConnectorProviderController::class, 'show'])
            ->name('api.v1.connector-providers.show');

        Route::get('/data-sources', [DataSourceController::class, 'index'])
            ->name('api.v1.data-sources.index');
        Route::post('/data-sources', [DataSourceController::class, 'store'])
            ->middleware('throttle:api-writes')
            ->name('api.v1.data-sources.store');
        Route::get('/data-sources/{id}', [DataSourceController::class, 'show'])
            ->name('api.v1.data-sources.show');
        Route::patch('/data-sources/{id}', [DataSourceController::class, 'update'])
            ->middleware('throttle:api-writes')
            ->name('api.v1.data-sources.update');
        Route::post('/data-sources/{id}/test', [DataSourceController::class, 'test'])
            ->middleware('throttle:api-writes')
            ->name('api.v1.data-sources.test');
        Route::get('/data-sources/{id}/health', [DataSourceController::class, 'health'])
            ->name('api.v1.data-sources.health');
        Route::get('/data-sources/{id}/capabilities', [DataSourceController::class, 'capabilities'])
            ->name('api.v1.data-sources.capabilities');
        Route::post('/data-sources/{id}/disable', [DataSourceController::class, 'disable'])
            ->middleware('throttle:api-writes')
            ->name('api.v1.data-sources.disable');
    });
});

