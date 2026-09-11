<?php

namespace App\Providers;

use App\Live\PublishLiveUpdates;
use App\Mail\OnMessage;
use App\Notifications\Notify;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        Event::subscribe(Notify::class);
        Event::subscribe(PublishLiveUpdates::class);
        Event::subscribe(OnMessage::class);
        Paginator::defaultView('vendor.pagination.xcar');
    }
}
