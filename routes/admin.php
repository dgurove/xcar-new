<?php

use App\Http\Admin\BidController;
use App\Http\Admin\InterestController;
use App\Http\Admin\OfferController;
use App\Http\Admin\OfferPhotoController;
use App\Http\Admin\ReferenceController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')->middleware(['auth', 'staff'])->group(function () {
    Route::get('/offers', [OfferController::class, 'index'])->name('admin.offers');
    Route::post('/offers', [OfferController::class, 'store']);
    Route::get('/offers/{offer}', [OfferController::class, 'edit'])->name('admin.offers.edit');
    Route::put('/offers/{offer}', [OfferController::class, 'update']);
    Route::post('/offers/{offer}/sostoyanie', [OfferController::class, 'state']);

    Route::post('/offers/{offer}/media', [OfferPhotoController::class, 'store']);
    Route::post('/offers/{offer}/media/poryadok', [OfferPhotoController::class, 'reorder']);
    Route::post('/offers/{offer}/media/{media}/skryt', [OfferPhotoController::class, 'toggle']);
    Route::post('/offers/{offer}/media/{media}/glavnoe', [OfferPhotoController::class, 'main']);
    Route::delete('/offers/{offer}/media/{media}', [OfferPhotoController::class, 'destroy']);

    Route::post('/stavki/{bid}/prinyat', [BidController::class, 'accept']);
    Route::post('/stavki/{bid}/otklonit', [BidController::class, 'decline']);
    Route::post('/interesy/{interest}', [InterestController::class, 'update']);

    Route::get('/spravochnik/marki', [ReferenceController::class, 'brands']);
    Route::get('/spravochnik/modeli', [ReferenceController::class, 'models']);
    Route::post('/spravochnik/marki', [ReferenceController::class, 'createBrand']);
    Route::post('/spravochnik/modeli', [ReferenceController::class, 'createModel']);
});
