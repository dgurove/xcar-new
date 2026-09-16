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
// «Не присылать на почту» из письма: подписанная ссылка, без входа.
Route::match(['get', 'post'], '/mail/unsubscribe/{user}', [NotificationController::class, 'unsubscribe'])->middleware('signed')->name('mail.unsubscribe');
Route::post('/push/subscription', [PushController::class, 'store'])->middleware('auth');
Route::delete('/push/subscription', [PushController::class, 'destroy'])->middleware('auth');

// Без стены: обращение (и гостю по cookie), юридические страницы.
Route::get('/contacts', [EnquiryController::class, 'show']);
Route::post('/contacts', [EnquiryController::class, 'store'])->middleware('throttle:5,1');
Route::view('/privacy', 'site.pages.obrabotka-dannyh');
Route::view('/terms', 'site.pages.soglashenie');
Route::view('/consent', 'site.pages.soglasie');
// Лента чата открыта и гостю с обращением — право решает Chat::allows по cookie.
Route::get('/chats/{chat}/messages', [ChatController::class, 'messages'])->middleware('throttle:120,1');
Route::post('/chats/{chat}/messages', [ChatController::class, 'post'])->middleware('throttle:30,1');
Route::get('/chats/{chat}/files/{file}', [ChatController::class, 'file']);

// Сайт закрыт: дальше только с открытым доступом (SiteWall).
Route::middleware('wall')->group(function () {
    Route::get('/', [CatalogController::class, 'index'])->name('home');
    Route::get('/search', [CatalogController::class, 'search']);
    Route::view('/faq', 'site.pages.voprosy');
    Route::get('/gallery', [CatalogController::class, 'gallery']);
    Route::get('/offers/{offer}', [OfferController::class, 'show'])->name('offers.show');
    Route::get('/offers/{offer}/card', [FragmentController::class, 'card']);
});

