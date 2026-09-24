<?php

use App\Http\Admin\BidController;
use App\Http\Admin\ChatController;
use App\Http\Admin\DealController;
use App\Http\Admin\GalleryController;
use App\Http\Admin\InterestController;
use App\Http\Admin\InvoiceController;
use App\Http\Admin\MailAccountController;
use App\Http\Admin\MailController;
use App\Http\Admin\MailTemplateController;
use App\Http\Admin\MoneyController;
use App\Http\Admin\OfferController;
use App\Http\Admin\OfferPhotoController;
use App\Http\Admin\PurchaseCarPhotoController;
use App\Http\Admin\PurchaseController;
use App\Http\Admin\ReferenceController;
use App\Http\Admin\RouteController;
use App\Http\Admin\TagController;
use App\Http\Admin\UserController;
use App\Http\Admin\VendorContactController;
use App\Http\Admin\VendorController;
use App\Http\Admin\WorkflowController;
use App\Http\Cabinet\InviteController;
use App\Http\Cabinet\ProfileController;
use App\Http\Site\ShareController;
use App\Purchases\Car;
use App\Purchases\Purchase;
use App\Support\Surface;
use Illuminate\Support\Facades\Route;

// CRM — свой хост того же приложения, только для сотрудников.
Route::domain(config('xcar.crm_host'))->middleware(['auth', 'staff'])->group(function () {
    Route::get('/', [OfferController::class, 'index'])->name('crm.offers');
    Route::get('/offers', fn () => redirect('/'.(request()->getQueryString() ? '?'.request()->getQueryString() : ''), 301));
    Route::post('/offers', [OfferController::class, 'store']);
    // Из писем: та же почта с вшитой выборкой «надо завести» — раньше `/offers/{offer}`, иначе from-mail примут за номер.
    Route::get('/offers/from-mail', [MailController::class, 'fromMail']);
    Route::post('/offers/from-mail/{candidate}/create', [MailController::class, 'promote']);
    Route::post('/offers/from-mail/{candidate}/decline', [MailController::class, 'decline']);
    Route::get('/offers/{offer}', [OfferController::class, 'edit'])->name('crm.offers.edit');
    Route::get('/offers/{offer}/peek', [OfferController::class, 'peek']);
    Route::get('/work/invoices/new', [InvoiceController::class, 'create']);
    Route::post('/work/invoices', [InvoiceController::class, 'store']);
    // Шеринг PDF: маршруты без домена на хосте CRM отбивает ResolveSurface — свои копии.
    Route::match(['get', 'post'], '/offers/{offer}/pdf', [ShareController::class, 'pdf']);
    Route::post('/share/error', [ShareController::class, 'report'])->middleware('throttle:30,1');
    Route::put('/offers/{offer}', [OfferController::class, 'update']);
    Route::post('/offers/{offer}/extend', [OfferController::class, 'extend']);
    Route::post('/offers/{offer}/state', [OfferController::class, 'state']);
    Route::post('/offers/{offer}/exit/{exit}', [RouteController::class, 'exit']);
    Route::post('/offers/{offer}/stage', [RouteController::class, 'place']);
    Route::post('/offers/{offer}/pickup', [RouteController::class, 'pickup']);
    Route::delete('/offers/{offer}/pickup', [RouteController::class, 'dropPickup']);

    Route::post('/offers/{offer}/media', [OfferPhotoController::class, 'store']);
    Route::post('/offers/{offer}/media/order', [OfferPhotoController::class, 'reorder']);
    Route::post('/offers/{offer}/media/{media}/hide', [OfferPhotoController::class, 'toggle']);
    Route::post('/offers/{offer}/media/{media}/rotate', [OfferPhotoController::class, 'rotate']);
    Route::delete('/offers/{offer}/media/{media}', [OfferPhotoController::class, 'destroy']);

    Route::post('/confirmations/{bid}/accept', [BidController::class, 'accept']);
    Route::post('/confirmations/{bid}/decline', [BidController::class, 'decline']);
    Route::post('/interests/{interest}', [InterestController::class, 'update']);

    // Работа: сделки, почта и чаты под одним заголовком.
    Route::redirect('/work', '/work/deals');
    Route::get('/work/deals', [DealController::class, 'index']);
    Route::get('/work/deals/{deal}', [DealController::class, 'show']);
    Route::post('/work/deals/{deal}/note', [DealController::class, 'note']);
    Route::put('/work/deals/{deal}/money', [DealController::class, 'money']);
    // Деньги по сделкам: заявки менеджеров об оплате, вознаграждения к выплате, счета; карточка счёта — общая со стоянкой.
    Route::get('/work/money', [MoneyController::class, 'index']);
    Route::get('/work/money/managers', [MoneyController::class, 'managers']);
    Route::post('/work/payments/{payment}/confirm', [MoneyController::class, 'confirm']);
    Route::post('/work/payments/{payment}/reject', [MoneyController::class, 'reject']);
    Route::prefix('work/money/invoices/{invoice}')->group(function () {
        Route::get('/', [MoneyController::class, 'show']);
        Route::get('/peek', [MoneyController::class, 'peek']);
        Route::put('/', [MoneyController::class, 'update']);
        Route::get('/pdf', [MoneyController::class, 'file']);
        Route::get('/print', [MoneyController::class, 'print']);
        Route::post('/payments', [MoneyController::class, 'pay']);
        Route::get('/payments/{payment}/slip', [MoneyController::class, 'slip']);
        Route::delete('/payments/{payment}', [MoneyController::class, 'unpay']);
        Route::post('/void', [MoneyController::class, 'void']);
    });
    Route::prefix('work/mail')->group(function () {
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
    Route::redirect('/purchases/{purchase}/offers', '/purchases/{purchase}', 301);
    Route::get('/purchases/{purchase}/{car}/peek', [PurchaseController::class, 'peek']);
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
    // Кабинет CRM = «Настройки»: корень — профиль (общий экран с сайтом), /account сюда же.
    Route::get('/settings', [ProfileController::class, 'profile']);
    Route::permanentRedirect('/account', '/settings');
    Route::prefix('settings')->group(function () {
        Route::get('/users', [UserController::class, 'index']);
        Route::get('/users/{user}', [UserController::class, 'show'])->whereNumber('user');
        Route::put('/users/{user}', [UserController::class, 'update']);
        Route::post('/users/{user}/access', [UserController::class, 'decide']);
        Route::delete('/users/{user}', [UserController::class, 'destroy']);
        Route::post('/users/{user}/password', [UserController::class, 'passwordLink']);
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

        Route::get('/templates', [MailTemplateController::class, 'index']);
        Route::get('/templates/new', [MailTemplateController::class, 'create']);
        Route::post('/templates', [MailTemplateController::class, 'store']);
        Route::get('/templates/{template}', [MailTemplateController::class, 'edit']);
        Route::put('/templates/{template}', [MailTemplateController::class, 'update']);
        Route::delete('/templates/{template}', [MailTemplateController::class, 'destroy']);
    });

    Route::get('/reference/vin', [ReferenceController::class, 'vin']);
    Route::get('/reference/brands', [ReferenceController::class, 'brands']);
    Route::get('/reference/models', [ReferenceController::class, 'models']);
    Route::post('/reference/brands', [ReferenceController::class, 'createBrand']);
    Route::post('/reference/models', [ReferenceController::class, 'createModel']);

    if (app()->isLocal()) {
        Route::view('/ui', 'admin.ui');
    }
});

// На хосте CRM ничего, кроме CRM и общих путей.
Route::domain(config('xcar.crm_host'))->group(function () {
    Route::fallback(fn () => abort(404));
});
