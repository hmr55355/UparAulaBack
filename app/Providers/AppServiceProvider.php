<?php

namespace App\Providers;

use App\Models\AttendanceRecord;
use App\Models\Grade;
use App\Observers\AttendanceObserver;
use App\Observers\GradeObserver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // 300 req/min para el resto de la API (nota "SEGURIDAD" del prompt).
        RateLimiter::for('api', function ($request) {
            return Limit::perMinute(300)->by($request->user()?->id ?: $request->ip());
        });

        // 60 req/min para endpoints de autenticación (login/register/forgot/reset).
        RateLimiter::for('auth', function ($request) {
            return Limit::perMinute(60)->by($request->ip());
        });

        Grade::observe(GradeObserver::class);
        AttendanceRecord::observe(AttendanceObserver::class);
    }
}
