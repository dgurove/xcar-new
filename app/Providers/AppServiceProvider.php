<?php

namespace App\Providers;

use App\Billing\Acquiring\Gateway;
use App\Billing\Acquiring\YooKassa;
use App\Billing\Events\InvoiceIssued;
use App\Billing\Events\InvoiceVoided;
use App\Billing\Events\PaymentClaimed;
use App\Billing\Events\PaymentConfirmed;
use App\Billing\Events\PaymentRecorded;
use App\Billing\Events\PaymentRejected;
use App\Billing\Events\PaymentVoided;
use App\Billing\Listeners\AdvanceOnClaim;
use App\Billing\Listeners\AdvanceOnPayment;
use App\Billing\Listeners\CloseLinksWhenSettled;
use App\Billing\Listeners\CreditDealOnManual;
use App\Billing\Listeners\KeepPayLink;
use App\Billing\Listeners\PassTurnOnInvoice;
use App\Billing\Listeners\PayoutWhenPaid;
use App\Billing\Listeners\ReturnOnReject;
use App\Billing\Listeners\RevokeAgentFee;
use App\Billing\Listeners\StartSelectionDue;
use App\Cars\Events\CarChanged;
use App\Chats\Events\ChatMessagePosted;
use App\Chats\Listeners\AttachGuestEnquiry;
use App\Chats\Listeners\MailGuestReply;
use App\Chats\Listeners\ScheduleAutoReply;
use App\Garage\Listeners\CloseWhenPaid;
use App\Garage\Listeners\PayoutWhenBuyerPaid;
use App\Garage\Listeners\ReopenWhenVoided;
use App\Garage\Listeners\StartPrepOnArrival;
use App\Live\PublishLiveUpdates;
use App\Live\RefreshCar;
use App\Logistics\Events\TransportMoved;
use App\Logistics\Listeners\NotifyLogistics;
use App\Logistics\Listeners\RecordHistory;
use App\Logistics\Listeners\RecordOnOffer;
use App\Logistics\Listeners\RefreshLogistics;
use App\Mail\Extraction\AttachmentReader;
use App\Mail\Extraction\TextAttachmentReader;
use App\Mail\OnMessage;
use App\Media\StampOnAdd;
use App\Notifications\CollapseNotices;
use App\Notifications\Notify;
use App\Offers\Events\OfferStateChanged;
use App\Offers\Listeners\AdvanceOnReply;
use App\Offers\Listeners\MarkSupplierLetter;
use App\Offers\Listeners\RetractWhenGone;
use App\Offers\Listeners\SyncHandover;
use App\Park\Actions\SendPickupLink;
use App\Park\Events\VehicleSold;
use App\Park\Listeners\SyncOffer;
use App\Users\Access\EnrollOnLogin;
use App\Users\Auth\WebAuthnProvider;
use App\Users\Passkeys;
use App\Workflow\Events\StageEntered;
use Illuminate\Auth\Events\Login;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Laragear\WebAuthn\Assertion\Validator\AssertionValidator;
use Spatie\MediaLibrary\MediaCollections\Events\MediaHasBeenAddedEvent;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Сканы и документы из писем пока не читаются; сюда встанет OCR или модель по API.
        $this->app->bind(AttachmentReader::class, TextAttachmentReader::class);
        // Эквайринг — ЮKassa, подключённая в СберБизнесе.
        $this->app->bind(Gateway::class, YooKassa::class);
    }

    public function boot(): void
    {
        // Локально ленивая загрузка в цикле (N+1) пишется в storage/logs/lazy.log с адресом экрана: видно при проходе
        // экранов, а работа соседних сессий в том же дереве не падает исключением.
        if (! $this->app->isProduction() && ! $this->app->runningUnitTests()) {
            Model::preventLazyLoading();
            Model::handleLazyLoadingViolationUsing(function (Model $model, string $relation) {
                Log::build(['driver' => 'single', 'path' => storage_path('logs/lazy.log')])
                    ->info(($this->app->runningInConsole() ? 'console' : request()->method().' '.request()->path()).' '.$model::class.'::'.$relation);
            });
        }
        Event::subscribe(Notify::class);
        Event::subscribe(NotifyLogistics::class);
        Event::subscribe(RecordOnOffer::class);
        Event::listen(TransportMoved::class, RecordHistory::class);
        Event::listen(TransportMoved::class, \App\Logistics\Actions\NextLeg::class);
        Event::subscribe(RefreshLogistics::class);
        Event::listen(NotificationSent::class, CollapseNotices::class);
        Event::subscribe(PublishLiveUpdates::class);
        Event::subscribe(OnMessage::class);
        Event::subscribe(SyncOffer::class);
        Event::subscribe(SyncHandover::class);
        Event::subscribe(AdvanceOnReply::class);
        Event::subscribe(MarkSupplierLetter::class);
        Event::listen(OfferStateChanged::class, RetractWhenGone::class);
        Event::listen(TransportMoved::class, StartPrepOnArrival::class);
        Event::listen(VehicleSold::class, SendPickupLink::class);
        Event::listen(\App\Park\Events\VehicleAccepted::class, \App\Park\Listeners\ReleaseSold::class);
        Event::listen(PaymentRecorded::class, AdvanceOnPayment::class);
        Event::listen(PaymentRecorded::class, PayoutWhenPaid::class);
        Event::listen(PaymentRecorded::class, PayoutWhenBuyerPaid::class);
        Event::listen(PaymentRecorded::class, CloseWhenPaid::class);
        Event::listen(PaymentClaimed::class, AdvanceOnClaim::class);
        Event::listen(PaymentConfirmed::class, AdvanceOnPayment::class);
        Event::listen(StageEntered::class, [AdvanceOnPayment::class, 'entered']);
        Event::listen(StageEntered::class, StartSelectionDue::class);
        Event::listen(PaymentRejected::class, ReturnOnReject::class);
        Event::listen([PaymentRecorded::class, InvoiceVoided::class], CloseLinksWhenSettled::class);
        Event::listen([InvoiceIssued::class, PaymentRecorded::class, PaymentVoided::class], KeepPayLink::class);
        Event::listen([InvoiceIssued::class, InvoiceVoided::class], PassTurnOnInvoice::class);
        Event::listen([InvoiceVoided::class, PaymentVoided::class], RevokeAgentFee::class);
        Event::listen([InvoiceVoided::class, PaymentVoided::class], ReopenWhenVoided::class);
        Event::listen([PaymentRecorded::class, PaymentVoided::class, InvoiceVoided::class], CreditDealOnManual::class);
        Event::listen(Login::class, AttachGuestEnquiry::class);
        Event::listen(Login::class, EnrollOnLogin::class);
        Event::listen(ChatMessagePosted::class, ScheduleAutoReply::class);
        Event::listen(ChatMessagePosted::class, MailGuestReply::class);
        Event::subscribe(Passkeys::class);
        Event::listen(MediaHasBeenAddedEvent::class, StampOnAdd::class);
        // Машина поменялась (поля из любой двери, файлы) — её экраны перечитываются на всех хостах.
        Event::listen(CarChanged::class, RefreshCar::class);
        Event::listen(MediaHasBeenAddedEvent::class, fn (MediaHasBeenAddedEvent $e) => RefreshCar::media($e->media, added: true));
        Event::listen('eloquent.saved: '.Media::class, fn (Media $m) => RefreshCar::media($m));
        Event::listen('eloquent.deleted: '.Media::class, fn (Media $m) => RefreshCar::media($m, gone: true));
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
