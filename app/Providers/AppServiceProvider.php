<?php

namespace App\Providers;

use App\Enums\CdnDriver;
use App\Models\Project;
use App\Models\User;
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

        $this->app->singleton(CdnProvider::class, fn ($app) => match (CdnDriver::from($app->make(CdnSettings::class)->provider)) {
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

        /**
         * The account a token reads its own usage as.
         *
         * `usage` is keyed by `user_id` in ClickHouse, and a project API key authenticates AS the
         * project — `$request->user()` is a Project, whose `id` comes from a different sequence
         * entirely. Reading it as a user id would not fail; it would quietly answer with whichever
         * account happens to share that number. A macro rather than a helper on each controller
         * because getting it wrong is silent, and two copies is how one of them drifts.
         */
        Request::macro('accountId', function (): int {
            $caller = $this->user();

            return $caller instanceof Project ? (int) $caller->user_id : (int) $caller->id;
        });

        /** Whether the caller is an operator. A project key never is: it is not a User at all. */
        Request::macro('isAdmin', function (): bool {
            return $this->user() instanceof User && (bool) $this->user()->is_admin;
        });

        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));

    }
}
