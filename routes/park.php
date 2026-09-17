<?php

use App\Http\Admin\ReferenceController;
use App\Http\Park\ActController;
use App\Http\Park\ClientController;
use App\Http\Park\MailController;
use App\Http\Park\ParkCandidateController;
use App\Http\Park\RequestController;
use App\Http\Park\VehicleController;
use App\Http\Park\YardController;
use Illuminate\Support\Facades\Route;

// Стоянка — свой хост того же приложения. Вход общий; пускает раздел «park».
Route::domain(config('xcar.park_host'))->middleware(['auth', 'section:park'])->group(function () {
    Route::get('/', [RequestController::class, 'index']);
    Route::get('/requests', fn () => redirect('/'.(request()->getQueryString() ? '?'.request()->getQueryString() : ''), 301));

    Route::get('/requests/new', [RequestController::class, 'create']);
    Route::get('/requests/from-mail', [ParkCandidateController::class, 'index']);
    Route::post('/requests/from-mail/{candidate}/create', [ParkCandidateController::class, 'promote']);
    Route::post('/requests/from-mail/{candidate}/decline', [ParkCandidateController::class, 'reject']);
    Route::post('/requests', [RequestController::class, 'store']);
    Route::get('/requests/{zayavka}', [RequestController::class, 'show']);
    Route::post('/requests/{zayavka}/intake', [RequestController::class, 'intake']);
    Route::post('/requests/{zayavka}/move', [RequestController::class, 'move']);
    Route::post('/requests/{zayavka}/release', [RequestController::class, 'release']);
    Route::post('/requests/{zayavka}/close', [RequestController::class, 'close']);

    Route::get('/cars', [VehicleController::class, 'index']);
    Route::get('/cars/{vehicle}', [VehicleController::class, 'show']);
    Route::get('/cars/{vehicle}/peek', [VehicleController::class, 'peek']);
    Route::put('/cars/{vehicle}', [VehicleController::class, 'update']);
    Route::post('/cars/{vehicle}/media', [VehicleController::class, 'upload']);
    Route::post('/cars/{vehicle}/media/order', [VehicleController::class, 'reorder']);
    Route::post('/cars/{vehicle}/media/{media}/rotate', [VehicleController::class, 'rotateMedia']);
    Route::delete('/cars/{vehicle}/media/{media}', [VehicleController::class, 'destroyMedia']);
    Route::post('/cars/{vehicle}/note', [VehicleController::class, 'note']);
    Route::post('/cars/{vehicle}/move', [VehicleController::class, 'move']);
    Route::post('/cars/{vehicle}/release', [VehicleController::class, 'release']);
    Route::get('/reference/cars', [VehicleController::class, 'suggest']);
    Route::get('/reference/vin', [ReferenceController::class, 'vin']);
    Route::get('/reference/brands', [ReferenceController::class, 'brands']);
    Route::get('/reference/models', [ReferenceController::class, 'models']);
    Route::post('/reference/brands', [ReferenceController::class, 'createBrand']);
    Route::post('/reference/models', [ReferenceController::class, 'createModel']);
    Route::get('/acts/{vehicle}/{kind}', [ActController::class, 'show'])->where('kind', 'intake|release');

    Route::get('/yards', [YardController::class, 'index']);
    Route::post('/yards', [YardController::class, 'store']);
    Route::put('/yards/{yard}', [YardController::class, 'update']);
    Route::get('/clients', [ClientController::class, 'index']);
    Route::post('/clients', [ClientController::class, 'store']);
    Route::put('/clients/{client}', [ClientController::class, 'update']);

    Route::get('/mail', [MailController::class, 'index']);
    Route::get('/mail/new', [MailController::class, 'compose']);
    Route::post('/mail', [MailController::class, 'send']);
    Route::post('/mail/file', [MailController::class, 'file']);
    Route::post('/mail/sync', [MailController::class, 'sync']);
    Route::get('/mail/attachments/{attachment}', [MailController::class, 'attachment']);
    Route::post('/mail/messages/{message}/flag', [MailController::class, 'flag']);
    Route::post('/mail/messages/{message}/parse', [MailController::class, 'reparse']);
    Route::post('/mail/messages/{message}/retry', [MailController::class, 'resend']);
    Route::get('/mail/{thread}', [MailController::class, 'show']);
    Route::get('/mail/{thread}/reply/{message}', [MailController::class, 'reply']);
    Route::post('/mail/{thread}/unread', [MailController::class, 'unread']);
    Route::post('/mail/{thread}/link', [MailController::class, 'link']);
});

// На хосте стоянки ничего, кроме стоянки и входа.
Route::domain(config('xcar.park_host'))->group(function () {
    Route::fallback(fn () => abort(404));
});
