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
    Route::get('/predlozheniya', fn () => redirect('/'.(request()->getQueryString() ? '?'.request()->getQueryString() : ''), 301));
    Route::post('/predlozheniya', [OfferController::class, 'store']);
    // Из писем: кандидаты в предложения — раньше карточки, иначе iz-pisem примут за номер.
    Route::get('/predlozheniya/iz-pisem', [CandidateController::class, 'index']);
    Route::post('/predlozheniya/iz-pisem/{candidate}/zavesti', [CandidateController::class, 'promote']);
    Route::post('/predlozheniya/iz-pisem/{candidate}/otklonit', [CandidateController::class, 'reject']);
    Route::get('/predlozheniya/{offer}', [OfferController::class, 'edit'])->name('crm.offers.edit');
    // Шеринг PDF: маршруты без домена на хосте CRM отбивает ResolveSurface — свои копии.
    Route::match(['get', 'post'], '/offers/{offer}/pdf', [ShareController::class, 'pdf']);
    Route::post('/share/oshibka', [ShareController::class, 'report'])->middleware('throttle:30,1');
    Route::put('/predlozheniya/{offer}', [OfferController::class, 'update']);
    Route::post('/predlozheniya/{offer}/sostoyanie', [OfferController::class, 'state']);
    Route::post('/predlozheniya/{offer}/iskhod/{exit}', [RouteController::class, 'exit']);
    Route::post('/predlozheniya/{offer}/etap', [RouteController::class, 'place']);
    Route::post('/predlozheniya/{offer}/vyvoz', [RouteController::class, 'pickup']);
    Route::delete('/predlozheniya/{offer}/vyvoz', [RouteController::class, 'dropPickup']);

    Route::post('/predlozheniya/{offer}/media', [OfferPhotoController::class, 'store']);
    Route::post('/predlozheniya/{offer}/media/poryadok', [OfferPhotoController::class, 'reorder']);
    Route::post('/predlozheniya/{offer}/media/{media}/skryt', [OfferPhotoController::class, 'toggle']);
    Route::post('/predlozheniya/{offer}/media/{media}/povernut', [OfferPhotoController::class, 'rotate']);
    Route::delete('/predlozheniya/{offer}/media/{media}', [OfferPhotoController::class, 'destroy']);

    Route::post('/stavki/{bid}/prinyat', [BidController::class, 'accept']);
    Route::post('/stavki/{bid}/otklonit', [BidController::class, 'decline']);
    Route::post('/interesy/{interest}', [InterestController::class, 'update']);

    // Работа: сделки, почта и чаты под одним заголовком.
    Route::redirect('/rabota', '/rabota/sdelki');
    Route::get('/rabota/sdelki', [DealController::class, 'index']);
    Route::get('/rabota/sdelki/{deal}', [DealController::class, 'show']);
    Route::post('/rabota/sdelki/{deal}/zametka', [DealController::class, 'note']);
    Route::prefix('rabota/pochta')->group(function () {
        Route::get('/', [MailController::class, 'index']);
        Route::get('/novoe', [MailController::class, 'compose']);
        Route::post('/', [MailController::class, 'send']);
        Route::post('/fayl', [MailController::class, 'file']);
        Route::post('/sinhronizaciya', [MailController::class, 'sync']);
        Route::get('/vlozheniya/{attachment}', [MailController::class, 'attachment']);
        Route::post('/pisma/{message}/flag', [MailController::class, 'flag']);
        Route::post('/pisma/{message}/razbor', [MailController::class, 'reparse']);
        Route::post('/pisma/{message}/snova', [MailController::class, 'resend']);
        Route::get('/{thread}', [MailController::class, 'show']);
        Route::get('/{thread}/otvet/{message}', [MailController::class, 'reply']);
        Route::post('/{thread}/neprochitano', [MailController::class, 'unread']);
        Route::post('/{thread}/prochitano', [MailController::class, 'toggleRead']);
        Route::post('/{thread}/privyazka', [MailController::class, 'link']);
    });
    Route::get('/rabota/chaty', [ChatController::class, 'index']);
    Route::get('/rabota/chaty/{chat}', [ChatController::class, 'show']);

    Route::get('/galereya', [GalleryController::class, 'index']);
    Route::post('/galereya', [GalleryController::class, 'store']);
    // Старые адреса разделов — закладки и ссылки из уведомлений.
    Route::get('/sdelki', fn () => redirect('/rabota/sdelki', 301));
    Route::get('/perepiski/{path?}', fn (?string $path = null) => redirect('/rabota'.($path ? "/{$path}" : '').(request()->getQueryString() ? '?'.request()->getQueryString() : ''), 301))->where('path', '.*');

    Route::get('/zakupki', [PurchaseController::class, 'index']);
    Route::post('/zakupki', [PurchaseController::class, 'store']);
    Route::get('/zakupki/ogranicheniya', [PurchaseController::class, 'restrictions']);
    Route::post('/zakupki/ogranicheniya/{user}', [PurchaseController::class, 'restrict']);
    Route::post('/zakupki/ceny/{offer}/vybrat', [PurchaseController::class, 'choose']);
    Route::post('/zakupki/ceny/{offer}/otmenit', [PurchaseController::class, 'unchoose']);
    Route::get('/zakupki/{purchase}', [PurchaseController::class, 'show']);
    Route::put('/zakupki/{purchase}', [PurchaseController::class, 'update']);
    Route::post('/zakupki/{purchase}/sostoyanie', [PurchaseController::class, 'state']);
    Route::post('/zakupki/{purchase}/prodlit', [PurchaseController::class, 'extend']);
    Route::post('/zakupki/{purchase}/fayl', [PurchaseController::class, 'upload']);
    Route::get('/zakupki/{purchase}/import', [PurchaseController::class, 'preview']);
    Route::post('/zakupki/{purchase}/import', [PurchaseController::class, 'import']);
    Route::get('/zakupki/{purchase}/xlsx', [PurchaseController::class, 'export']);
    Route::redirect('/zakupki/{purchase}/predlozheniya', '/zakupki/{purchase}?view=managers', 301);
    Route::get('/zakupki/{purchase}/predlozheniya/fayl', [PurchaseController::class, 'offersExport']);
    Route::post('/zakupki/{purchase}/zanovo', [PurchaseController::class, 'refetch']);
    Route::get('/zakupki/{purchase}/{car}', [PurchaseController::class, 'car']);
    Route::match(['get', 'post'], '/zakupki/{purchase}/{car}/pdf', [ShareController::class, 'carPdf']);
    Route::put('/zakupki/{purchase}/{car}', [PurchaseController::class, 'updateCar']);
    Route::get('/zakupki/{purchase}/{car}/ocenka', [PurchaseController::class, 'price']);
    Route::post('/zakupki/{purchase}/{car}/ocenka', [PurchaseController::class, 'priceStore']);
    Route::post('/zakupki/{purchase}/{car}/zabrat', [PurchaseController::class, 'fetch']);
    Route::post('/zakupki/mashiny/{car}/media', [PurchaseCarPhotoController::class, 'store']);
    Route::post('/zakupki/mashiny/{car}/media/poryadok', [PurchaseCarPhotoController::class, 'reorder']);
    Route::post('/zakupki/mashiny/{car}/media/{media}/skryt', [PurchaseCarPhotoController::class, 'toggle']);
    Route::post('/zakupki/mashiny/{car}/media/{media}/povernut', [PurchaseCarPhotoController::class, 'rotate']);
    Route::delete('/zakupki/mashiny/{car}/media/{media}', [PurchaseCarPhotoController::class, 'destroy']);

    // Настройки: справочное, что меняется редко.
    Route::get('/nastroyki', [SettingsController::class, 'index']);
    Route::prefix('nastroyki')->group(function () {
        Route::get('/polzovateli', [UserController::class, 'index']);
        Route::post('/polzovateli', [UserController::class, 'store']);
        Route::put('/polzovateli/{user}', [UserController::class, 'update']);
        Route::post('/polzovateli/{user}/dostup', [UserController::class, 'decide']);

        Route::get('/tegi', [TagController::class, 'index']);
        Route::post('/tegi', [TagController::class, 'store']);
        Route::post('/tegi/poryadok', [TagController::class, 'reorder']);
        Route::put('/tegi/{tag}', [TagController::class, 'update']);
        Route::delete('/tegi/{tag}', [TagController::class, 'destroy']);

        Route::get('/strahovye', [InsurerController::class, 'index']);
        Route::post('/strahovye', [InsurerController::class, 'store']);
        Route::get('/strahovye/{insurer}', [InsurerController::class, 'show']);
        Route::put('/strahovye/{insurer}', [InsurerController::class, 'update']);
        Route::delete('/strahovye/{insurer}', [InsurerController::class, 'destroy']);

        Route::post('/marshruty/{workflow}/vklyuchit', [WorkflowController::class, 'activate']);
        Route::post('/marshruty/{workflow}/zapusk', [WorkflowController::class, 'autoStart']);
        Route::post('/marshruty/{workflow}/zapolnit', [WorkflowController::class, 'fill']);
        Route::post('/marshruty/{workflow}/poryadok', [WorkflowController::class, 'reorder']);
        Route::post('/marshruty/{workflow}/bloki', [WorkflowController::class, 'storeBlock']);
        Route::put('/marshruty/bloki/{block}', [WorkflowController::class, 'updateBlock']);
        Route::delete('/marshruty/bloki/{block}', [WorkflowController::class, 'destroyBlock']);
        Route::get('/marshruty/{workflow}/etapy/novyy', [WorkflowController::class, 'createStage']);
        Route::post('/marshruty/{workflow}/etapy', [WorkflowController::class, 'storeStage']);
        Route::get('/marshruty/etapy/{stage}', [WorkflowController::class, 'editStage']);
        Route::put('/marshruty/etapy/{stage}', [WorkflowController::class, 'updateStage']);
        Route::delete('/marshruty/etapy/{stage}', [WorkflowController::class, 'destroyStage']);

        Route::get('/yashchiki', [MailAccountController::class, 'index']);
        Route::get('/yashchiki/novyy', [MailAccountController::class, 'create']);
        Route::post('/yashchiki', [MailAccountController::class, 'store']);
        Route::get('/yashchiki/{account}', [MailAccountController::class, 'edit']);
        Route::put('/yashchiki/{account}', [MailAccountController::class, 'update']);
        Route::delete('/yashchiki/{account}', [MailAccountController::class, 'destroy']);
        Route::post('/yashchiki/{account}/proverka', [MailAccountController::class, 'test']);
        Route::post('/yashchiki/{account}/sinhronizaciya', [MailAccountController::class, 'sync']);

        Route::get('/shablony', [MailTemplateController::class, 'index']);
        Route::get('/shablony/novyy', [MailTemplateController::class, 'create']);
        Route::post('/shablony', [MailTemplateController::class, 'store']);
        Route::get('/shablony/{template}', [MailTemplateController::class, 'edit']);
        Route::put('/shablony/{template}', [MailTemplateController::class, 'update']);
        Route::delete('/shablony/{template}', [MailTemplateController::class, 'destroy']);
    });

    Route::get('/spravochnik/vin', [ReferenceController::class, 'vin']);
    Route::get('/spravochnik/marki', [ReferenceController::class, 'brands']);
    Route::get('/spravochnik/modeli', [ReferenceController::class, 'models']);
    Route::post('/spravochnik/marki', [ReferenceController::class, 'createBrand']);
    Route::post('/spravochnik/modeli', [ReferenceController::class, 'createModel']);

    if (app()->isLocal()) {
        Route::view('/ui', 'admin.ui');
    }
});

// На хосте CRM ничего, кроме CRM и общих путей.
Route::domain(config('xcar.crm_host'))->group(function () {
    Route::fallback(fn () => abort(404));
});
