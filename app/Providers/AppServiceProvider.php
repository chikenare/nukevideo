<?php

namespace App\Providers;

use App\Enums\CdnDriver;
use App\Services\Cdn\BunnyProvider;
use App\Services\Cdn\CdnProvider;
use App\Services\Cdn\SelfHostedProvider;
use App\Settings\CdnSettings;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if ($this->app->environment('local') && class_exists(\Laravel\Telescope\TelescopeServiceProvider::class)) {
            $this->app->register(\Laravel\Telescope\TelescopeServiceProvider::class);
            $this->app->register(TelescopeServiceProvider::class);
        }

        $this->app->singleton(CdnProvider::class, fn ($app) => match (CdnDriver::from($app->make(CdnSettings::class)->provider)) {
            CdnDriver::SelfHosted => $app->make(SelfHostedProvider::class),
            CdnDriver::Bunny => $app->make(BunnyProvider::class),
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Request::macro('project', function () {
            $project = $this->attributes->get('resolved_project');
            abort_if(! $project, 400, 'Project context required. Send X-Project-Ulid header.');

            return $project;
        });

        // Generous enough for the panel's polling; the point is bounding abuse, not shaping traffic.
        RateLimiter::for('api', function (Request $request) {
            $actor = $request->user();

            return Limit::perMinute(240)->by($actor ? get_class($actor).':'.$actor->getKey() : $request->ip());
        });

        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
    }
}
