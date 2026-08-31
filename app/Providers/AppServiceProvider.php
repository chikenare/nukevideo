<?php

namespace App\Providers;

use App\Enums\CdnDriver;
use App\Services\Cdn\BunnyProvider;
use App\Services\Cdn\CdnProvider;
use App\Services\Cdn\SelfHostedProvider;
use App\Settings\CdnSettings;
use ClickHouseDB\Client as ClickhouseClient;
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

        // Scoped, not a singleton: the self-hosted provider holds a ProxyRing, which memoizes
        // rows `nodes:probe` rewrites every minute. A singleton would pin that memo past the
        // request — php-fpm would not notice, Octane and the queue worker would serve routing
        // from a fleet that has since changed.
        $this->app->scoped(CdnProvider::class, fn ($app) => match (CdnDriver::from($app->make(CdnSettings::class)->provider)) {
            CdnDriver::SelfHosted => $app->make(SelfHostedProvider::class),
            CdnDriver::Bunny => $app->make(BunnyProvider::class),
        });

        $this->app->extend(ClickhouseClient::class, fn (ClickhouseClient $client) => $client->https(! $this->app->isLocal()));
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

        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
    }
}
