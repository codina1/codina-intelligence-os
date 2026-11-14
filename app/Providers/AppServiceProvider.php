<?php

namespace App\Providers;

use App\Connectors\Registry\ConnectorRegistry;
use App\Shared\Contracts\AiProviderInterface;
use App\Shared\Support\NullAiProvider;
use App\Tenancy\Services\TenantContext;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);
        $this->app->singleton(ConnectorRegistry::class);
        $this->app->bind(AiProviderInterface::class, NullAiProvider::class);
    }

    public function boot(): void
    {
        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(5)->by(strtolower((string) $request->input('email')).'|'.$request->ip());
        });

        RateLimiter::for('api-writes', function (Request $request) {
            return Limit::perMinute(60)->by(optional($request->user())->id ?: $request->ip());
        });
    }
}
