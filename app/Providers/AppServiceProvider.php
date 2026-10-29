<?php

// This file is part of the Relief Inventory Project (https://reliefinventory.fiforms.net)
// Licensed under the GNU GPL v. 3. See LICENSE.md for details

namespace App\Providers;

use App\Auth\AccountSession;
use App\Auth\AccountUserProvider;
use App\Models\Control\Warehouse;
use App\Services\ActivityRecorder;
use App\Services\MailSettings;
use App\Services\SlowQueryLog;
use App\Services\Warehouses\WarehouseConsole;
use App\Services\Warehouses\WarehouseContext;
use App\Services\Warehouses\WarehouseJobs;
use App\Support\InstanceSignedUrl;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Http\Events\RequestHandled;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
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
        // Which warehouse this request/job/command works in (MULTI_WAREHOUSE.md §8).
        $this->app->singleton(WarehouseContext::class);
        $this->app->singleton(WarehouseJobs::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        // Not in tests: a parallel worker's own databases are only chosen after boot; tests\InstanceSchemas
        // switches once they are.
        if ($this->app->runningInConsole() && ! $this->app->runningUnitTests()) {
            $this->app->booted(fn () => app(WarehouseContext::class)->bootConsole());
        }

        // Queued jobs run in the warehouse that queued them. Registered before any other JobProcessing listener
        // (MailSettings, below), which must already see the job's warehouse.
        WarehouseJobs::register();
        // Every command takes --warehouse; with several warehouses, one that works on a warehouse's data needs it.
        WarehouseConsole::register();

        // Logins check the central account, then resolve this warehouse's person (MULTI_WAREHOUSE.md §6).
        Auth::provider('accounts', fn ($app, array $config) => new AccountUserProvider($app['hash'], $config['model']));
        Event::listen(Login::class, fn (Login $e) => AccountSession::started($e->user));
        Event::listen(Logout::class, fn () => AccountSession::forget());
        // Account-level, so signed with APP_KEY rather than the warehouse's key (VerifyEmailController checks it).
        // Both are emailed, so they are built on the warehouse's public address, never the request's Host header (which
        // whoever asks for the email chooses), and name the warehouse when the address alone doesn't (§3).
        VerifyEmail::createUrlUsing(function ($notifiable) {
            $warehouse = Warehouse::current();
            $generator = InstanceSignedUrl::generator();
            $generator->forceRootUrl($warehouse->publicRoot());

            return $warehouse->withSelector($generator->temporarySignedRoute(
                'verification.verify',
                now()->addMinutes(config('auth.verification.expire', 60)),
                ['id' => $notifiable->getKey(), 'hash' => sha1($notifiable->getEmailForVerification())],
            ));
        });
        ResetPassword::createUrlUsing(fn ($notifiable, string $token) => Warehouse::current()->publicUrl(
            '/reset-password/'.$token.'?email='.rawurlencode($notifiable->getEmailForPasswordReset()),
        ));

        // Federation backup endpoints. Named limiters, keyed by who is calling: a numeric `throttle:N,1` shares one counter
        // per IP across every route, so a client polling a backup's status would use up the budget of the call that
        // starts one. The key comes from the request itself (session token, else the claimed trust id) because
        // throttling runs before `trust-auth`, so the authenticated trust isn't known yet.
        foreach (['fed-backup-read' => 120, 'fed-backup-create' => 6, 'fed-backup-download' => 30] as $name => $perMinute) {
            RateLimiter::for($name, fn (Request $request) => Limit::perMinute($perMinute)->by($request->ip().'|'.sha1((string) ($request->bearerToken() ?: $request->header('X-RI-Trust')))));
        }

        // Who did what at each station (activity_log) — read by the Training report card.
        ActivityRecorder::register();

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
