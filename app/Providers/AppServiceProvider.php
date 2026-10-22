<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Providers;

use App\Services\MailSettings;
use App\Services\SlowQueryLog;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One instance per process: it counts queries across a whole request.
        $this->app->singleton(SlowQueryLog::class);
        // Keeps the .env mail config it snapshots before the settings below override it.
        $this->app->singleton(MailSettings::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // Email Settings (/setup/email) override .env mail config: applied when mail is first used in a request,
        // and before every queued job so a long-running worker sees a change without a restart.
        $this->app->afterResolving('mail.manager', fn ($manager) => app(MailSettings::class)->apply($manager));
        Event::listen(JobProcessing::class, fn () => app(MailSettings::class)->apply());

        // Slow-query log (config/slowlog.php). Off unless RI_SLOW_QUERY_MS is set, so a normal install pays nothing.
        if (config('slowlog.threshold_ms') > 0) {
            DB::listen(fn ($query) => app(SlowQueryLog::class)->observe($query));
            Event::listen(RequestHandled::class, fn ($e) => app(SlowQueryLog::class)->finishRequest($e->request, $e->response));
        }

        // Behind a TLS-terminating reverse proxy (typical for the Docker
        // image) this makes URLs/redirects use the public scheme and host.
        if ($proxies = config('app.trusted_proxies')) {
            TrustProxies::at($proxies === '*' ? '*' : array_map('trim', explode(',', $proxies)));
        }
    }
}
