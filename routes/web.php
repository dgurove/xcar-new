<?php

use App\Http\Cabinet\DealController;
use App\Http\Cabinet\ListsController;
use App\Http\Cabinet\NotificationController;
use App\Http\Cabinet\ProfileController;
use App\Http\Live\FragmentController;
use App\Http\Pwa\PushController;
use App\Http\Pwa\PwaController;
use App\Http\Site\BidController;
use App\Http\Site\CatalogController;
use App\Http\Site\ChatController;
use App\Http\Site\FavoriteController;
use App\Http\Site\InterestController;
use App\Http\Site\OfferController;
use App\Http\Site\PurchaseController;
use App\Http\Site\ShareController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::get('/manifest.webmanifest', [PwaController::class, 'manifest']);
Route::get('/offline', [PwaController::class, 'offline']);
Route::post('/push/podpiska', [PushController::class, 'store'])->middleware('auth');
Route::delete('/push/podpiska', [PushController::class, 'destroy'])->middleware('auth');

Route::get('/', [CatalogController::class, 'index'])->name('home');
Route::view('/voprosy', 'site.pages.voprosy');
Route::view('/kontakty', 'site.pages.kontakty');
Route::view('/obrabotka-dannyh', 'site.pages.obrabotka-dannyh');
Route::view('/soglashenie', 'site.pages.soglashenie');
Route::view('/soglasie', 'site.pages.soglasie');
Route::get('/galereya', [CatalogController::class, 'gallery']);
Route::get('/offers/{offer}', [OfferController::class, 'show'])->name('offers.show');
Route::get('/offers/{offer}/card', [FragmentController::class, 'card']);

Route::middleware('auth')->group(function () {
    Route::post('/offers/{offer}/stavka', [BidController::class, 'store']);
    Route::post('/stavki/{bid}/otozvat', [BidController::class, 'withdraw']);
    Route::post('/offers/{offer}/interes', [InterestController::class, 'store']);
    Route::post('/offers/{offer}/izbrannoe', [FavoriteController::class, 'toggle']);
    Route::post('/offers/{offer}/pdf', [ShareController::class, 'pdf']);
    Route::post('/offers/{offer}/chat', [ChatController::class, 'open']);
    Route::get('/chaty/{chat}/soobshcheniya', [ChatController::class, 'messages']);
    Route::post('/chaty/{chat}/soobshcheniya', [ChatController::class, 'post']);
    Route::get('/chaty/{chat}/fayly/{file}', [ChatController::class, 'file']);

    Route::get('/lk', [ProfileController::class, 'show'])->name('cabinet');
    Route::put('/lk', [ProfileController::class, 'update']);
    Route::get('/lk/profil', [ProfileController::class, 'profile']);
    Route::get('/lk/izbrannoe', [ListsController::class, 'favorites']);
    Route::get('/lk/stavki', [ListsController::class, 'bids']);
    Route::get('/lk/interesy', [ListsController::class, 'interests']);

    Route::get('/lk/sdelki', [DealController::class, 'index']);
    Route::get('/lk/sdelki/{deal}', [DealController::class, 'show']);
    Route::post('/lk/sdelki/{deal}/otvet', [DealController::class, 'answer']);
    Route::post('/lk/sdelki/{deal}/fayly', [DealController::class, 'upload']);
    Route::delete('/lk/sdelki/{deal}/fayly/{media}', [DealController::class, 'removeFile']);
    Route::get('/sdelki/fayly/{media}', [DealController::class, 'file']);

    Route::get('/lk/uvedomleniya', [NotificationController::class, 'index']);
    Route::get('/lk/uvedomleniya/svezhie', [NotificationController::class, 'latest']);
    Route::post('/lk/uvedomleniya/prochitano', [NotificationController::class, 'readAll']);
    Route::put('/lk/uvedomleniya/nastroyki', [NotificationController::class, 'settings']);
    Route::get('/lk/uvedomleniya/{id}', [NotificationController::class, 'open']);

    Route::get('/live/badges', [FragmentController::class, 'badges']);

    Route::get('/zakupki', [PurchaseController::class, 'index'])->middleware('purchases');
    Route::get('/zakupki/{purchase}', [PurchaseController::class, 'show'])->middleware('purchases');
    Route::get('/zakupki/{purchase}/{car}', [PurchaseController::class, 'car'])->middleware('purchases');
    Route::post('/zakupki/{purchase}/{car}/cena', [PurchaseController::class, 'offer'])->middleware('purchases');
    Route::post('/zakupki/ceny/{offer}/otozvat', [PurchaseController::class, 'withdraw'])->middleware('purchases');
});

if (app()->isLocal()) {
    Route::view('/admin/ui', 'admin.ui');
    // На сервере медиатеку раздаёт Caddy; artisan serve этого не умеет.
    Route::get('/media/{path}', function (string $path) {
        $file = Storage::disk('media')->path($path);
        abort_unless(is_file($file), 404);

        return response()->file($file, ['Cache-Control' => 'public, max-age=3600']);
    })->where('path', '.*');
}
