<?php

use App\Http\Admin\UserController;
use App\Http\Cabinet\BuyerController;
use App\Http\Cabinet\BuyerInterestController;
use App\Http\Cabinet\ChatController as CabinetChatController;
use App\Http\Cabinet\DealContractController;
use App\Http\Cabinet\DealController;
use App\Http\Cabinet\GroupController;
use App\Http\Cabinet\InviteController;
use App\Http\Cabinet\ListsController;
use App\Http\Cabinet\MoneyController;
use App\Http\Cabinet\NotificationController;
use App\Http\Cabinet\ProfileController;
use App\Http\Cabinet\ShowingController;
use App\Http\Garage\CarController as GarageCarController;
use App\Http\Garage\PickupController as GaragePickupController;
use App\Http\Garage\SettlementController as GarageSettlementController;
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
use App\Http\Site\LandingController;
use App\Http\Site\OfferController;
use App\Http\Site\PayController;
use App\Http\Site\PickupController;
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
Route::view('/company', 'site.pages.o-kompanii');
Route::view('/consent', 'site.pages.soglasie');
// Выдача по QR: анкета покупателя по ссылке от страховой и страница, которую открывает камера по QR пропуска.
Route::get('/pickup/{code}', [PickupController::class, 'show'])->where('code', '[0-9A-Za-z]{20}')->middleware('throttle:60,1');
Route::post('/pickup/{code}', [PickupController::class, 'store'])->where('code', '[0-9A-Za-z]{20}')->middleware('throttle:5,1');
Route::post('/pickup/{code}/resend', [PickupController::class, 'resend'])->where('code', '[0-9A-Za-z]{20}')->middleware('throttle:3,10');
// В QR адрес заглавными (буквенно-цифровой режим, крупные модули) — путь /P/…, руками набирают /p/….
Route::get('/p/{code}', [PickupController::class, 'scanned'])->where('code', '[0-9A-Za-z]{20}')->middleware('throttle:60,1');
Route::get('/P/{code}', [PickupController::class, 'scanned'])->where('code', '[0-9A-Za-z]{20}')->middleware('throttle:60,1');
// Оплата по ссылке: страница плательщика без входа и уведомления ЮKassa (без CSRF — `hooks/*` в исключениях).
Route::get('/pay/{code}', [PayController::class, 'show'])->where('code', '[2-9a-z]{12}')->middleware('throttle:60,1');
Route::post('/pay/{code}', [PayController::class, 'go'])->where('code', '[2-9a-z]{12}')->middleware('throttle:10,1');
Route::post('/hooks/yookassa', [PayController::class, 'hook'])->middleware('throttle:120,1');
// Лента чата открыта и гостю с обращением — право решает Chat::allows по cookie.
Route::get('/chats/{chat}/messages', [ChatController::class, 'messages'])->middleware('throttle:120,1');
Route::post('/chats/{chat}/messages', [ChatController::class, 'post'])->middleware('throttle:30,1');
Route::get('/chats/{chat}/messages/{seq}', [ChatController::class, 'message'])->whereNumber('seq');
Route::patch('/chats/{chat}/messages/{seq}', [ChatController::class, 'edit'])->whereNumber('seq')->middleware('throttle:30,1');
Route::delete('/chats/{chat}/messages/{seq}', [ChatController::class, 'destroy'])->whereNumber('seq')->middleware('throttle:30,1');
Route::post('/chats/{chat}/typing', [ChatController::class, 'typing'])->middleware('throttle:30,1');
Route::get('/chats/{chat}/files/{file}', [ChatController::class, 'file']);

// Первый экран для гостя; вошедшего и установленное приложение уводит в предложения.
Route::get('/', [LandingController::class, 'site']);

