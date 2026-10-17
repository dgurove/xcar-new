<?php

use App\Http\Admin\ReferenceController;
use App\Http\Cabinet\InviteController;
use App\Http\Logistics\ClientController;
use App\Http\Logistics\ContractorController;
use App\Http\Logistics\DocumentController;
use App\Http\Logistics\MoneyController;
use App\Http\Logistics\OrderController;
use App\Http\Logistics\TransportController;
use App\Http\Logistics\TripController;
use App\Http\PeopleController;
use App\Http\SearchController;
use Illuminate\Support\Facades\Route;

// Logistics XCar (10.10.2026; вывоз целиком — с 17.10.2026) — свой хост того же приложения. Вход общий; пускает
// `canLogistics` (админ, логист, наши люди, исполнители логиста, менеджеры с поручениями, модератор — документы).
// Лендинга нет: `/` — в вывоз (модератору — в документы).
Route::domain(config('xcar.logistics_host'))->get('/', fn () => redirect(auth()->user()?->isModerator() && ! auth()->user()->isAdmin() ? '/documents' : '/transports'));

Route::domain(config('xcar.logistics_host'))->middleware(['auth', 'ability:canLogistics'])->group(function () {
    Route::get('/search', SearchController::class);
    // «+ Новый исполнитель / заказчик / логист / сотрудник» в шторке выбора (18.10.2026).
    Route::post('/people', [PeopleController::class, 'store']);
    Route::get('/transports', [TransportController::class, 'index']);
    // Заявки логиста от своих заказчиков (18.10.2026): форма, правка, отметки денег.
    Route::get('/transports/new', [OrderController::class, 'create']);
    Route::post('/transports', [OrderController::class, 'store']);
    Route::get('/transports/{transport}/edit', [OrderController::class, 'edit'])->whereNumber('transport');
    Route::put('/transports/{transport}', [OrderController::class, 'update'])->whereNumber('transport');
    Route::post('/transports/{transport}/paid', [OrderController::class, 'paid'])->whereNumber('transport');
    Route::get('/clients', [ClientController::class, 'index']);
    Route::post('/clients', [ClientController::class, 'store']);
    Route::put('/clients/{client}', [ClientController::class, 'update'])->whereNumber('client');
    Route::post('/clients/{client}/archive', [ClientController::class, 'archive'])->whereNumber('client');
    Route::get('/reference/clients', [ClientController::class, 'search']);
    Route::post('/reference/clients', [ClientController::class, 'quick']);
    // Справочник машин для формы заявки — тот же, что в CRM и на парковке.
    Route::post('/reference/car-text', [ReferenceController::class, 'carText']);
    Route::get('/reference/brands', [ReferenceController::class, 'brands']);
    Route::post('/reference/brands', [ReferenceController::class, 'createBrand']);
    Route::get('/reference/models', [ReferenceController::class, 'models']);
    Route::post('/reference/models', [ReferenceController::class, 'createModel']);
    Route::get('/transports/{transport}', [TransportController::class, 'show'])->whereNumber('transport');
    Route::put('/transports/executor', [TransportController::class, 'executorMany']);
    Route::post('/transports/cancel', [TransportController::class, 'cancelMany']);
    Route::put('/transports/backdate', [TransportController::class, 'backdateMany']);
    Route::put('/transports/{transport}/executor', [TransportController::class, 'executor']);
    Route::post('/transports/{transport}/cdek', [TransportController::class, 'cdek']);
    Route::put('/transports/{transport}/logist', [TransportController::class, 'logist']);
    Route::post('/transports/{transport}/call', [TransportController::class, 'call']);
    Route::post('/transports/{transport}/missed', [TransportController::class, 'missed']);
    Route::post('/transports/{transport}/docs', [TransportController::class, 'docs']);
    Route::post('/transports/{transport}/docs/received', [TransportController::class, 'received']);
    Route::post('/transports/{transport}/media', [TransportController::class, 'upload']);
    Route::post('/transports/{transport}/media/order', [TransportController::class, 'order']);
    Route::post('/transports/{transport}/media/{media}/rotate', [TransportController::class, 'rotate']);
    Route::delete('/transports/{transport}/media/{media}', [TransportController::class, 'destroyMedia']);
    Route::post('/transports/{transport}/pickup', [TransportController::class, 'pickup']);
    Route::post('/transports/{transport}/deliver', [TransportController::class, 'deliver']);
    Route::post('/transports/{transport}/cancel', [TransportController::class, 'cancel']);
    Route::post('/transports/{transport}/backdate', [TransportController::class, 'backdate']);
    Route::get('/documents', [DocumentController::class, 'index']);

    Route::middleware('ability:managesContractors')->group(function () {
        Route::get('/contractors', [ContractorController::class, 'index']);
        Route::post('/contractors', [ContractorController::class, 'store']);
        Route::post('/contractors/invites', [ContractorController::class, 'invite']);
        Route::post('/contractors/{user}/password', [ContractorController::class, 'password'])->whereNumber('user');
        Route::post('/contractors/invites/{invite}/off', [InviteController::class, 'disable']);
        Route::post('/contractors/invites/{invite}/on', [InviteController::class, 'enable']);
        Route::put('/contractors/{user}', [ContractorController::class, 'update'])->whereNumber('user');
        Route::post('/contractors/{user}/revoke', [ContractorController::class, 'revoke'])->whereNumber('user');
        Route::get('/money', [MoneyController::class, 'index']);
        Route::put('/money/{user}/party', [MoneyController::class, 'party'])->whereNumber('user');
        Route::post('/money/paid', [MoneyController::class, 'paid']);
    });
});

// Страница задачи для исполнителя без входа (18.10.2026): ссылка из «Отправить исполнителю».
Route::domain(config('xcar.logistics_host'))->prefix('/trip/{code}')->middleware('throttle:60,1')->group(function () {
    Route::get('/', [TripController::class, 'show']);
    Route::post('/call', [TripController::class, 'call']);
    Route::post('/missed', [TripController::class, 'missed']);
    Route::post('/received', [TripController::class, 'received']);
    Route::post('/pickup', [TripController::class, 'pickup']);
    Route::post('/deliver', [TripController::class, 'deliver']);
    Route::post('/media', [TripController::class, 'upload']);
    Route::post('/media/order', [TripController::class, 'order']);
    Route::post('/media/{media}/rotate', [TripController::class, 'rotate']);
    Route::delete('/media/{media}', [TripController::class, 'destroyMedia']);
});

// На хосте логистики ничего, кроме логистики и входа.
Route::domain(config('xcar.logistics_host'))->group(function () {
    Route::fallback(fn () => abort(404));
});
