<?php

use App\Http\Cabinet\BuyerController;
use App\Http\Cabinet\BuyerInterestController;
use App\Http\Cabinet\ChatController as CabinetChatController;
use App\Http\Cabinet\GroupController;
use App\Http\Cabinet\InviteController;
use App\Http\Cabinet\ShowingController;
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
use App\Http\Site\EnquiryController;
use App\Http\Site\FavoriteController;
use App\Http\Site\FileController;
use App\Http\Site\InterestController;
use App\Http\Site\OfferController;
use App\Http\Site\PurchaseController;
use App\Http\Site\ShareController;
use App\Support\LegacyAdmin;
use App\Users\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::get('/manifest.webmanifest', [PwaController::class, 'manifest']);
Route::get('/offline', [PwaController::class, 'offline']);
Route::get('/sw.js', [PwaController::class, 'worker']);
Route::post('/push/podpiska', [PushController::class, 'store'])->middleware('auth');
Route::delete('/push/podpiska', [PushController::class, 'destroy'])->middleware('auth');

// Без стены: обращение (и гостю по cookie), юридические страницы.
Route::get('/kontakty', [EnquiryController::class, 'show']);
Route::post('/kontakty', [EnquiryController::class, 'store'])->middleware('throttle:5,1');
Route::view('/obrabotka-dannyh', 'site.pages.obrabotka-dannyh');
Route::view('/soglashenie', 'site.pages.soglashenie');
Route::view('/soglasie', 'site.pages.soglasie');
// Лента чата открыта и гостю с обращением — право решает Chat::allows по cookie.
Route::get('/chaty/{chat}/soobshcheniya', [ChatController::class, 'messages'])->middleware('throttle:120,1');
Route::post('/chaty/{chat}/soobshcheniya', [ChatController::class, 'post'])->middleware('throttle:30,1');
Route::get('/chaty/{chat}/fayly/{file}', [ChatController::class, 'file']);

// Сайт закрыт: дальше только с открытым доступом (SiteWall).
Route::middleware('wall')->group(function () {
    Route::get('/', [CatalogController::class, 'index'])->name('home');
    Route::get('/poisk', [CatalogController::class, 'search']);
    Route::view('/voprosy', 'site.pages.voprosy');
    Route::get('/galereya', [CatalogController::class, 'gallery']);
    Route::get('/offers/{offer}', [OfferController::class, 'show'])->name('offers.show');
    Route::get('/offers/{offer}/card', [FragmentController::class, 'card']);
});