// Сайт закрыт: дальше только с открытым доступом (SiteWall).
Route::middleware('wall')->group(function () {
    Route::get('/offers', [CatalogController::class, 'index'])->name('home');
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
    Route::post('/chats/support', [ChatController::class, 'support'])->middleware('throttle:30,1');

    Route::get('/account', [ProfileController::class, 'profile'])->name('cabinet');
    Route::put('/account', [ProfileController::class, 'update']);
    Route::permanentRedirect('/account/profile', '/account');
    Route::get('/account/favorites', [ListsController::class, 'favorites']);
    Route::get('/account/chats', [CabinetChatController::class, 'index']);
    Route::get('/account/chats/offer/{offer}', [CabinetChatController::class, 'offer']);
    Route::get('/account/chats/support', [CabinetChatController::class, 'support']);
    Route::get('/account/chats/{chat}', [CabinetChatController::class, 'show']);
    Route::get('/account/interests', [ListsController::class, 'interests']);

    Route::get('/account/invoices/{invoice}/pdf', [MoneyController::class, 'pdf']);
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

    // Пользователи — админу тот же экран, что в CRM (чужому 404 в контроллере).
    Route::get('/account/users', [UserController::class, 'index']);
    Route::get('/account/users/{user}', [UserController::class, 'show'])->whereNumber('user');
    Route::put('/account/users/{user}', [UserController::class, 'update']);
    Route::delete('/account/users/{user}', [UserController::class, 'destroy']);
    Route::post('/account/users/{user}/access', [UserController::class, 'decide']);
    Route::post('/account/users/{user}/password', [UserController::class, 'passwordLink']);
    Route::post('/account/users/{user}/impersonate', [UserController::class, 'impersonate']);
    Route::put('/account/users/{user}/party', [UserController::class, 'party']);
    Route::get('/account/users/{user}/statement', [UserController::class, 'statement']);
    Route::get('/account/users/{user}/export', [UserController::class, 'export']);

    // Менеджеру: деньги в кабинете, сделки и покупатели — раздел таб-бара.
    Route::middleware('ability:isManager')->group(function () {
        // Деньги менеджера: счета к оплате, вознаграждение, история, реквизиты, акт сверки, выгрузка.
        Route::get('/account/money', [MoneyController::class, 'index']);
        Route::get('/account/money/details', [MoneyController::class, 'details']);
        Route::put('/account/money/details', [MoneyController::class, 'saveDetails']);
        Route::get('/account/money/statement', [MoneyController::class, 'statement']);
        Route::get('/account/money/export', [MoneyController::class, 'export']);
        Route::get('/account/money/invoices/{invoice}', [MoneyController::class, 'invoice']);
        Route::post('/account/money/deals/{deal}/pay', [MoneyController::class, 'pay']);
        Route::post('/account/money/invoices/{invoice}/links', [MoneyController::class, 'link']);
        Route::delete('/account/money/links/{link}', [MoneyController::class, 'cancelLink']);
        Route::put('/account/money/links/{link}/payer', [MoneyController::class, 'payer']);
        Route::get('/account/money/invoices/{invoice}/payments/{payment}/slip', [MoneyController::class, 'slip']);
        Route::get('/account/money/deals/{deal}', [MoneyController::class, 'deal']);

        // Сделки — раздел таб-бара; покупатели — его вторая пилюля (интерес, группы, показы внутри).
        Route::get('/deals', [DealController::class, 'index']);
        Route::get('/deals/{deal}', [DealController::class, 'show']);
        Route::post('/deals/{deal}/reply', [DealController::class, 'answer']);
        Route::post('/deals/{deal}/picked', [DealController::class, 'picked']);
        Route::put('/deals/{deal}/contract', [DealContractController::class, 'update']);
        Route::get('/deals/{deal}/dkp', [DealContractController::class, 'show']);
        Route::get('/deals/{deal}/dkp.pdf', [DealContractController::class, 'show']);
        Route::post('/deals/{deal}/files', [DealController::class, 'upload']);
        Route::delete('/deals/{deal}/files/{media}', [DealController::class, 'removeFile']);
        Route::get('/buyers', [BuyerController::class, 'index']);
        Route::get('/buyers/interest', [BuyerInterestController::class, 'index']);
        Route::post('/buyers/interest/{interest}', [BuyerInterestController::class, 'update']);
        Route::post('/buyers/groups', [GroupController::class, 'store']);
        Route::get('/buyers/groups/{group}', [GroupController::class, 'show']);
        Route::put('/buyers/groups/{group}', [GroupController::class, 'update']);
        Route::put('/buyers/groups/{group}/members', [GroupController::class, 'members']);
        Route::delete('/buyers/groups/{group}', [GroupController::class, 'destroy']);
        Route::get('/buyers/showings/new', [ShowingController::class, 'create']);
        Route::get('/buyers/showings/pick', [ShowingController::class, 'pick']);
        Route::post('/buyers/showings', [ShowingController::class, 'store']);
        Route::delete('/buyers/showings', [ShowingController::class, 'destroy']);
        Route::get('/buyers/{user}', [BuyerController::class, 'show'])->whereNumber('user');
        Route::put('/buyers/{user}/groups', [BuyerController::class, 'groups'])->whereNumber('user');
        Route::post('/buyers/{user}/password', [BuyerController::class, 'passwordLink'])->whereNumber('user');
        Route::put('/buyers/{user}/passport', [BuyerController::class, 'passport'])->whereNumber('user');
    });

    // Гараж — машины, выведенные из продажи менеджеру на ремонт (до 30.09.2026 — хост garage.xcar.ru).
    // Внутрь — сотрудники и менеджеры (`ability:canGarage`), свои машины менеджеру — контроллер.
    Route::prefix('garage')->middleware('ability:canGarage')->group(function () {
        Route::get('/', [GarageCarController::class, 'index']);
        Route::get('/cars/{offer}', [GarageCarController::class, 'show']);
        Route::post('/cars/{offer}/costs', [GarageCarController::class, 'storeCost']);
        Route::delete('/cars/{offer}', [GarageCarController::class, 'destroy']);
        Route::put('/costs/{cost}', [GarageCarController::class, 'updateCost']);
        Route::delete('/costs/{cost}', [GarageCarController::class, 'destroyCost']);
        // Этапы: «Привёз», «Готова» — кнопкой; «Продаю» — менеджер ценой, итог и счёт — мы, об оплате сообщает он.
        Route::post('/cars/{offer}/stage', [GarageSettlementController::class, 'stage']);
        Route::post('/cars/{offer}/advance', [GarageSettlementController::class, 'stage']);
        Route::post('/cars/{offer}/sold', [GarageSettlementController::class, 'sold']);
        Route::delete('/cars/{offer}/sold', [GarageSettlementController::class, 'unsold']);
        Route::post('/cars/{offer}/settle', [GarageSettlementController::class, 'settle']);
        Route::post('/cars/{offer}/payout', [GarageSettlementController::class, 'payout']);
        Route::post('/cars/{offer}/payments', [GarageSettlementController::class, 'pay']);
        Route::post('/cars/{offer}/checkout', [GarageSettlementController::class, 'checkout']);
        Route::post('/cars/{offer}/links', [GarageSettlementController::class, 'link']);
        Route::delete('/cars/{offer}/links/{link}', [GarageSettlementController::class, 'cancelLink']);
        Route::get('/cars/{offer}/invoice/pdf', [GarageSettlementController::class, 'pdf']);
        Route::delete('/cars/{offer}/invoice', [GarageSettlementController::class, 'voidInvoice']);
        // Вывоз, порученный менеджеру (04.10.2026): страница ТС и «Забрал».
        Route::get('/pickups/{offer}', [GaragePickupController::class, 'show']);
        Route::post('/pickups/{offer}/picked', [GaragePickupController::class, 'picked']);
    });

    Route::get('/purchases', [PurchaseController::class, 'index'])->middleware('ability:canSeePurchases');
    Route::get('/purchases/{purchase}', [PurchaseController::class, 'show'])->middleware('ability:canSeePurchases');
    Route::get('/purchases/{purchase}/{car}', [PurchaseController::class, 'car'])->middleware('ability:canSeePurchases');
    Route::match(['get', 'post'], '/purchases/{purchase}/{car}/pdf', [ShareController::class, 'carPdf'])->middleware('ability:canSeePurchases');
    Route::post('/purchases/{purchase}/{car}/price', [PurchaseController::class, 'offer'])->middleware('ability:canSeePurchases');
    Route::post('/purchases/prices/{offer}/withdraw', [PurchaseController::class, 'withdraw'])->middleware('ability:canSeePurchases');
});

// Прежняя админка: по привычке идут на xcar.ru/admin/… — уводим в CRM.
Route::get('/admin/{path?}', fn (string $path = '') => redirect(LegacyAdmin::target($path, request()->getQueryString()), 301))->where('path', '.*');

if (app()->isLocal()) {
    // Вход без пароля для проверки глазами: /dev/login/1 — первый пользователь.
    Route::get('/dev/login/{user}', function (int $user) {
        auth()->login(User::withoutGlobalScope('demo')->findOrFail($user), true);

        return redirect('/');
    });

    // На сервере медиатеку раздаёт Caddy; artisan serve этого не умеет.
    foreach (['media', 'hot'] as $disk) {
        Route::get("/{$disk}/{path}", function (string $path) use ($disk) {
            $file = Storage::disk($disk)->path($path);
            abort_unless(is_file($file), 404);

            return response()->file($file, ['Cache-Control' => 'public, max-age=3600']);
        })->where('path', '.*');
    }
}

// Неизвестный адрес сайта — 404 через маршрут, как у CRM и парковки: с сессией, вошедший видит свою оболочку, а не гостевую.
Route::fallback(fn () => abort(404));
