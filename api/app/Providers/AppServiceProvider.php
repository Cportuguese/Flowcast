<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();
    }

    /**
     * Named limiters referenced from bootstrap/app.php (`api`, applied to
     * the whole API group) and routes/api.php (`auth`, `public`). Numbers
     * live in config/security.php.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return $request->user()
                ? Limit::perMinute(config('security.rate_limits.api'))->by('user:'.$request->user()->id)
                : Limit::perMinute(config('security.rate_limits.guest'))->by('ip:'.$request->ip());
        });

        RateLimiter::for('auth', function (Request $request) {
            $email = strtolower(trim((string) $request->input('email')));

            return Limit::perMinute(config('security.rate_limits.auth'))->by('auth:'.$email.'|'.$request->ip());
        });

        RateLimiter::for('public', function (Request $request) {
            return Limit::perMinute(config('security.rate_limits.public'))->by('public:'.$request->ip());
        });

        RateLimiter::for('sensitive', function (Request $request) {
            return Limit::perMinute(config('security.rate_limits.sensitive'))
                ->by('sensitive:'.($request->user()?->id ?? $request->ip()));
        });
    }
}
