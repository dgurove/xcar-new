<?php

use App\Http\Admin\ReferenceController;
use App\Http\Park\ActController;
use App\Http\Park\ClientController;
use App\Http\Park\MailController;
use App\Http\Park\ParkCandidateController;
use App\Http\Park\RequestController;
use App\Http\Park\VehicleController;
use App\Http\Park\YardController;
use App\Park\Request;
use App\Park\RequestState;
use App\Park\Vehicle;
use App\Park\VehicleState;
use Illuminate\Support\Facades\Route;

// Стоянка — свой хост того же приложения. Вход общий; пускает раздел «park».
Route::domain(config('xcar.park_host'))->middleware(['auth', 'section:park'])->group(function () {
    Route::get('/', function () {
        return view('park.home', [
            'stored' => Vehicle::where('state', VehicleState::Stored)->count(),
            'expected' => Vehicle::where('state', VehicleState::Expected)->count(),
            'requests' => Request::where('state', RequestState::New)->count(),
            'fresh' => Request::with('vehicle')->where('state', RequestState::New)->orderByRaw('planned_at asc nulls last')->limit(6)->get(),
        ]);
    });
    Route::redirect('/eshchyo', '/lk', 301);
    Route::redirect('/kabinet', '/lk', 301);

    Route::get('/zayavki', [RequestController::class, 'index']);
    Route::get('/zayavki/novaya', [RequestController::class, 'create']);
    Route::get('/zayavki/iz-pisem', [ParkCandidateController::class, 'index']);
    Route::post('/zayavki/iz-pisem/{candidate}/zavesti', [ParkCandidateController::class, 'promote']);
    Route::post('/zayavki/iz-pisem/{candidate}/otklonit', [ParkCandidateController::class, 'reject']);
    Route::post('/zayavki', [RequestController::class, 'store']);
    Route::get('/zayavki/{zayavka}', [RequestController::class, 'show']);
    Route::post('/zayavki/{zayavka}/priem', [RequestController::class, 'intake']);
    Route::post('/zayavki/{zayavka}/perestanovka', [RequestController::class, 'move']);
    Route::post('/zayavki/{zayavka}/vydacha', [RequestController::class, 'release']);
    Route::post('/zayavki/{zayavka}/zakryt', [RequestController::class, 'close']);

    Route::get('/mashiny', [VehicleController::class, 'index']);
    Route::get('/mashiny/{vehicle}', [VehicleController::class, 'show']);
    Route::put('/mashiny/{vehicle}', [VehicleController::class, 'update']);
    Route::post('/mashiny/{vehicle}/media', [VehicleController::class, 'upload']);
    Route::post('/mashiny/{vehicle}/media/poryadok', [VehicleController::class, 'reorder']);
    Route::post('/mashiny/{vehicle}/media/{media}/povernut', [VehicleController::class, 'rotateMedia']);
    Route::delete('/mashiny/{vehicle}/media/{media}', [VehicleController::class, 'destroyMedia']);
    Route::post('/mashiny/{vehicle}/zametka', [VehicleController::class, 'note']);
    Route::post('/mashiny/{vehicle}/perestanovka', [VehicleController::class, 'move']);
    Route::post('/mashiny/{vehicle}/vydacha', [VehicleController::class, 'release']);
    Route::get('/spravochnik/mashiny', [VehicleController::class, 'suggest']);
    Route::get('/spravochnik/vin', [ReferenceController::class, 'vin']);
    Route::get('/spravochnik/marki', [ReferenceController::class, 'brands']);
    Route::get('/spravochnik/modeli', [ReferenceController::class, 'models']);
    Route::post('/spravochnik/marki', [ReferenceController::class, 'createBrand']);
    Route::post('/spravochnik/modeli', [ReferenceController::class, 'createModel']);
    Route::get('/akty/{vehicle}/{kind}', [ActController::class, 'show'])->where('kind', 'priem|vydacha');

    Route::get('/stoyanki', [YardController::class, 'index']);
    Route::post('/stoyanki', [YardController::class, 'store']);
    Route::put('/stoyanki/{yard}', [YardController::class, 'update']);
    Route::get('/klienty', [ClientController::class, 'index']);
    Route::post('/klienty', [ClientController::class, 'store']);
    Route::put('/klienty/{client}', [ClientController::class, 'update']);

    Route::get('/pochta', [MailController::class, 'index']);
    Route::get('/pochta/novoe', [MailController::class, 'compose']);
    Route::post('/pochta', [MailController::class, 'send']);
    Route::post('/pochta/fayl', [MailController::class, 'file']);
    Route::post('/pochta/sinhronizaciya', [MailController::class, 'sync']);
    Route::get('/pochta/vlozheniya/{attachment}', [MailController::class, 'attachment']);
    Route::post('/pochta/pisma/{message}/flag', [MailController::class, 'flag']);
    Route::post('/pochta/pisma/{message}/razbor', [MailController::class, 'reparse']);
    Route::post('/pochta/pisma/{message}/snova', [MailController::class, 'resend']);
    Route::get('/pochta/{thread}', [MailController::class, 'show']);
    Route::get('/pochta/{thread}/otvet/{message}', [MailController::class, 'reply']);
    Route::post('/pochta/{thread}/neprochitano', [MailController::class, 'unread']);
    Route::post('/pochta/{thread}/privyazka', [MailController::class, 'link']);
});

// На хосте стоянки ничего, кроме стоянки и входа.
Route::domain(config('xcar.park_host'))->group(function () {
    Route::fallback(fn () => abort(404));
});
