<?php

use App\Http\Garage\CarController;
use Illuminate\Support\Facades\Route;

// Гараж — свой хост того же приложения: машины, выведенные из продажи, и расходы по ним.
// Вход общий; внутрь пускает ResolveSurface (сотрудники и менеджеры).
Route::domain(config('xcar.garage_host'))->middleware('auth')->group(function () {
    Route::get('/', [CarController::class, 'index']);
});

// На хосте гаража ничего, кроме гаража и входа.
Route::domain(config('xcar.garage_host'))->group(function () {
    Route::fallback(fn () => abort(404));
});
