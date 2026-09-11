<?php

use App\Http\Admin\BidController;
use App\Http\Admin\DealController;
use App\Http\Admin\InsurerController;
use App\Http\Admin\InterestController;
use App\Http\Admin\OfferController;
use App\Http\Admin\OfferPhotoController;
use App\Http\Admin\ReferenceController;
use App\Http\Admin\RouteController;
use App\Http\Admin\WorkflowController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')->middleware(['auth', 'staff'])->group(function () {
    Route::get('/offers', [OfferController::class, 'index'])->name('admin.offers');
    Route::post('/offers', [OfferController::class, 'store']);
    Route::get('/offers/{offer}', [OfferController::class, 'edit'])->name('admin.offers.edit');
    Route::put('/offers/{offer}', [OfferController::class, 'update']);
    Route::post('/offers/{offer}/sostoyanie', [OfferController::class, 'state']);
    Route::post('/offers/{offer}/iskhod/{exit}', [RouteController::class, 'exit']);
    Route::post('/offers/{offer}/etap', [RouteController::class, 'place']);

    Route::post('/offers/{offer}/media', [OfferPhotoController::class, 'store']);
    Route::post('/offers/{offer}/media/poryadok', [OfferPhotoController::class, 'reorder']);
    Route::post('/offers/{offer}/media/{media}/skryt', [OfferPhotoController::class, 'toggle']);
    Route::post('/offers/{offer}/media/{media}/glavnoe', [OfferPhotoController::class, 'main']);
    Route::delete('/offers/{offer}/media/{media}', [OfferPhotoController::class, 'destroy']);

    Route::post('/stavki/{bid}/prinyat', [BidController::class, 'accept']);
    Route::post('/stavki/{bid}/otklonit', [BidController::class, 'decline']);
    Route::post('/interesy/{interest}', [InterestController::class, 'update']);

    Route::view('/eshchyo', 'admin.more');
    Route::get('/sdelki', [DealController::class, 'index']);
    Route::post('/sdelki/{deal}/zametka', [DealController::class, 'note']);

    Route::get('/strahovye', [InsurerController::class, 'index']);
    Route::post('/strahovye', [InsurerController::class, 'store']);
    Route::get('/strahovye/{insurer}', [InsurerController::class, 'show']);
    Route::put('/strahovye/{insurer}', [InsurerController::class, 'update']);
    Route::delete('/strahovye/{insurer}', [InsurerController::class, 'destroy']);

    Route::post('/marshruty/{workflow}/vklyuchit', [WorkflowController::class, 'activate']);
    Route::post('/marshruty/{workflow}/tipovoy', [WorkflowController::class, 'typical']);
    Route::post('/marshruty/{workflow}/poryadok', [WorkflowController::class, 'reorder']);
    Route::post('/marshruty/{workflow}/bloki', [WorkflowController::class, 'storeBlock']);
    Route::put('/marshruty/bloki/{block}', [WorkflowController::class, 'updateBlock']);
    Route::delete('/marshruty/bloki/{block}', [WorkflowController::class, 'destroyBlock']);
    Route::get('/marshruty/{workflow}/etapy/novyy', [WorkflowController::class, 'createStage']);
    Route::post('/marshruty/{workflow}/etapy', [WorkflowController::class, 'storeStage']);
    Route::get('/marshruty/etapy/{stage}', [WorkflowController::class, 'editStage']);
    Route::put('/marshruty/etapy/{stage}', [WorkflowController::class, 'updateStage']);
    Route::delete('/marshruty/etapy/{stage}', [WorkflowController::class, 'destroyStage']);

    Route::get('/spravochnik/marki', [ReferenceController::class, 'brands']);
    Route::get('/spravochnik/modeli', [ReferenceController::class, 'models']);
    Route::post('/spravochnik/marki', [ReferenceController::class, 'createBrand']);
    Route::post('/spravochnik/modeli', [ReferenceController::class, 'createModel']);
});
