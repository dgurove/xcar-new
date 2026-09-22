<?php

namespace App\Providers;

use App\Billing\Events\InvoiceVoided;
use App\Billing\Events\PaymentClaimed;
use App\Billing\Events\PaymentRecorded;
use App\Billing\Events\PaymentVoided;
use App\Billing\Listeners\AdvanceOnClaim;
use App\Billing\Listeners\AdvanceOnPayment;
use App\Billing\Listeners\PayoutWhenPaid;
use App\Billing\Listeners\RevokeAgentFee;
use App\Chats\Events\ChatMessagePosted;
use App\Chats\Listeners\AttachGuestEnquiry;
use App\Chats\Listeners\ScheduleAutoReply;
use App\Live\PublishLiveUpdates;
use App\Mail\Extraction\AttachmentReader;
use App\Mail\Extraction\NullAttachmentReader;
use App\Mail\OnMessage;
use App\Media\StampOnAdd;
use App\Notifications\Notify;
use App\Park\Listeners\SyncOffer;
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
use Spatie\MediaLibrary\MediaCollections\Events\MediaHasBeenAddedEvent;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Сканы и документы из писем пока не читаются; сюда встанет OCR или модель по API.
        $this->app->bind(AttachmentReader::class, NullAttachmentReader::class);
    }

    public function boot(): void
    {
        Event::subscribe(Notify::class);
        Event::subscribe(PublishLiveUpdates::class);
        Event::subscribe(OnMessage::class);
        Event::subscribe(SyncOffer::class);
        Event::listen(PaymentRecorded::class, AdvanceOnPayment::class);
        Event::listen(PaymentRecorded::class, PayoutWhenPaid::class);
        Event::listen(PaymentClaimed::class, AdvanceOnClaim::class);
        Event::listen([InvoiceVoided::class, PaymentVoided::class], RevokeAgentFee::class);
        Event::listen(Login::class, AttachGuestEnquiry::class);
        Event::listen(ChatMessagePosted::class, ScheduleAutoReply::class);
        Event::subscribe(Passkeys::class);
        Event::listen(MediaHasBeenAddedEvent::class, StampOnAdd::class);
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
