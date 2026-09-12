<?php

namespace App\Providers;

use App\Chats\Listeners\AttachGuestEnquiry;
use App\Live\PublishLiveUpdates;
use App\Mail\OnMessage;
use App\Notifications\Notify;
use Illuminate\Auth\Events\Login;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        Event::subscribe(Notify::class);
        Event::subscribe(PublishLiveUpdates::class);
        Event::subscribe(OnMessage::class);
        Event::listen(Login::class, AttachGuestEnquiry::class);
        Paginator::defaultView('vendor.pagination.xcar');
        // После выкладки Turbo видит новый хэш и делает одну полную загрузку —
        // иначе дописывает второй бандл рядом со старым: два Turbo, два Stimulus.
        Vite::useScriptTagAttributes(['data-turbo-track' => 'reload']);
        Vite::useStyleTagAttributes(['data-turbo-track' => 'reload']);
    }
}
