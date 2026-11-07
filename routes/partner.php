<?php

use App\Http\Admin\ReferenceController;
use App\Http\Partner\MediaController;
use App\Http\Partner\OfferController;
use Illuminate\Support\Facades\Route;

// Partner XCar (07.11.2026) — поставщики: сотрудники страховых заводят черновики своего вендора и вписывают цены. Свой
// хост того же приложения; пускает только поставщика (`Surface::opensFor`), всё, что не объявлено здесь, — 404, общие пути
// урезаны (`ResolveSurface::PARTNER_SHARED`). Что открыто — одна дверь `Offers\Partner\PartnerOffers`.
Route::domain(config('xcar.partner_host'))->get('/', fn () => redirect('/offers'));

Route::domain(config('xcar.partner_host'))->middleware(['auth', 'ability:isSupplier'])->group(function () {
    Route::get('/offers', [OfferController::class, 'index']);
    // Номером не прощупать нашу базу: не чаще 30 раз в минуту.
    Route::post('/offers/find', [OfferController::class, 'find'])->middleware('throttle:30,1');
    Route::get('/offers/{offer:id}', [OfferController::class, 'edit'])->whereNumber('offer');
    Route::put('/offers/{offer:id}', [OfferController::class, 'update'])->whereNumber('offer');
    Route::delete('/offers/{offer:id}', [OfferController::class, 'destroy'])->whereNumber('offer');
    // Фото и документы: загрузка, порядок, удаление своих; отдаются только своими адресами, с нейтральным именем.
    Route::post('/offers/{offer:id}/media', [MediaController::class, 'store'])->whereNumber('offer');
    Route::post('/offers/{offer:id}/media/order', [MediaController::class, 'order'])->whereNumber('offer');
    Route::delete('/offers/{offer:id}/media/{media}', [MediaController::class, 'destroy'])->whereNumber(['offer', 'media']);
    Route::get('/photos/{media}/{size?}', [MediaController::class, 'photo'])->whereNumber('media')->whereIn('size', ['w320', 'w960']);
    Route::get('/files/{media}', [MediaController::class, 'file'])->whereNumber('media');
    // Справочники полей: марка, модель, город — те же, что в CRM (вендоров среди них нет).
    Route::get('/reference/brands', [ReferenceController::class, 'brands']);
    Route::get('/reference/models', [ReferenceController::class, 'models']);
    Route::get('/reference/settlements', [ReferenceController::class, 'settlements']);
    Route::post('/reference/brands', [ReferenceController::class, 'createBrand']);
    Route::post('/reference/models', [ReferenceController::class, 'createModel']);
});

// На хосте партнёра ничего, кроме его экранов и входа.
Route::domain(config('xcar.partner_host'))->group(function () {
    Route::fallback(fn () => abort(404));
});
