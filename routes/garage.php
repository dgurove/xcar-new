<?php

use App\Http\Garage\CarController;
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
});

// На хосте гаража ничего, кроме гаража и входа.
Route::domain(config('xcar.garage_host'))->group(function () {
    Route::fallback(fn () => abort(404));
});
