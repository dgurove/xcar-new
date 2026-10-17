<?php

use App\Http\Cabinet\InviteController;
use App\Http\Logistics\ContractorController;
use App\Http\Logistics\DocumentController;
use App\Http\Logistics\TransportController;
use App\Http\SearchController;
use Illuminate\Support\Facades\Route;

// Logistics XCar (10.10.2026; вывоз целиком — с 17.10.2026) — свой хост того же приложения. Вход общий; пускает
// `canLogistics` (админ, логист, наши люди, исполнители логиста, менеджеры с поручениями, модератор — документы).
// Лендинга нет: `/` — в вывоз (модератору — в документы).
Route::domain(config('xcar.logistics_host'))->get('/', fn () => redirect(auth()->user()?->isModerator() && ! auth()->user()->isAdmin() ? '/documents' : '/transports'));

Route::domain(config('xcar.logistics_host'))->middleware(['auth', 'ability:canLogistics'])->group(function () {
    Route::get('/search', SearchController::class);
    Route::get('/transports', [TransportController::class, 'index']);
    Route::get('/transports/{transport}', [TransportController::class, 'show'])->whereNumber('transport');
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
    Route::get('/documents', [DocumentController::class, 'index']);

    Route::middleware('ability:managesContractors')->group(function () {
        Route::get('/contractors', [ContractorController::class, 'index']);
        Route::post('/contractors/invites', [ContractorController::class, 'invite']);
        Route::post('/contractors/invites/{invite}/off', [InviteController::class, 'disable']);
        Route::post('/contractors/invites/{invite}/on', [InviteController::class, 'enable']);
        Route::put('/contractors/{user}', [ContractorController::class, 'update'])->whereNumber('user');
        Route::post('/contractors/{user}/revoke', [ContractorController::class, 'revoke'])->whereNumber('user');
    });
});

// На хосте логистики ничего, кроме логистики и входа.
Route::domain(config('xcar.logistics_host'))->group(function () {
    Route::fallback(fn () => abort(404));
});
