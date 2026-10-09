<?php

use App\Http\Admin\ReferenceController;
use App\Http\Cabinet\InviteController;
use App\Http\Mail\AttachController;
use App\Http\Mail\ScanController;
use App\Http\Park\ActController;
use App\Http\Park\MailController;
use App\Http\Park\MoneyController;
use App\Http\Park\PartyController;
use App\Http\Park\PassController;
use App\Http\Park\RequestController;
use App\Http\Park\TariffController;
use App\Http\Park\UserController;
use App\Http\Park\VehicleController;
use App\Http\Park\VehicleInvoiceController;
use App\Http\Park\VendorContactController;
use App\Http\Park\VendorController;
use App\Http\Park\YardController;
use App\Http\Site\LandingController;
use Illuminate\Support\Facades\Route;

// Первый экран для страховых и лизинга; вошедшего и установленное приложение уводит в заявки.
Route::domain(config('xcar.park_host'))->get('/', [LandingController::class, 'park']);

// Стоянка — свой хост того же приложения. Вход общий; пускает раздел «park».
Route::domain(config('xcar.park_host'))->middleware(['auth', 'section:park'])->group(function () {
    Route::get('/requests', [RequestController::class, 'index']);

    Route::get('/requests/new', [RequestController::class, 'create']);
    // Из писем: та же почта с вшитой выборкой «надо завести»; «Завести» ведёт в разбор письма `/requests/new`.
    Route::get('/requests/from-mail', [MailController::class, 'fromMail']);
    Route::post('/requests/from-mail/{candidate}/decline', [MailController::class, 'decline']);
    Route::get('/requests/from-mail/{candidate}/letters', [MailController::class, 'candidateLetters']);
    // «✨ Распознать»: файлы цепочки → OCR → что подставить (ScanController).
    Route::get('/requests/from-mail/{candidate}/scan', [ScanController::class, 'show']);
    Route::post('/requests/from-mail/{candidate}/scan', [ScanController::class, 'scan']);
    Route::post('/requests/from-mail/{candidate}/scan/apply', [ScanController::class, 'apply']);
    // Читалка «Завести» в разборе письма: документы читаются сами по порядку, поля заполняются по ходу (x-mail.reader).
    Route::get('/requests/from-mail/{candidate}/scan/live', [ScanController::class, 'readerLive']);
    Route::post('/requests/from-mail/{candidate}/scan/read', [ScanController::class, 'readerRead']);
    Route::post('/requests/from-mail/{candidate}/scan/stop', [ScanController::class, 'readerStop']);
    Route::post('/requests/from-mail/{candidate}/scan/reject', [ScanController::class, 'readerReject']);
    Route::post('/requests', [RequestController::class, 'store']);
    Route::get('/requests/{req}', [RequestController::class, 'show']);
    Route::post('/requests/{req}/intake', [RequestController::class, 'intake']);
    Route::post('/requests/{req}/move', [RequestController::class, 'move']);
    Route::post('/requests/{req}/release', [RequestController::class, 'release']);
    Route::post('/requests/{req}/refuse', [RequestController::class, 'refuse'])->middleware('park.manage');
    Route::post('/requests/{req}/close', [RequestController::class, 'close']);
    Route::post('/requests/{req}/schedule', [RequestController::class, 'schedule']);
    Route::post('/requests/{req}/start', [RequestController::class, 'start']);
    Route::post('/requests/{req}/assign', [RequestController::class, 'assign']);
    Route::post('/requests/{req}/contact', [RequestController::class, 'contact']);

    Route::get('/cars', [VehicleController::class, 'index']);
    Route::get('/cars/export', [VehicleController::class, 'export'])->middleware('park.manage');
    Route::get('/cars/{vehicle}', [VehicleController::class, 'show']);
    Route::get('/cars/{vehicle}/letters', [VehicleController::class, 'letters']);
    // Кадры письма прикрепляются: строка хода и ряд «От страховой», пока ImportThreadFiles идёт (attach_controller).
    Route::get('/cars/{vehicle}/attach', [AttachController::class, 'vehicle']);
    Route::put('/cars/{vehicle}', [VehicleController::class, 'update'])->middleware('park.manage');
    Route::post('/cars/{vehicle}/fields', [VehicleController::class, 'fields'])->middleware('park.manage');
    Route::post('/cars/{vehicle}/media', [VehicleController::class, 'upload']);
    Route::post('/cars/{vehicle}/media/order', [VehicleController::class, 'reorder']);
    // Глаз — показ кадра в продаже: это продажа, только админу. Поворот и удаление — не помощнику.
    Route::post('/cars/{vehicle}/media/visibility', [VehicleController::class, 'visibility'])->middleware('ability:isAdmin');
    Route::post('/cars/{vehicle}/media/{media}/hide', [VehicleController::class, 'hideMedia'])->middleware('ability:isAdmin');
    Route::post('/cars/{vehicle}/media/{media}/rotate', [VehicleController::class, 'rotateMedia'])->middleware('park.manage');
    Route::delete('/cars/{vehicle}/media/{media}', [VehicleController::class, 'destroyMedia'])->middleware('park.manage');
    Route::post('/cars/{vehicle}/note', [VehicleController::class, 'note']);
    // «✨ Распознать» в деле ТС — то же окно, что у цепочки «Из писем» (ScanController, Scan\VehicleSubject).
    Route::get('/cars/{vehicle}/scan', [ScanController::class, 'vehicleShow'])->middleware('park.manage');
    Route::post('/cars/{vehicle}/scan', [ScanController::class, 'vehicleScan'])->middleware('park.manage');
    Route::post('/cars/{vehicle}/scan/apply', [ScanController::class, 'vehicleApply'])->middleware('park.manage');
    // Чип «в документе …»: кнопка отправляет форму дела (там _method=PUT) на свой адрес.
    Route::match(['post', 'put'], '/cars/{vehicle}/take', [ScanController::class, 'vehicleTake'])->middleware('park.manage');
    Route::post('/cars/{vehicle}/letters/{message}/done', [VehicleController::class, 'letterDone']);
    Route::post('/cars/{vehicle}/docs', [VehicleController::class, 'doc']);
    Route::post('/cars/{vehicle}/docs/{doc}', [VehicleController::class, 'doc']);
    Route::post('/cars/{vehicle}/offer', [VehicleController::class, 'link'])->middleware('ability:isAdmin');
    Route::post('/cars/{vehicle}/sell', [VehicleController::class, 'sell'])->middleware('park.manage');
    Route::post('/cars/{vehicle}/unsell', [VehicleController::class, 'unsell'])->middleware('park.manage');
    Route::post('/cars/{vehicle}/cancel', [VehicleController::class, 'cancel'])->middleware('park.manage');
    Route::post('/cars/{vehicle}/sold', [VehicleController::class, 'sold'])->middleware('park.manage');
    // Выдача по QR: проверка кода при выдаче, подтверждение покупателя, ссылка страховой ещё раз.
    Route::post('/cars/{vehicle}/pass-check', [PassController::class, 'check'])->middleware(['park.manage', 'throttle:60,1']);
    Route::post('/cars/{vehicle}/buyer/confirm', [PassController::class, 'confirm'])->middleware('park.manage');
    Route::post('/cars/{vehicle}/buyer/reject', [PassController::class, 'reject'])->middleware('park.manage');
    Route::post('/cars/{vehicle}/pickup-link', [PassController::class, 'link'])->middleware(['park.manage', 'throttle:5,1']);
    Route::post('/cars/{vehicle}/pickup-link/create', [PassController::class, 'create'])->middleware('park.manage');
    Route::post('/cars/{vehicle}/pickup-link/renew', [PassController::class, 'renew'])->middleware(['park.manage', 'throttle:5,1']);
    Route::delete('/cars/{vehicle}', [VehicleController::class, 'destroy'])->middleware('park.manage');
    Route::post('/cars/{vehicle}/move', [VehicleController::class, 'move']);
    Route::post('/cars/{vehicle}/transfer', [VehicleController::class, 'transfer'])->middleware('park.manage');
    Route::post('/cars/{vehicle}/yard', [VehicleController::class, 'yard']);
    Route::post('/cars/{vehicle}/dates', [VehicleController::class, 'dates'])->middleware('park.manage');
    Route::post('/cars/{vehicle}/restore', [VehicleController::class, 'restore'])->middleware('park.manage');
    Route::post('/cars/{vehicle}/undo-intake', [VehicleController::class, 'undoIntake'])->middleware('park.manage');
    Route::post('/cars/{vehicle}/undo-release', [VehicleController::class, 'undoRelease'])->middleware('park.manage');
    Route::get('/cars/{vehicle}/invoices/new', [VehicleInvoiceController::class, 'create'])->middleware('park.manage');
    Route::post('/cars/{vehicle}/invoices', [VehicleInvoiceController::class, 'store'])->middleware('park.manage');
    Route::post('/cars/{vehicle}/charges', [VehicleInvoiceController::class, 'charge'])->middleware('park.manage');
    Route::delete('/cars/{vehicle}/charges/{charge}', [VehicleInvoiceController::class, 'uncharge'])->middleware('park.manage');

    // Деньги — управляющему и админу (не помощнику): счета, долги, закрытие месяца, реквизиты плательщиков.
    Route::middleware('park.manage')->group(function () {
        Route::get('/money', [MoneyController::class, 'index']);
        Route::get('/money/debts', [MoneyController::class, 'debts']);
        Route::get('/money/closing', [MoneyController::class, 'closing']);
        Route::post('/money/closing', [MoneyController::class, 'close']);
        // Реквизиты плательщиков — и управляющему (только парковки, без наших — PartyController::guard).
        Route::get('/money/parties', [PartyController::class, 'index']);
        Route::post('/money/parties', [PartyController::class, 'store']);
        Route::put('/money/parties/{party}', [PartyController::class, 'update']);
        Route::delete('/money/parties/{party}', [PartyController::class, 'destroy']);
        Route::get('/money/invoices/{invoice}', [MoneyController::class, 'show']);
        Route::get('/money/invoices/{invoice}/pdf', [MoneyController::class, 'file']);
        Route::get('/money/invoices/{invoice}/print', [MoneyController::class, 'print']);
        Route::get('/money/invoices/{invoice}/act', [MoneyController::class, 'act']);
        Route::get('/money/invoices/{invoice}/acts', [MoneyController::class, 'acts']);
        Route::get('/money/invoices/{invoice}/registry.xlsx', [MoneyController::class, 'registryXlsx']);
        Route::put('/money/invoices/{invoice}', [MoneyController::class, 'update']);
        Route::post('/money/invoices/{invoice}/payments', [MoneyController::class, 'pay']);
        Route::get('/money/invoices/{invoice}/payments/{payment}/slip', [MoneyController::class, 'slip']);
        Route::delete('/money/invoices/{invoice}/payments/{payment}', [MoneyController::class, 'unpay']);
        Route::post('/money/invoices/{invoice}/void', [MoneyController::class, 'void']);
    });

    Route::get('/reference/cars', [VehicleController::class, 'suggest']);
    Route::get('/reference/twins', [VehicleController::class, 'twins']);
    Route::get('/reference/vin', [ReferenceController::class, 'vin']);
    Route::post('/reference/car-text', [ReferenceController::class, 'carText']);
    Route::get('/reference/brands', [ReferenceController::class, 'brands']);
    Route::get('/reference/settlements', [ReferenceController::class, 'settlements']);
    Route::get('/reference/models', [ReferenceController::class, 'models']);
    Route::post('/reference/brands', [ReferenceController::class, 'createBrand']);
    Route::post('/reference/settlements', [ReferenceController::class, 'createSettlement']);
    Route::post('/reference/models', [ReferenceController::class, 'createModel']);
    Route::get('/acts/{vehicle}/{kind}', [ActController::class, 'show'])->where('kind', 'intake|release|contract|handover');

    // Раздел «Парковки» — тому, у кого их больше одной (`User::seesYards`): управляющему Питера его нет.
    Route::get('/yards', [YardController::class, 'index'])->middleware('ability:seesYards');
    // Вендоры управляющему — смотреть карточку и править контакты (кому слать отчёты и счета); всё прочее — админу.
    Route::middleware('park.manage')->group(function () {
        Route::get('/vendors', [VendorController::class, 'index']);
        Route::get('/vendors/{vendor}', [VendorController::class, 'show']);
        Route::post('/vendors/{vendor}/contacts', [VendorContactController::class, 'store']);
        Route::put('/vendors/{vendor}/contacts/{contact}', [VendorContactController::class, 'update']);
        Route::delete('/vendors/{vendor}/contacts/{contact}', [VendorContactController::class, 'destroy']);
    });
    // Настройки парковки — администратору парковки: правка парковок, вендоров (условия, ставки, договор, адреса), прайс, люди.
    Route::middleware('ability:isParkAdmin')->group(function () {
        Route::get('/users', [UserController::class, 'index']);
        Route::post('/users/invites', [UserController::class, 'invite']);
        Route::post('/users/invites/{invite}/off', [InviteController::class, 'disable']);
        Route::post('/users/invites/{invite}/on', [InviteController::class, 'enable']);
        Route::put('/users/{user}', [UserController::class, 'update'])->whereNumber('user');
        Route::post('/users/{user}/revoke', [UserController::class, 'revoke'])->whereNumber('user');
        Route::post('/yards', [YardController::class, 'store']);
        Route::put('/yards/{yard}', [YardController::class, 'update']);
        Route::delete('/yards/{yard}', [YardController::class, 'destroy']);
        // Вендоры и прайс — на парковке (с 24.09.2026); прежний адрес /clients — 301.
        Route::get('/clients', fn () => redirect('/vendors', 301));
        Route::post('/vendors', [VendorController::class, 'store']);
        Route::put('/vendors/{vendor}', [VendorController::class, 'update']);
        Route::delete('/vendors/{vendor}', [VendorController::class, 'destroy']);
        Route::post('/vendors/{vendor}/contract', [VendorController::class, 'contract']);
        Route::delete('/vendors/{vendor}/contract', [VendorController::class, 'dropContract']);
        Route::post('/tariffs/ladder', [TariffController::class, 'save']);
        Route::get('/tariffs', [TariffController::class, 'index']);
    });

    // Почта парковки разделом — управляющему и админу; помощник пишет и отвечает из дела ТС.
    Route::get('/mail', [MailController::class, 'index'])->middleware('park.manage');
    Route::get('/mail/new', [MailController::class, 'compose']);
    Route::post('/mail', [MailController::class, 'send']);
    Route::post('/mail/file', [MailController::class, 'file']);
    // «Проверить почту» стоит и на «Из писем» — у заявок он без раздела «Почта».
    Route::post('/mail/sync', [MailController::class, 'sync']);
    Route::get('/mail/attachments/{attachment}', [MailController::class, 'attachment']);
    Route::get('/mail/messages/{message}/body', [MailController::class, 'body']);
    Route::post('/mail/messages/{message}/flag', [MailController::class, 'flag']);
    Route::post('/mail/messages/{message}/parse', [MailController::class, 'reparse']);
    Route::post('/mail/messages/{message}/retry', [MailController::class, 'resend']);
    Route::get('/mail/{thread}', [MailController::class, 'show'])->middleware('park.manage');
    Route::get('/mail/{thread}/window', [MailController::class, 'window']);
    Route::get('/mail/{thread}/reply/{message}', [MailController::class, 'reply']);
    Route::post('/mail/{thread}/unread', [MailController::class, 'unread'])->middleware('park.manage');
    Route::post('/mail/{thread}/answered', [MailController::class, 'answered'])->middleware('park.manage');
    Route::post('/mail/archive-other', [MailController::class, 'archiveOther'])->middleware('park.manage');
    Route::post('/mail/{thread}/archive', [MailController::class, 'archive'])->middleware('park.manage');
    Route::post('/mail/case/{kind}/{id}/archive', [MailController::class, 'archiveCase'])->whereIn('kind', ['v', 'c', 't'])->whereNumber('id')->middleware('park.manage');
    Route::post('/mail/{thread}/candidate', [MailController::class, 'candidate']);
    Route::post('/mail/{thread}/link', [MailController::class, 'link']);
});

// На хосте стоянки ничего, кроме стоянки и входа.
Route::domain(config('xcar.park_host'))->group(function () {
    Route::fallback(fn () => abort(404));
});
