<?php

declare(strict_types=1);

namespace App\Providers;

use App\Policies\DashboardPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->configureDefaults();

        Model::preventLazyLoading(! $this->app->isProduction());

        $this->configureRateLimiting();

        Gate::define('viewDashboard', [DashboardPolicy::class, 'view']);
    }

    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('api', fn (Request $request): Limit => Limit::perMinute(60)->by(self::throttleKey($request)));

        RateLimiter::for('uploads', fn (Request $request): Limit => Limit::perMinute(20)->by(self::throttleKey($request)));

        RateLimiter::for('api-tokens', fn (Request $request): Limit => Limit::perMinute(5)->by(
            Str::transliterate(Str::lower($request->string('email')->toString()).'|'.$request->ip()),
        ));
    }

    private static function throttleKey(Request $request): string
    {
        $id = $request->user()?->getAuthIdentifier();

        return $id !== null ? (string) $id : (string) $request->ip();
    }
}
