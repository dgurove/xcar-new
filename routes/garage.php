<?php

use App\Http\Garage\CarController;
use App\Http\Garage\SettlementController;
use Illuminate\Support\Facades\Route;

// Гараж — свой хост того же приложения: машины, выведенные из продажи, и расходы по ним.
// Вход общий; внутрь пускает ResolveSurface (сотрудники и менеджеры), свои машины — контроллер.
Route::domain(config('xcar.garage_host'))->middleware('auth')->group(function () {
    Route::get('/', [CarController::class, 'index']);
    Route::get('/cars/{offer}', [CarController::class, 'show']);
    Route::post('/cars/{offer}/costs', [CarController::class, 'storeCost']);
    Route::delete('/cars/{offer}', [CarController::class, 'destroy']);
    Route::put('/costs/{cost}', [CarController::class, 'updateCost']);
    Route::delete('/costs/{cost}', [CarController::class, 'destroyCost']);

    // Расчёт: итог продажи и счёт менеджеру вносим мы, об оплате сообщает он.
    // «Расчёты» слились со списком машин: там группы по этапу.
    Route::get('/money', fn () => redirect('/', 301));
    Route::post('/cars/{offer}/sold', [SettlementController::class, 'sold']);
    Route::delete('/cars/{offer}/sold', [SettlementController::class, 'unsold']);
    Route::post('/cars/{offer}/settle', [SettlementController::class, 'settle']);
    Route::post('/cars/{offer}/payments', [SettlementController::class, 'pay']);
    Route::post('/cars/{offer}/claims', [SettlementController::class, 'claim']);
    Route::get('/cars/{offer}/invoice/pdf', [SettlementController::class, 'pdf']);
    Route::delete('/cars/{offer}/invoice', [SettlementController::class, 'voidInvoice']);
});

// На хосте гаража ничего, кроме гаража и входа.
Route::domain(config('xcar.garage_host'))->group(function () {
    Route::fallback(fn () => abort(404));
});
