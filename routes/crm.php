<?php

use App\Http\Admin\BidController;
use App\Http\Admin\CandidateController;
use App\Http\Admin\ChatController;
use App\Http\Admin\DealController;
use App\Http\Admin\GalleryController;
use App\Http\Admin\InsurerController;
use App\Http\Admin\InterestController;
use App\Http\Admin\MailAccountController;
use App\Http\Admin\MailController;
use App\Http\Admin\MailTemplateController;
use App\Http\Admin\OfferController;
use App\Http\Admin\OfferPhotoController;
use App\Http\Admin\PurchaseCarPhotoController;
use App\Http\Admin\PurchaseController;
use App\Http\Admin\ReferenceController;
use App\Http\Admin\RouteController;
use App\Http\Admin\SettingsController;
use App\Http\Admin\TagController;
use App\Http\Admin\UserController;
use App\Http\Admin\WorkflowController;
use App\Http\Site\ShareController;
use Illuminate\Support\Facades\Route;

// CRM — свой хост того же приложения, только для сотрудников.
Route::domain(config('xcar.crm_host'))->middleware(['auth', 'staff'])->group(function () {
    Route::get('/', [OfferController::class, 'index'])->name('crm.offers');
    Route::get('/offers', fn () => redirect('/'.(request()->getQueryString() ? '?'.request()->getQueryString() : ''), 301));
    Route::post('/offers', [OfferController::class, 'store']);
    // Из писем: кандидаты в предложения — раньше карточки, иначе iz-pisem примут за номер.
    Route::get('/offers/from-mail', [CandidateController::class, 'index']);
    Route::post('/offers/from-mail/{candidate}/create', [CandidateController::class, 'promote']);
    Route::post('/offers/from-mail/{candidate}/decline', [CandidateController::class, 'reject']);
    Route::get('/offers/{offer}', [OfferController::class, 'edit'])->name('crm.offers.edit');
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
    Route::prefix('work/mail')->group(function () {
        Route::get('/', [MailController::class, 'index']);
        Route::get('/new', [MailController::class, 'compose']);
        Route::post('/', [MailController::class, 'send']);
        Route::post('/file', [MailController::class, 'file']);
        Route::post('/sync', [MailController::class, 'sync']);
        Route::get('/attachments/{attachment}', [MailController::class, 'attachment']);
        Route::post('/messages/{message}/flag', [MailController::class, 'flag']);
        Route::post('/messages/{message}/parse', [MailController::class, 'reparse']);
        Route::post('/messages/{message}/retry', [MailController::class, 'resend']);
        Route::get('/{thread}', [MailController::class, 'show']);
        Route::get('/{thread}/reply/{message}', [MailController::class, 'reply']);
        Route::post('/{thread}/unread', [MailController::class, 'unread']);
        Route::post('/{thread}/read', [MailController::class, 'toggleRead']);
        Route::post('/{thread}/link', [MailController::class, 'link']);
    });
    Route::get('/work/chats', [ChatController::class, 'index']);
    Route::get('/work/chats/{chat}', [ChatController::class, 'show']);

    Route::get('/gallery', [GalleryController::class, 'index']);
    Route::post('/gallery', [GalleryController::class, 'store']);
    // Старые адреса разделов — закладки и ссылки из уведомлений.
    Route::get('/deals', fn () => redirect('/work/deals', 301));
    Route::get('/chats/{path?}', fn (?string $path = null) => redirect('/work'.($path ? "/{$path}" : '').(request()->getQueryString() ? '?'.request()->getQueryString() : ''), 301))->where('path', '.*');

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
    Route::get('/purchases/{purchase}/{car}', [PurchaseController::class, 'car']);
    Route::match(['get', 'post'], '/purchases/{purchase}/{car}/pdf', [ShareController::class, 'carPdf']);
    Route::put('/purchases/{purchase}/{car}', [PurchaseController::class, 'updateCar']);
    Route::get('/purchases/{purchase}/{car}/estimate', [PurchaseController::class, 'price']);
    Route::post('/purchases/{purchase}/{car}/estimate', [PurchaseController::class, 'priceStore']);
    Route::post('/purchases/cars/{car}/media', [PurchaseCarPhotoController::class, 'store']);
    Route::post('/purchases/cars/{car}/media/order', [PurchaseCarPhotoController::class, 'reorder']);
    Route::post('/purchases/cars/{car}/media/{media}/hide', [PurchaseCarPhotoController::class, 'toggle']);
    Route::post('/purchases/cars/{car}/media/{media}/rotate', [PurchaseCarPhotoController::class, 'rotate']);
    Route::delete('/purchases/cars/{car}/media/{media}', [PurchaseCarPhotoController::class, 'destroy']);

    // Настройки: справочное, что меняется редко.
    Route::get('/settings', [SettingsController::class, 'index']);
    Route::prefix('settings')->group(function () {
        Route::get('/users', [UserController::class, 'index']);
        Route::put('/users/{user}', [UserController::class, 'update']);
        Route::post('/users/{user}/access', [UserController::class, 'decide']);
        Route::post('/users/{user}/password', [UserController::class, 'passwordLink']);
        Route::post('/users/invites', [\App\Http\Cabinet\InviteController::class, 'store']);
        Route::post('/users/invites/{invite}/off', [\App\Http\Cabinet\InviteController::class, 'disable']);
        Route::post('/users/invites/{invite}/on', [\App\Http\Cabinet\InviteController::class, 'enable']);

        Route::get('/tags', [TagController::class, 'index']);
        Route::post('/tags', [TagController::class, 'store']);
        Route::post('/tags/order', [TagController::class, 'reorder']);
        Route::put('/tags/{tag}', [TagController::class, 'update']);
        Route::delete('/tags/{tag}', [TagController::class, 'destroy']);

        Route::get('/insurers', [InsurerController::class, 'index']);
        Route::post('/insurers', [InsurerController::class, 'store']);
        Route::get('/insurers/{insurer}', [InsurerController::class, 'show']);
        Route::put('/insurers/{insurer}', [InsurerController::class, 'update']);
        Route::delete('/insurers/{insurer}', [InsurerController::class, 'destroy']);

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
