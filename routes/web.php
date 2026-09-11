<?php

use App\Http\Cabinet\DealController;
use App\Http\Cabinet\ListsController;
use App\Http\Cabinet\NotificationController;
use App\Http\Cabinet\ProfileController;
use App\Http\Live\FragmentController;
use App\Http\Site\BidController;
use App\Http\Site\CatalogController;
use App\Http\Site\FavoriteController;
use App\Http\Site\InterestController;
use App\Http\Site\OfferController;
use Illuminate\Support\Facades\Route;

Route::get('/', [CatalogController::class, 'index'])->name('home');
Route::get('/galereya', [CatalogController::class, 'gallery']);
Route::get('/offers/{offer}', [OfferController::class, 'show'])->name('offers.show');
Route::get('/offers/{offer}/card', [FragmentController::class, 'card']);

Route::middleware('auth')->group(function () {
    Route::post('/offers/{offer}/stavka', [BidController::class, 'store']);
    Route::post('/stavki/{bid}/otozvat', [BidController::class, 'withdraw']);
    Route::post('/offers/{offer}/interes', [InterestController::class, 'store']);
    Route::post('/offers/{offer}/izbrannoe', [FavoriteController::class, 'toggle']);
    Route::post('/offers/{offer}/pdf', [\App\Http\Site\ShareController::class, 'pdf']);
    Route::post('/offers/{offer}/chat', [\App\Http\Site\ChatController::class, 'open']);
    Route::get('/chaty/{chat}/soobshcheniya', [\App\Http\Site\ChatController::class, 'messages']);
    Route::post('/chaty/{chat}/soobshcheniya', [\App\Http\Site\ChatController::class, 'post']);
    Route::get('/chaty/{chat}/fayly/{file}', [\App\Http\Site\ChatController::class, 'file']);

    Route::get('/lk', [ProfileController::class, 'show'])->name('cabinet');
    Route::put('/lk', [ProfileController::class, 'update']);
    Route::get('/lk/izbrannoe', [ListsController::class, 'favorites']);
    Route::get('/lk/stavki', [ListsController::class, 'bids']);
    Route::get('/lk/interesy', [ListsController::class, 'interests']);

    Route::get('/lk/sdelki', [DealController::class, 'index']);
    Route::get('/lk/sdelki/{deal}', [DealController::class, 'show']);
    Route::post('/lk/sdelki/{deal}/otvet', [DealController::class, 'answer']);
    Route::post('/lk/sdelki/{deal}/fayly', [DealController::class, 'upload']);
    Route::delete('/lk/sdelki/{deal}/fayly/{media}', [DealController::class, 'removeFile']);

    Route::get('/lk/uvedomleniya', [NotificationController::class, 'index']);
    Route::post('/lk/uvedomleniya/prochitano', [NotificationController::class, 'readAll']);
    Route::put('/lk/uvedomleniya/nastroyki', [NotificationController::class, 'settings']);
    Route::get('/lk/uvedomleniya/{id}', [NotificationController::class, 'open']);

    Route::get('/live/badges', [FragmentController::class, 'badges']);

    Route::get('/zakupki', [\App\Http\Site\PurchaseController::class, 'index']);
    Route::get('/zakupki/{purchase}', [\App\Http\Site\PurchaseController::class, 'show']);
    Route::get('/zakupki/{purchase}/{car}', [\App\Http\Site\PurchaseController::class, 'car']);
    Route::post('/zakupki/{purchase}/{car}/cena', [\App\Http\Site\PurchaseController::class, 'offer']);
    Route::post('/zakupki/ceny/{offer}/otozvat', [\App\Http\Site\PurchaseController::class, 'withdraw']);
});


if (app()->isLocal()) {
    Route::view('/admin/ui', 'admin.ui');
    // На сервере медиатеку раздаёт Caddy; artisan serve этого не умеет.
    Route::get('/media/{path}', function (string $path) {
        $file = \Illuminate\Support\Facades\Storage::disk('media')->path($path);
        abort_unless(is_file($file), 404);

        return response()->file($file, ['Cache-Control' => 'public, max-age=3600']);
    })->where('path', '.*');
}
