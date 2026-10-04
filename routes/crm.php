<?php

use App\Http\Admin\BankController;
use App\Http\Admin\BidController;
use App\Http\Admin\ChatController;
use App\Http\Admin\DealController;
use App\Http\Admin\GalleryController;
use App\Http\Admin\GarageController;
use App\Http\Admin\InterestController;
use App\Http\Admin\InvoiceController;
use App\Http\Admin\MailAccountController;
use App\Http\Admin\MailController;
use App\Http\Admin\MailTemplateController;
use App\Http\Admin\MoneyController;
use App\Http\Admin\OfferController;
use App\Http\Admin\OfferDraftController;
use App\Http\Admin\OfferPhotoController;
use App\Http\Admin\PayLinkController;
use App\Http\Admin\PurchaseCarPhotoController;
use App\Http\Admin\PurchaseController;
use App\Http\Admin\ReferenceController;
use App\Http\Admin\RouteController;
use App\Http\Admin\TagController;
use App\Http\Admin\TelegramChatController;
use App\Http\Admin\UserController;
use App\Http\Admin\UserGroupController;
use App\Http\Admin\VendorContactController;
use App\Http\Admin\VendorController;
use App\Http\Admin\WatermarkController;
use App\Http\Admin\WorkflowController;
use App\Http\Cabinet\InviteController;
use App\Http\Cabinet\ProfileController;
use App\Http\Garage\SettlementController as GarageSettlementController;
use App\Http\Mail\ScanController;
use App\Http\Site\ShareController;
use App\Purchases\Car;
use App\Purchases\Purchase;
use App\Support\Surface;
use Illuminate\Support\Facades\Route;

