<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Mail;
use App\Mail\Transport\GmailApiTransport;

class MailServiceProvider extends ServiceProvider
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
        Mail::extend('gmail-api', function () {
            $clientId = config('services.google.client_id');
            $clientSecret = config('services.google.client_secret');
            $refreshToken = config('services.google.refresh_token');
            $fromAddress = config('mail.from.address');

            if (!$clientId || !$clientSecret || !$refreshToken) {
                throw new \Exception('Gmail API credentials not configured');
            }

            return new GmailApiTransport($clientId, $clientSecret, $refreshToken, $fromAddress);
        });
    }
}
