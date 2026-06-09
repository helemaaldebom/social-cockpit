<?php

namespace App\Providers;

use App\Contracts\PublisherInterface;
use App\Services\PublerPublisher;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PublisherInterface::class, PublerPublisher::class);
    }

    public function boot(): void
    {
        // Rate limiting voor login: max 5 pogingen per minuut per IP
        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });
    }
}