// CRM — свой хост того же приложения, только для сотрудников.
Route::domain(config('xcar.crm_host'))->middleware(['auth', 'ability:isStaff'])->group(function () {
    // Модератору — только этот список: черновики предложений, справочник, профиль, почта по галке. Чужое предложение
    // (опубликованное, из закупки) — 404 через `offer.edit`. Всё остальное ниже — `ability:canManageCrm`: новый раздел, не
    // внесённый сюда, модератору закрыт сам.
    Route::get('/', [OfferController::class, 'index'])->name('crm.offers');
    Route::get('/offers', fn () => redirect('/'.(request()->getQueryString() ? '?'.request()->getQueryString() : ''), 301));
    Route::post('/offers', [OfferController::class, 'store']);
    // Из писем: та же почта с вшитой выборкой «надо завести» — раньше `/offers/{offer}`, иначе from-mail примут за номер.
    Route::middleware('ability:canCrmMail')->group(function () {
        Route::get('/offers/from-mail', [MailController::class, 'fromMail']);
        Route::post('/offers/from-mail/{candidate}/create', [MailController::class, 'promote']);
        Route::post('/offers/from-mail/{candidate}/decline', [MailController::class, 'decline']);
        Route::get('/offers/from-mail/{candidate}/letters', [MailController::class, 'candidateLetters']);
        // «✨ Распознать» — то же окно, что на парковке (ScanController): у цепочки «Из писем» и у предложения.
        Route::get('/offers/from-mail/{candidate}/scan', [ScanController::class, 'show']);
        Route::post('/offers/from-mail/{candidate}/scan', [ScanController::class, 'scan']);
        Route::post('/offers/from-mail/{candidate}/scan/apply', [ScanController::class, 'apply']);
    });
    Route::get('/offers/twins', [OfferController::class, 'twins']);
    Route::middleware('offer.edit')->group(function () {
        Route::get('/offers/{offer}', [OfferController::class, 'edit'])->name('crm.offers.edit');
        Route::get('/offers/{offer}/letters', [MailController::class, 'offerLetters'])->middleware('ability:canCrmMail');
        // «✨» читает документы предложения, а вложения писем — только тем, кому открыта почта (`OfferSubject::for`).
        Route::get('/offers/{offer}/scan', [ScanController::class, 'offerShow']);
        Route::post('/offers/{offer}/scan', [ScanController::class, 'offerScan']);
        Route::post('/offers/{offer}/scan/apply', [ScanController::class, 'offerApply']);
        // Читалка в блоке «Документы» редактора: читает сама по порядку, поля заполняются по ходу (x-mail.reader).
        Route::get('/offers/{offer}/scan/live', [ScanController::class, 'offerLive']);
        Route::post('/offers/{offer}/scan/read', [ScanController::class, 'offerRead']);
        Route::post('/offers/{offer}/scan/stop', [ScanController::class, 'offerStop']);
        Route::put('/offers/{offer}', [OfferController::class, 'update']);
        // Черновик из писем до первого сохранения: «Отменить» и «Не заявка» (decline) — черновика не было, письма снова ждут.
        Route::post('/offers/{offer}/drop', [MailController::class, 'dropDraft'])->middleware('ability:canCrmMail');
        // Ушли из пустого черновика «+ Новый» — его нет (sendBeacon при уходе; не пустой сервер не трогает).
        Route::post('/offers/{offer}/drop-empty', [OfferController::class, 'dropEmpty']);
        Route::delete('/offers/{offer}', [OfferDraftController::class, 'destroy']);
        // Черновик из парковки завели в продажу зря — отвязать ТС и удалить («Снять с продажи»).
        Route::post('/offers/{offer}/unlist', [OfferController::class, 'unlist']);
        Route::post('/offers/{offer}/media', [OfferPhotoController::class, 'store']);
        Route::post('/offers/{offer}/media/migtorg', [OfferPhotoController::class, 'migtorg']);
        Route::get('/offers/{offer}/migtorg', [OfferPhotoController::class, 'migtorgChip']);
        Route::post('/offers/{offer}/media/order', [OfferPhotoController::class, 'reorder']);
        Route::post('/offers/{offer}/media/visibility', [OfferPhotoController::class, 'visibility']);
        Route::post('/offers/{offer}/media/{media}/hide', [OfferPhotoController::class, 'toggle']);
        Route::post('/offers/{offer}/media/{media}/rotate', [OfferPhotoController::class, 'rotate']);
        // Чужой водяной знак кадра: шторка из просмотрщика, кадр со знаком для сравнения, «Вернуть» и свой файл.
        Route::get('/offers/{offer}/media/{media}/mark', [OfferPhotoController::class, 'mark']);
        Route::get('/offers/{offer}/media/{media}/marked', [OfferPhotoController::class, 'marked']);
        Route::post('/offers/{offer}/media/{media}/mark', [OfferPhotoController::class, 'unmark']);
        Route::post('/offers/{offer}/media/{media}/learn', [OfferPhotoController::class, 'learn']);
        Route::delete('/offers/{offer}/media/{media}', [OfferPhotoController::class, 'destroy']);
    });
    Route::prefix('work/mail')->middleware('ability:canCrmMail')->group(function () {
        Route::get('/', [MailController::class, 'index']);
        Route::get('/new', [MailController::class, 'compose']);
        Route::post('/', [MailController::class, 'send']);
        Route::post('/file', [MailController::class, 'file']);
        Route::post('/sync', [MailController::class, 'sync']);
        Route::get('/attachments/{attachment}', [MailController::class, 'attachment']);
        Route::get('/messages/{message}/body', [MailController::class, 'body']);
        Route::post('/messages/{message}/flag', [MailController::class, 'flag']);
        Route::post('/messages/{message}/parse', [MailController::class, 'reparse']);
        Route::post('/messages/{message}/retry', [MailController::class, 'resend']);
        Route::get('/{thread}', [MailController::class, 'show']);
        Route::get('/{thread}/window', [MailController::class, 'window']);
        Route::get('/{thread}/reply/{message}', [MailController::class, 'reply']);
        Route::post('/{thread}/unread', [MailController::class, 'unread']);
        Route::post('/archive-other', [MailController::class, 'archiveOther']);
        Route::post('/{thread}/archive', [MailController::class, 'archive']);
        Route::post('/case/{kind}/{id}/archive', [MailController::class, 'archiveCase'])->whereIn('kind', ['o', 'c', 't'])->whereNumber('id');
        Route::post('/{thread}/candidate', [MailController::class, 'candidate']);
        Route::post('/{thread}/link', [MailController::class, 'link']);
    });
    // Кабинет CRM = «Настройки»: корень — профиль (общий экран с сайтом), /account сюда же.
    Route::get('/settings', [ProfileController::class, 'profile']);
    Route::permanentRedirect('/account', '/settings');
    // Форма профиля (имя, фото) шлёт PUT /account. Редирект выше ловит любой метод, а маршрут того же адреса перекрывает
    // объявленный раньше — поэтому PUT строго после него; без него фото и имя в CRM молча терялись.
    Route::put('/account', [ProfileController::class, 'update']);
    Route::get('/reference/vin', [ReferenceController::class, 'vin']);
    Route::post('/reference/car-text', [ReferenceController::class, 'carText']);
    Route::get('/reference/brands', [ReferenceController::class, 'brands']);
    Route::get('/reference/models', [ReferenceController::class, 'models']);
    Route::get('/reference/settlements', [ReferenceController::class, 'settlements']);
    Route::post('/reference/brands', [ReferenceController::class, 'createBrand']);
    Route::post('/reference/models', [ReferenceController::class, 'createModel']);

    Route::middleware('ability:canManageCrm')->group(function () {
        Route::get('/work/invoices/new', [InvoiceController::class, 'create']);
        Route::post('/work/invoices', [InvoiceController::class, 'store']);
        // Шеринг PDF: маршруты без домена на хосте CRM отбивает ResolveSurface — свои копии.
        Route::match(['get', 'post'], '/offers/{offer}/pdf', [ShareController::class, 'pdf']);
        // Карточка «Гаража» зовёт счёт машины и отмену ссылки оплаты путём без хоста — на CRM тоже свои копии.
        Route::get('/garage/cars/{offer}/invoice/pdf', [GarageSettlementController::class, 'pdf']);
        Route::delete('/garage/cars/{offer}/links/{link}', [GarageSettlementController::class, 'cancelLink']);
        Route::post('/share/error', [ShareController::class, 'report'])->middleware('throttle:30,1');
        Route::post('/offers/{offer}/extend', [OfferController::class, 'extend']);
        Route::post('/offers/{offer}/state', [OfferController::class, 'state']);
        Route::post('/offers/{offer}/rate', [OfferController::class, 'rate']);
        // Пачкой из «Оцененных» и «Публикации»: галочки строк, `offers[]` — номера.
        Route::post('/offers/schedule', [OfferController::class, 'scheduleMany']);
        Route::post('/offers/unschedule', [OfferController::class, 'unscheduleMany']);
        Route::post('/offers/{offer}/unschedule', [OfferController::class, 'unschedule']);
        Route::post('/offers/{offer}/garage', [OfferController::class, 'garage']);
        Route::post('/offers/{offer}/exit/{exit}', [RouteController::class, 'exit']);
        Route::post('/offers/{offer}/stage', [RouteController::class, 'place']);
        Route::post('/offers/{offer}/back', [RouteController::class, 'back']);
        Route::post('/offers/{offer}/pickup', [RouteController::class, 'pickup']);
        Route::delete('/offers/{offer}/pickup', [RouteController::class, 'dropPickup']);

        Route::post('/confirmations/{bid}/accept', [BidController::class, 'accept']);
        Route::post('/confirmations/{bid}/decline', [BidController::class, 'decline']);
        Route::post('/interests/{interest}', [InterestController::class, 'update']);

        // Работа: сделки, почта и чаты под одним заголовком.
        Route::redirect('/work', '/work/deals');
        Route::get('/work/deals', [DealController::class, 'index']);
        Route::get('/work/deals/{deal}', [DealController::class, 'show']);
        // У кого что в гараже и во что обошлось.
        Route::get('/work/garage', [GarageController::class, 'index']);
        Route::post('/work/deals/{deal}/note', [DealController::class, 'note']);
        Route::put('/work/deals/{deal}/money', [DealController::class, 'money']);
        // Деньги по сделкам: заявки менеджеров об оплате, вознаграждения к выплате, счета; карточка счёта — общая со стоянкой.
        Route::get('/work/money', [MoneyController::class, 'index']);
        Route::get('/work/money/managers', [MoneyController::class, 'managers']);
        Route::post('/work/payments/{payment}/confirm', [MoneyController::class, 'confirm']);
        Route::post('/work/payments/{payment}/reject', [MoneyController::class, 'reject']);
        Route::post('/work/money/invoices/{invoice}/links', [PayLinkController::class, 'store']);
        Route::delete('/work/money/links/{link}', [PayLinkController::class, 'destroy']);
        Route::post('/work/money/acquiring/{attempt}/refund', [PayLinkController::class, 'refund']);
        Route::get('/work/money/bank', [BankController::class, 'index']);
        Route::post('/work/money/bank/{transaction}/match', [BankController::class, 'match']);
        Route::post('/work/money/bank/{transaction}/ignore', [BankController::class, 'ignore']);
        Route::prefix('work/money/invoices/{invoice}')->group(function () {
            Route::get('/', [MoneyController::class, 'show']);
            Route::put('/', [MoneyController::class, 'update']);
            Route::get('/pdf', [MoneyController::class, 'file']);
            Route::get('/print', [MoneyController::class, 'print']);
            Route::get('/act', [MoneyController::class, 'act']);
            Route::post('/payments', [MoneyController::class, 'pay']);
            Route::get('/payments/{payment}/slip', [MoneyController::class, 'slip']);
            Route::delete('/payments/{payment}', [MoneyController::class, 'unpay']);
            Route::post('/void', [MoneyController::class, 'void']);
        });
        Route::get('/work/chats', [ChatController::class, 'index']);
        Route::get('/work/chats/{chat}', [ChatController::class, 'show']);

        Route::get('/gallery', [GalleryController::class, 'index']);
        Route::post('/gallery', [GalleryController::class, 'store']);
        // Старые адреса разделов — закладки и ссылки из уведомлений.
        Route::get('/deals', fn () => redirect('/work/deals', 301));
        // Только корень и экран чата: `/chats/{id}/messages|files|typing` из web.php должны дойти до ленты.
        Route::get('/chats', fn () => redirect('/work/chats'.(request()->getQueryString() ? '?'.request()->getQueryString() : ''), 301));
        Route::get('/chats/{chat}', fn (int $chat) => redirect("/work/chats/{$chat}", 301))->whereNumber('chat');
        // Уведомления и тосты зовут на экран кабинета без хоста — на CRM это «Чаты» в работе.
        Route::get('/account/chats', fn () => redirect('/work/chats'));
        Route::get('/account/chats/{chat}', fn (int $chat) => redirect("/work/chats/{$chat}"))->whereNumber('chat');

        Route::get('/purchases', [PurchaseController::class, 'index']);
        Route::post('/purchases', [PurchaseController::class, 'store']);
        Route::get('/purchases/limits', [PurchaseController::class, 'restrictions']);
        Route::post('/purchases/limits/{user}', [PurchaseController::class, 'restrict']);
        Route::post('/purchases/prices/{offer}/choose', [PurchaseController::class, 'choose']);
        Route::post('/purchases/prices/{offer}/cancel', [PurchaseController::class, 'unchoose']);
        Route::get('/purchases/{purchase}', [PurchaseController::class, 'show']);
        Route::put('/purchases/{purchase}', [PurchaseController::class, 'update']);
        Route::post('/purchases/{purchase}/state', [PurchaseController::class, 'state']);
        Route::post('/purchases/{purchase}/extend', [PurchaseController::class, 'extend']);
        Route::post('/purchases/{purchase}/file', [PurchaseController::class, 'upload']);
        Route::get('/purchases/{purchase}/import', [PurchaseController::class, 'preview']);
        Route::post('/purchases/{purchase}/import', [PurchaseController::class, 'import']);
        Route::get('/purchases/{purchase}/export', [PurchaseController::class, 'export']);
        Route::post('/purchases/{purchase}/counter', [PurchaseController::class, 'counterUpload']);
        Route::get('/purchases/{purchase}/counter', [PurchaseController::class, 'counterPreview']);
        Route::post('/purchases/{purchase}/counter/move', [PurchaseController::class, 'counterMove']);
        Route::redirect('/purchases/{purchase}/offers', '/purchases/{purchase}', 301);
        Route::get('/purchases/{purchase}/{car}', [PurchaseController::class, 'car']);
        Route::match(['get', 'post'], '/purchases/{purchase}/{car}/pdf', [ShareController::class, 'carPdf']);
        Route::put('/purchases/{purchase}/{car}', [PurchaseController::class, 'updateCar']);
        // Оценка — в окошке строки таблицы; старый экран ведёт туда же.
        Route::get('/purchases/{purchase}/{car}/estimate', fn (Purchase $purchase, Car $car) => redirect("/purchases/{$purchase->number}?preset=unfinal&vid=table&peek={$car->ref}", 301));
        Route::post('/purchases/{purchase}/{car}/estimate', [PurchaseController::class, 'estimate']);
        Route::post('/purchases/cars/{car}/media', [PurchaseCarPhotoController::class, 'store']);
        Route::post('/purchases/cars/{car}/media/order', [PurchaseCarPhotoController::class, 'reorder']);
        Route::post('/purchases/cars/{car}/media/{media}/hide', [PurchaseCarPhotoController::class, 'toggle']);
        Route::post('/purchases/cars/{car}/media/{media}/rotate', [PurchaseCarPhotoController::class, 'rotate']);
        Route::delete('/purchases/cars/{car}/media/{media}', [PurchaseCarPhotoController::class, 'destroy']);

        // Настройки: справочное, что меняется редко.
        Route::prefix('settings')->group(function () {
            Route::get('/users', [UserController::class, 'index']);
            // Группы менеджеров и модераторов — до `/users/{user}`, иначе «groups» приняли бы за человека.
            Route::post('/users/groups', [UserGroupController::class, 'store']);
            Route::put('/users/groups/{group}', [UserGroupController::class, 'update']);
            Route::delete('/users/groups/{group}', [UserGroupController::class, 'destroy']);
            Route::get('/users/{user}', [UserController::class, 'show'])->whereNumber('user');
            Route::put('/users/{user}', [UserController::class, 'update']);
            Route::post('/users/{user}/access', [UserController::class, 'decide']);
            Route::delete('/users/{user}', [UserController::class, 'destroy']);
            Route::post('/users/{user}/password', [UserController::class, 'passwordLink']);
            Route::post('/users/{user}/impersonate', [UserController::class, 'impersonate']);
            Route::put('/users/{user}/party', [UserController::class, 'party']);
            Route::get('/users/{user}/statement', [UserController::class, 'statement']);
            Route::get('/users/{user}/export', [UserController::class, 'export']);
            Route::post('/users/invites', [InviteController::class, 'store']);
            Route::post('/users/invites/{invite}/off', [InviteController::class, 'disable']);
            Route::post('/users/invites/{invite}/on', [InviteController::class, 'enable']);

            Route::get('/tags', [TagController::class, 'index']);
            Route::post('/tags', [TagController::class, 'store']);
            Route::post('/tags/order', [TagController::class, 'reorder']);
            Route::put('/tags/{tag}', [TagController::class, 'update']);
            Route::delete('/tags/{tag}', [TagController::class, 'destroy']);

            Route::get('/watermarks', [WatermarkController::class, 'index']);
            Route::get('/watermarks/{name}/image', [WatermarkController::class, 'image']);
            Route::put('/watermarks/{name}', [WatermarkController::class, 'update']);
            Route::delete('/watermarks/{name}', [WatermarkController::class, 'destroy']);

            Route::get('/vendors', [VendorController::class, 'index']);
            Route::post('/vendors', [VendorController::class, 'store']);
            Route::get('/vendors/{vendor}', [VendorController::class, 'show']);
            Route::put('/vendors/{vendor}', [VendorController::class, 'update']);
            Route::post('/vendors/{vendor}/contacts', [VendorContactController::class, 'store']);
            Route::put('/vendors/{vendor}/contacts/{contact}', [VendorContactController::class, 'update']);
            Route::delete('/vendors/{vendor}/contacts/{contact}', [VendorContactController::class, 'destroy']);
            // Парковочное у вендора (реквизиты, хранение, прайс, деньги, заявки на приёмку) — на парковке с 24.09.2026.
            Route::get('/tariffs', fn () => redirect()->away(Surface::Park->url('/tariffs'), 301));

            Route::post('/workflows/{workflow}/enable', [WorkflowController::class, 'activate']);
            Route::post('/workflows/{workflow}/launch', [WorkflowController::class, 'autoStart']);
            Route::post('/workflows/{workflow}/fill', [WorkflowController::class, 'fill']);
            Route::post('/workflows/{workflow}/order', [WorkflowController::class, 'reorder']);
            Route::post('/workflows/{workflow}/blocks', [WorkflowController::class, 'storeBlock']);
            Route::put('/workflows/blocks/{block}', [WorkflowController::class, 'updateBlock']);
            Route::delete('/workflows/blocks/{block}', [WorkflowController::class, 'destroyBlock']);
            Route::get('/workflows/{workflow}/stages/new', [WorkflowController::class, 'createStage']);
            Route::post('/workflows/{workflow}/stages', [WorkflowController::class, 'storeStage']);
            Route::get('/workflows/stages/{stage}', [WorkflowController::class, 'editStage']);
            Route::put('/workflows/stages/{stage}', [WorkflowController::class, 'updateStage']);
            Route::delete('/workflows/stages/{stage}', [WorkflowController::class, 'destroyStage']);

            Route::get('/mailboxes', [MailAccountController::class, 'index']);
            Route::get('/mailboxes/new', [MailAccountController::class, 'create']);
            Route::post('/mailboxes', [MailAccountController::class, 'store']);
            Route::get('/mailboxes/{account}', [MailAccountController::class, 'edit']);
            Route::put('/mailboxes/{account}', [MailAccountController::class, 'update']);
            Route::delete('/mailboxes/{account}', [MailAccountController::class, 'destroy']);
            Route::post('/mailboxes/{account}/check', [MailAccountController::class, 'test']);
            Route::post('/mailboxes/{account}/sync', [MailAccountController::class, 'sync']);

            Route::get('/bank', [BankController::class, 'settings']);
            Route::post('/bank/connect', [BankController::class, 'connect']);
            Route::get('/bank/callback', [BankController::class, 'callback']);
            Route::put('/bank', [BankController::class, 'save']);
            Route::post('/bank/sync', [BankController::class, 'sync']);

            // Переписка бота (Настройки → «Бот Telegram»).
            Route::prefix('telegram')->group(function () {
                Route::get('/', [TelegramChatController::class, 'index']);
                Route::get('/files/{message}', [TelegramChatController::class, 'file']);
                Route::get('/{chat}', [TelegramChatController::class, 'show'])->whereNumber('chat');
                Route::get('/{chat}/messages', [TelegramChatController::class, 'messages'])->whereNumber('chat');
                Route::post('/{chat}/messages', [TelegramChatController::class, 'post'])->whereNumber('chat');
                Route::post('/{chat}/typing', [TelegramChatController::class, 'typing'])->whereNumber('chat');
                Route::get('/{chat}/messages/{seq}', [TelegramChatController::class, 'message'])->whereNumber(['chat', 'seq']);
                Route::patch('/{chat}/messages/{seq}', [TelegramChatController::class, 'edit'])->whereNumber(['chat', 'seq']);
                Route::delete('/{chat}/messages/{seq}', [TelegramChatController::class, 'destroy'])->whereNumber(['chat', 'seq']);
            });

            Route::get('/templates', [MailTemplateController::class, 'index']);
            Route::get('/templates/new', [MailTemplateController::class, 'create']);
            Route::post('/templates', [MailTemplateController::class, 'store']);
            Route::get('/templates/{template}', [MailTemplateController::class, 'edit']);
            Route::put('/templates/{template}', [MailTemplateController::class, 'update']);
            Route::delete('/templates/{template}', [MailTemplateController::class, 'destroy']);
        });

        if (app()->isLocal()) {
            Route::view('/ui', 'admin.ui');
        }
    });
});

// На хосте CRM ничего, кроме CRM и общих путей.
Route::domain(config('xcar.crm_host'))->group(function () {
    Route::fallback(fn () => abort(404));
});
