<?php

namespace App\Providers;

use App\Chats\Listeners\AttachGuestEnquiry;
use App\Live\PublishLiveUpdates;
use App\Mail\OnMessage;
use App\Notifications\Notify;
use App\Users\Auth\WebAuthnProvider;
use App\Users\Passkeys;
use Illuminate\Auth\Events\Login;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Laragear\WebAuthn\Assertion\Validator\AssertionValidator;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        Event::subscribe(Notify::class);
        Event::subscribe(PublishLiveUpdates::class);
        Event::subscribe(OnMessage::class);
        Event::listen(Login::class, AttachGuestEnquiry::class);
        Event::subscribe(Passkeys::class);
        Auth::provider('xcar-webauthn', fn (Application $app, array $config) => new WebAuthnProvider(
            $app->make('hash'),
            $config['model'],
            $app->make(AssertionValidator::class),
            $config['password_fallback'] ?? true,
        ));
        Paginator::defaultView('vendor.pagination.xcar');
        // После выкладки Turbo видит новый хэш и делает одну полную загрузку —
        // иначе дописывает второй бандл рядом со старым: два Turbo, два Stimulus.
        Vite::useScriptTagAttributes(['data-turbo-track' => 'reload']);
        Vite::useStyleTagAttributes(['data-turbo-track' => 'reload']);
    }
}
