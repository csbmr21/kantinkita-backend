<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        // Define the 'api' rate limiter required by throttleApi() middleware
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        // Auth endpoints rate limiter
        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });

        // Register custom Gmail API mailer transport
        $this->app->afterResolving(\Illuminate\Mail\MailManager::class, function (\Illuminate\Mail\MailManager $manager) {
            $manager->extend('gmail-api', function () {
                return new \App\Mail\Transport\GmailApiTransport(
                    config('services.google.client_id'),
                    config('services.google.client_secret'),
                    config('services.google.refresh_token'),
                    config('mail.from.address')
                );
            });
        });
    }
}