Route::middleware(['auth', 'wall'])->group(function () {
    Route::post('/offers/{offer}/stavka', [BidController::class, 'store']);
    Route::post('/stavki/{bid}/otozvat', [BidController::class, 'withdraw']);
    Route::post('/offers/{offer}/interes', [InterestController::class, 'store'])->middleware('throttle:30,1');
    Route::delete('/offers/{offer}/interes', [InterestController::class, 'destroy']);
    Route::post('/offers/{offer}/izbrannoe', [FavoriteController::class, 'toggle']);
    Route::match(['get', 'post'], '/offers/{offer}/pdf', [ShareController::class, 'pdf']);
    Route::post('/share/oshibka', [ShareController::class, 'report'])->middleware('throttle:30,1');
    Route::get('/offers/{offer}/chat', [OfferController::class, 'chat']);
    Route::post('/offers/{offer}/chat', [ChatController::class, 'open']);

    Route::get('/lk', [ProfileController::class, 'show'])->name('cabinet');
    Route::put('/lk', [ProfileController::class, 'update']);
    Route::get('/lk/profil', [ProfileController::class, 'profile']);
    Route::get('/lk/izbrannoe', [ListsController::class, 'favorites']);
    Route::get('/lk/chaty', [CabinetChatController::class, 'index']);
    Route::get('/lk/stavki', [ListsController::class, 'bids']);
    Route::get('/lk/interesy', [ListsController::class, 'interests']);

    Route::get('/lk/sdelki', [DealController::class, 'index']);
    Route::get('/lk/sdelki/{deal}', [DealController::class, 'show']);
    Route::post('/lk/sdelki/{deal}/otvet', [DealController::class, 'answer']);
    Route::post('/lk/sdelki/{deal}/fayly', [DealController::class, 'upload']);
    Route::delete('/lk/sdelki/{deal}/fayly/{media}', [DealController::class, 'removeFile']);
    // Документы и файлы с закрытого диска — на любом хосте.
    Route::get('/fayly/{media}', [FileController::class, 'show']);

    Route::get('/lk/uvedomleniya', [NotificationController::class, 'index']);
    Route::get('/lk/uvedomleniya/svezhie', [NotificationController::class, 'latest']);
    Route::post('/lk/uvedomleniya/prochitano', [NotificationController::class, 'readAll']);
    Route::post('/lk/uvedomleniya/{id}/prochitano', [NotificationController::class, 'toggleRead']);
    Route::post('/lk/uvedomleniya/{id}/otkryto', [NotificationController::class, 'seen']);
    Route::put('/lk/uvedomleniya/nastroyki', [NotificationController::class, 'settings']);
    Route::get('/lk/uvedomleniya/{id}', [NotificationController::class, 'open']);

    Route::get('/live/badges', [FragmentController::class, 'badges']);

    // Кабинет менеджера: покупатели, группы, приглашения, показы. Конкретные пути раньше {user}.
    Route::middleware('manager')->group(function () {
        Route::get('/lk/pokupateli', [BuyerController::class, 'index']);
        Route::get('/lk/pokupateli/priglasheniya', [InviteController::class, 'index']);
        Route::post('/lk/pokupateli/priglasheniya', [InviteController::class, 'store']);
        Route::post('/lk/pokupateli/priglasheniya/{invite}/vykl', [InviteController::class, 'disable']);
        Route::post('/lk/pokupateli/priglasheniya/{invite}/vkl', [InviteController::class, 'enable']);
        Route::get('/lk/interes', [BuyerInterestController::class, 'index']);
        Route::post('/lk/interes/{interest}', [BuyerInterestController::class, 'update']);
        Route::post('/lk/pokupateli/gruppy', [GroupController::class, 'store']);
        Route::get('/lk/pokupateli/gruppy/{group}', [GroupController::class, 'show']);
        Route::put('/lk/pokupateli/gruppy/{group}', [GroupController::class, 'update']);
        Route::put('/lk/pokupateli/gruppy/{group}/sostav', [GroupController::class, 'members']);
        Route::delete('/lk/pokupateli/gruppy/{group}', [GroupController::class, 'destroy']);
        Route::get('/lk/pokupateli/{user}', [BuyerController::class, 'show']);
        Route::put('/lk/pokupateli/{user}/gruppy', [BuyerController::class, 'groups']);
        Route::post('/lk/pokupateli/{user}/parol', [BuyerController::class, 'passwordLink']);
        Route::get('/lk/pokazy/novyy', [ShowingController::class, 'create']);
        Route::get('/lk/pokazy/vybor', [ShowingController::class, 'pick']);
        Route::post('/lk/pokazy', [ShowingController::class, 'store']);
        Route::delete('/lk/pokazy', [ShowingController::class, 'destroy']);
    });

    Route::get('/zakupki', [PurchaseController::class, 'index'])->middleware('purchases');
    Route::get('/zakupki/{purchase}', [PurchaseController::class, 'show'])->middleware('purchases');
    Route::get('/zakupki/{purchase}/{car}', [PurchaseController::class, 'car'])->middleware('purchases');
    Route::match(['get', 'post'], '/zakupki/{purchase}/{car}/pdf', [ShareController::class, 'carPdf'])->middleware('purchases');
    Route::post('/zakupki/{purchase}/{car}/cena', [PurchaseController::class, 'offer'])->middleware('purchases');
    Route::post('/zakupki/ceny/{offer}/otozvat', [PurchaseController::class, 'withdraw'])->middleware('purchases');
});

// Прежняя админка: по привычке идут на xcar.ru/admin/… — уводим в CRM.
Route::get('/admin/{path?}', fn (string $path = '') => redirect(LegacyAdmin::target($path, request()->getQueryString()), 301))->where('path', '.*');

if (app()->isLocal()) {
    // Вход без пароля для проверки глазами: /dev/vhod/1 — первый пользователь.
    Route::get('/dev/vhod/{user}', function (User $user) {
        auth()->login($user, true);

        return redirect('/');
    });

    // На сервере медиатеку раздаёт Caddy; artisan serve этого не умеет.
    Route::get('/media/{path}', function (string $path) {
        $file = Storage::disk('media')->path($path);
        abort_unless(is_file($file), 404);

        return response()->file($file, ['Cache-Control' => 'public, max-age=3600']);
    })->where('path', '.*');
}
