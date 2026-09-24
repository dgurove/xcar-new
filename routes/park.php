<?php

use App\Http\Admin\ReferenceController;
use App\Http\Park\ActController;
use App\Http\Park\MailController;
use App\Http\Park\MoneyController;
use App\Http\Park\PartyController;
use App\Http\Park\PassController;
use App\Http\Park\RequestController;
use App\Http\Park\TariffController;
use App\Http\Park\VehicleController;
use App\Http\Park\VehicleInvoiceController;
use App\Http\Park\VendorContactController;
use App\Http\Park\VendorController;
use App\Http\Park\YardController;
use Illuminate\Support\Facades\Route;

// Стоянка — свой хост того же приложения. Вход общий; пускает раздел «park».
Route::domain(config('xcar.park_host'))->middleware(['auth', 'section:park'])->group(function () {
    Route::get('/', [RequestController::class, 'index']);
    Route::get('/requests', [RequestController::class, 'index']);

    Route::get('/requests/new', [RequestController::class, 'create']);
    // Из писем: та же почта с вшитой выборкой «надо завести»; «Завести» ведёт в разбор письма `/requests/new`.
    Route::get('/requests/from-mail', [MailController::class, 'fromMail']);
    Route::post('/requests/from-mail/{candidate}/decline', [MailController::class, 'decline']);
    Route::post('/requests', [RequestController::class, 'store']);
    Route::get('/requests/{req}', [RequestController::class, 'show']);
    Route::get('/requests/{req}/peek', [RequestController::class, 'peek']);
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
    Route::get('/cars/{vehicle}', [VehicleController::class, 'show']);
    Route::get('/cars/{vehicle}/peek', [VehicleController::class, 'peek']);
    Route::get('/cars/{vehicle}/letters', [VehicleController::class, 'letters']);
    Route::put('/cars/{vehicle}', [VehicleController::class, 'update'])->middleware('park.manage');
    Route::post('/cars/{vehicle}/media', [VehicleController::class, 'upload']);
    Route::post('/cars/{vehicle}/media/order', [VehicleController::class, 'reorder']);
    Route::post('/cars/{vehicle}/media/{media}/rotate', [VehicleController::class, 'rotateMedia']);
    Route::delete('/cars/{vehicle}/media/{media}', [VehicleController::class, 'destroyMedia']);
    Route::post('/cars/{vehicle}/note', [VehicleController::class, 'note']);
    Route::post('/cars/{vehicle}/letters/{message}/done', [VehicleController::class, 'letterDone']);
    Route::post('/cars/{vehicle}/docs', [VehicleController::class, 'doc']);
    Route::post('/cars/{vehicle}/docs/{doc}', [VehicleController::class, 'doc']);
    Route::post('/cars/{vehicle}/offer', [VehicleController::class, 'link'])->middleware('park.manage');
    Route::post('/cars/{vehicle}/cancel', [VehicleController::class, 'cancel'])->middleware('park.manage');
    Route::post('/cars/{vehicle}/sold', [VehicleController::class, 'sold'])->middleware('park.manage');
    // Выдача по QR: проверка кода при выдаче, подтверждение покупателя, ссылка страховой ещё раз.
    Route::post('/cars/{vehicle}/pass-check', [PassController::class, 'check'])->middleware(['park.manage', 'throttle:60,1']);
    Route::post('/cars/{vehicle}/buyer/confirm', [PassController::class, 'confirm'])->middleware('park.manage');
    Route::post('/cars/{vehicle}/buyer/reject', [PassController::class, 'reject'])->middleware('park.manage');
    Route::post('/cars/{vehicle}/pickup-link', [PassController::class, 'link'])->middleware(['park.manage', 'throttle:5,1']);
    Route::delete('/cars/{vehicle}', [VehicleController::class, 'destroy'])->middleware('park.manage');
    Route::post('/cars/{vehicle}/move', [VehicleController::class, 'move']);
    Route::post('/cars/{vehicle}/yard', [VehicleController::class, 'yard']);
    Route::post('/cars/{vehicle}/restore', [VehicleController::class, 'restore'])->middleware('park.manage');
    Route::post('/cars/{vehicle}/undo-intake', [VehicleController::class, 'undoIntake'])->middleware('park.manage');
    Route::post('/cars/{vehicle}/undo-release', [VehicleController::class, 'undoRelease'])->middleware('park.manage');
    Route::get('/cars/{vehicle}/invoices/new', [VehicleInvoiceController::class, 'create'])->middleware('park.area:money');
    Route::post('/cars/{vehicle}/invoices', [VehicleInvoiceController::class, 'store'])->middleware('park.area:money');
    Route::post('/cars/{vehicle}/charges', [VehicleInvoiceController::class, 'charge'])->middleware('park.area:money');
    Route::delete('/cars/{vehicle}/charges/{charge}', [VehicleInvoiceController::class, 'uncharge'])->middleware('park.area:money');

    // Деньги — по галке «Деньги» (админу всегда): счета и долги целиком; реквизиты — настройки, только админу.
    Route::middleware('park.area:money')->group(function () {
        Route::get('/money', [MoneyController::class, 'index']);
        Route::get('/money/debts', [MoneyController::class, 'debts']);
        Route::get('/money/closing', [MoneyController::class, 'closing']);
        Route::post('/money/closing', [MoneyController::class, 'close']);
        Route::get('/money/parties', [PartyController::class, 'index'])->middleware('park.area:admin');
        Route::post('/money/parties', [PartyController::class, 'store'])->middleware('park.area:admin');
        Route::put('/money/parties/{party}', [PartyController::class, 'update'])->middleware('park.area:admin');
        Route::delete('/money/parties/{party}', [PartyController::class, 'destroy'])->middleware('park.area:admin');
        Route::get('/money/invoices/{invoice}', [MoneyController::class, 'show']);
        Route::get('/money/invoices/{invoice}/peek', [MoneyController::class, 'peek']);
        Route::get('/money/invoices/{invoice}/pdf', [MoneyController::class, 'file']);
        Route::get('/money/invoices/{invoice}/print', [MoneyController::class, 'print']);
        Route::get('/money/invoices/{invoice}/act', [MoneyController::class, 'act']);
        Route::put('/money/invoices/{invoice}', [MoneyController::class, 'update']);
        Route::post('/money/invoices/{invoice}/payments', [MoneyController::class, 'pay']);
        Route::get('/money/invoices/{invoice}/payments/{payment}/slip', [MoneyController::class, 'slip']);
        Route::delete('/money/invoices/{invoice}/payments/{payment}', [MoneyController::class, 'unpay']);
        Route::post('/money/invoices/{invoice}/void', [MoneyController::class, 'void']);
    });

    Route::get('/reference/cars', [VehicleController::class, 'suggest']);
    Route::get('/reference/vin', [ReferenceController::class, 'vin']);
    Route::get('/reference/brands', [ReferenceController::class, 'brands']);
    Route::get('/reference/models', [ReferenceController::class, 'models']);
    Route::post('/reference/brands', [ReferenceController::class, 'createBrand']);
    Route::post('/reference/models', [ReferenceController::class, 'createModel']);
    Route::get('/acts/{vehicle}/{kind}', [ActController::class, 'show'])->where('kind', 'intake|release|contract|handover');

    Route::get('/yards', [YardController::class, 'index']);
    Route::get('/yards/{yard}/peek', [YardController::class, 'peek']);
    // Настройки парковки — только админу: правка парковок, вендоры, прайс (у управляющего их нет вовсе).
    Route::middleware('park.area:admin')->group(function () {
        Route::post('/yards', [YardController::class, 'store']);
        Route::put('/yards/{yard}', [YardController::class, 'update']);
        Route::delete('/yards/{yard}', [YardController::class, 'destroy']);
        // Вендоры и прайс — на парковке (с 24.09.2026); прежний адрес /clients — 301.
        Route::get('/clients', fn () => redirect('/vendors', 301));
        Route::get('/vendors', [VendorController::class, 'index']);
        Route::get('/vendors/{vendor}', [VendorController::class, 'show']);
        Route::post('/vendors', [VendorController::class, 'store']);
        Route::put('/vendors/{vendor}', [VendorController::class, 'update']);
        Route::delete('/vendors/{vendor}', [VendorController::class, 'destroy']);
        Route::post('/vendors/{vendor}/contract', [VendorController::class, 'contract']);
        Route::delete('/vendors/{vendor}/contract', [VendorController::class, 'dropContract']);
        Route::post('/vendors/{vendor}/contacts', [VendorContactController::class, 'store']);
        Route::put('/vendors/{vendor}/contacts/{contact}', [VendorContactController::class, 'update']);
        Route::delete('/vendors/{vendor}/contacts/{contact}', [VendorContactController::class, 'destroy']);
        Route::post('/tariffs/ladder', [TariffController::class, 'save']);
        Route::get('/tariffs', [TariffController::class, 'index']);
    });

    // Почта парковки разделом — по галке «Почта»; писать и отвечать из дела ТС можно и без неё.
    Route::get('/mail', [MailController::class, 'index'])->middleware('park.area:mail');
    Route::get('/mail/new', [MailController::class, 'compose']);
    Route::post('/mail', [MailController::class, 'send']);
    Route::post('/mail/file', [MailController::class, 'file']);
    Route::post('/mail/sync', [MailController::class, 'sync'])->middleware('park.area:mail');
    Route::get('/mail/attachments/{attachment}', [MailController::class, 'attachment']);
    Route::get('/mail/messages/{message}/body', [MailController::class, 'body']);
    Route::post('/mail/messages/{message}/flag', [MailController::class, 'flag']);
    Route::post('/mail/messages/{message}/parse', [MailController::class, 'reparse']);
    Route::post('/mail/messages/{message}/retry', [MailController::class, 'resend']);
    Route::get('/mail/{thread}', [MailController::class, 'show'])->middleware('park.area:mail');
    Route::get('/mail/{thread}/window', [MailController::class, 'window']);
    Route::get('/mail/{thread}/reply/{message}', [MailController::class, 'reply']);
    Route::post('/mail/{thread}/unread', [MailController::class, 'unread'])->middleware('park.area:mail');
    Route::post('/mail/archive-other', [MailController::class, 'archiveOther'])->middleware('park.area:mail');
    Route::post('/mail/{thread}/archive', [MailController::class, 'archive'])->middleware('park.area:mail');
    Route::post('/mail/case/{kind}/{id}/archive', [MailController::class, 'archiveCase'])->whereIn('kind', ['v', 'c', 't'])->whereNumber('id')->middleware('park.area:mail');
    Route::post('/mail/{thread}/candidate', [MailController::class, 'candidate']);
    Route::post('/mail/{thread}/link', [MailController::class, 'link']);
});

// На хосте стоянки ничего, кроме стоянки и входа.
Route::domain(config('xcar.park_host'))->group(function () {
    Route::fallback(fn () => abort(404));
});