Route::middleware(['auth', 'wall'])->group(function () {
    Route::post('/offers/{offer}/confirm', [BidController::class, 'store']);
    Route::post('/confirmations/{bid}/withdraw', [BidController::class, 'withdraw']);
    Route::post('/offers/{offer}/interest', [InterestController::class, 'store'])->middleware('throttle:30,1');
    Route::delete('/offers/{offer}/interest', [InterestController::class, 'destroy']);
    Route::post('/offers/{offer}/favorites', [FavoriteController::class, 'toggle']);
    Route::match(['get', 'post'], '/offers/{offer}/pdf', [ShareController::class, 'pdf']);
    Route::post('/share/error', [ShareController::class, 'report'])->middleware('throttle:30,1');
    Route::get('/offers/{offer}/chat', [OfferController::class, 'chat']);
    Route::post('/offers/{offer}/chat', [ChatController::class, 'open']);

    Route::get('/account', [ProfileController::class, 'profile'])->name('cabinet');
    Route::put('/account', [ProfileController::class, 'update']);
    Route::permanentRedirect('/account/profile', '/account');
    Route::get('/account/favorites', [ListsController::class, 'favorites']);
    Route::get('/account/chats', [CabinetChatController::class, 'index']);
    Route::permanentRedirect('/account/confirmations', '/account/deals');
    Route::get('/account/interests', [ListsController::class, 'interests']);

    Route::get('/account/deals', [DealController::class, 'index']);
    Route::get('/account/deals/{deal}', [DealController::class, 'show']);
    Route::post('/account/deals/{deal}/reply', [DealController::class, 'answer']);
    Route::post('/account/deals/{deal}/files', [DealController::class, 'upload']);
    Route::delete('/account/deals/{deal}/files/{media}', [DealController::class, 'removeFile']);
    // Документы и файлы с закрытого диска — на любом хосте.
    Route::get('/files/{media}', [FileController::class, 'show']);

    Route::get('/account/notifications', [NotificationController::class, 'index']);
    Route::get('/account/notifications/latest', [NotificationController::class, 'latest']);
    Route::post('/account/notifications/read', [NotificationController::class, 'readAll']);
    Route::post('/account/notifications/{id}/read', [NotificationController::class, 'toggleRead']);
    Route::post('/account/notifications/{id}/opened', [NotificationController::class, 'seen']);
    Route::get('/account/notifications/settings', [NotificationController::class, 'settingsPage']);
    Route::put('/account/notifications/settings', [NotificationController::class, 'settings']);
    Route::post('/account/notifications/check', [NotificationController::class, 'test'])->middleware('throttle:3,1');
    Route::get('/account/notifications/{id}', [NotificationController::class, 'open']);

    Route::get('/live/badges', [FragmentController::class, 'badges']);

    // Приглашения — менеджеру и админу один экран (чужому 404 в контроллере); прежний адрес — навсегда сюда.
    Route::get('/account/invites', [InviteController::class, 'index']);
    Route::post('/account/invites', [InviteController::class, 'store']);
    Route::post('/account/invites/{invite}/off', [InviteController::class, 'disable']);
    Route::post('/account/invites/{invite}/on', [InviteController::class, 'enable']);
    Route::permanentRedirect('/account/buyers/invites', '/account/invites');

    // Пользователи — админу тот же экран, что в CRM (чужому 404 в контроллере).
    Route::get('/account/users', [\App\Http\Admin\UserController::class, 'index']);
    Route::put('/account/users/{user}', [\App\Http\Admin\UserController::class, 'update']);
    Route::delete('/account/users/{user}', [\App\Http\Admin\UserController::class, 'destroy']);
    Route::post('/account/users/{user}/access', [\App\Http\Admin\UserController::class, 'decide']);
    Route::post('/account/users/{user}/password', [\App\Http\Admin\UserController::class, 'passwordLink']);

    // Кабинет менеджера: покупатели, группы, приглашения, показы. Конкретные пути раньше {user}.
    Route::middleware('manager')->group(function () {
        Route::get('/account/buyers', [BuyerController::class, 'index']);
        Route::get('/account/interest', [BuyerInterestController::class, 'index']);
        Route::post('/account/interest/{interest}', [BuyerInterestController::class, 'update']);
        Route::post('/account/buyers/groups', [GroupController::class, 'store']);
        Route::get('/account/buyers/groups/{group}', [GroupController::class, 'show']);
        Route::put('/account/buyers/groups/{group}', [GroupController::class, 'update']);
        Route::put('/account/buyers/groups/{group}/members', [GroupController::class, 'members']);
        Route::delete('/account/buyers/groups/{group}', [GroupController::class, 'destroy']);
        Route::get('/account/buyers/{user}', [BuyerController::class, 'show']);
        Route::put('/account/buyers/{user}/groups', [BuyerController::class, 'groups']);
        Route::post('/account/buyers/{user}/password', [BuyerController::class, 'passwordLink']);
        Route::get('/account/showings/new', [ShowingController::class, 'create']);
        Route::get('/account/showings/pick', [ShowingController::class, 'pick']);
        Route::post('/account/showings', [ShowingController::class, 'store']);
        Route::delete('/account/showings', [ShowingController::class, 'destroy']);
    });

    Route::get('/purchases', [PurchaseController::class, 'index'])->middleware('purchases');
    Route::get('/purchases/{purchase}', [PurchaseController::class, 'show'])->middleware('purchases');
    Route::get('/purchases/{purchase}/{car}', [PurchaseController::class, 'car'])->middleware('purchases');
    Route::match(['get', 'post'], '/purchases/{purchase}/{car}/pdf', [ShareController::class, 'carPdf'])->middleware('purchases');
    Route::post('/purchases/{purchase}/{car}/price', [PurchaseController::class, 'offer'])->middleware('purchases');
    Route::post('/purchases/prices/{offer}/withdraw', [PurchaseController::class, 'withdraw'])->middleware('purchases');
});

// Прежняя админка: по привычке идут на xcar.ru/admin/… — уводим в CRM.
Route::get('/admin/{path?}', fn (string $path = '') => redirect(LegacyAdmin::target($path, request()->getQueryString()), 301))->where('path', '.*');

if (app()->isLocal()) {
    // Вход без пароля для проверки глазами: /dev/login/1 — первый пользователь.
    Route::get('/dev/login/{user}', function (User $user) {
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
