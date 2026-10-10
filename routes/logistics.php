<?php

use App\Http\Logistics\TransportController;
use App\Http\SearchController;
use Illuminate\Support\Facades\Route;

// Logistics XCar (10.10.2026) — свой хост того же приложения. Вход общий; пускает `canLogistics` (админ, логист,
// водитель, партнёр). Лендинга нет: `/` — в перевозки.
Route::domain(config('xcar.logistics_host'))->get('/', fn () => redirect('/transports'));

Route::domain(config('xcar.logistics_host'))->middleware(['auth', 'ability:canLogistics'])->group(function () {
    Route::get('/search', SearchController::class);
    Route::get('/transports', [TransportController::class, 'index']);
    Route::get('/transports/{transport}', [TransportController::class, 'show'])->whereNumber('transport');
    Route::put('/transports/{transport}', [TransportController::class, 'assign']);
    Route::post('/transports/{transport}/pickup', [TransportController::class, 'pickup']);
    Route::post('/transports/{transport}/deliver', [TransportController::class, 'deliver']);
    Route::post('/transports/{transport}/cancel', [TransportController::class, 'cancel']);
});

// На хосте логистики ничего, кроме логистики и входа.
Route::domain(config('xcar.logistics_host'))->group(function () {
    Route::fallback(fn () => abort(404));
});
