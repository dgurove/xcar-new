<?php

use App\Http\Admin\DealContractController;
use App\Http\Admin\MoneyController;
use App\Http\Admin\PayLinkController;
use Illuminate\Support\Facades\Route;

// «Оплаты» одним куском на два хоста (22.11.2026): crm.xcar.ru и XCAR Pay (pay.xcar.ru). Подключают `routes/crm.php` и
// `routes/pay.php` внутри своей группы `domain` + `auth`; новый маршрут экранов «Оплат» и их шторок ставится здесь, и он есть
// в обоих приложениях.
Route::middleware('ability:canEditDeals')->group(function () {
    // Раздел — админу и модератору (22.11.2026, начальник: «чтобы и администраторы, и модераторы видели, что по оплатам
    // менеджеров»); до того — только админу. Счёт без сделки модератору закрыт (`MoneyController::guardInvoice`,
    // `PayLinkController`), кнопок, которых ему нельзя, вид не рисует.
    Route::get('/work/money', [MoneyController::class, 'index']);
    Route::get('/work/money/managers', [MoneyController::class, 'managers']); // бывшие «Расчёты с менеджерами» — редирект
    // Человек в «Оплатах» (18.11.2026): его машины в разделе; `none` — без менеджера.
    Route::get('/work/money/managers/{person}', [MoneyController::class, 'person'])->where('person', '\d+|none');
    // Счёт — смотреть (окно счёта, PDF, печать, акт).
    Route::get('/work/money/invoices/{invoice}', [MoneyController::class, 'show']);
    Route::get('/work/money/invoices/{invoice}/pdf', [MoneyController::class, 'file']);
    Route::get('/work/money/invoices/{invoice}/print', [MoneyController::class, 'print']);
    Route::get('/work/money/invoices/{invoice}/act', [MoneyController::class, 'act']);
    // Кнопки строки: «Выставить» счёт сделки (16.11.2026) и «Напомнить менеджеру» (19.11.2026, `NudgeInvoice`).
    Route::post('/work/deals/{deal}/invoices/{invoice}/issue', [DealContractController::class, 'issueInvoice']);
    Route::post('/work/deals/{deal}/invoices/{invoice}/nudge', [DealContractController::class, 'nudgeInvoice']);
    // Счета и деньги сделки — модератору тоже (18.11.2026, владелец: «у модераторов должен быть полный доступ ко всему, что
    // касается договоров и счетов»): оплаты и аннулирование счёта, заявки менеджера об оплате, ссылки у счёта.
    Route::post('/work/payments/{payment}/confirm', [MoneyController::class, 'confirm']);
    Route::post('/work/payments/{payment}/reject', [MoneyController::class, 'reject']);
    Route::post('/work/payments/{payment}/to-supplier', [MoneyController::class, 'toSupplier']);
    Route::post('/work/money/invoices/{invoice}/links', [PayLinkController::class, 'store']);
    Route::delete('/work/money/links/{link}', [PayLinkController::class, 'destroy']);
    Route::put('/work/money/links/{link}/email', [PayLinkController::class, 'email']);
    Route::prefix('work/money/invoices/{invoice}')->group(function () {
        Route::put('/', [MoneyController::class, 'update']);
        Route::post('/payments', [MoneyController::class, 'pay']);
        Route::get('/payments/{payment}/slip', [MoneyController::class, 'slip']);
        Route::delete('/payments/{payment}', [MoneyController::class, 'unpay']);
        Route::post('/void', [MoneyController::class, 'void']);
    });
});

// Разовая оплата по ссылке без сделки, «Назначить менеджера» и привязка ссылки к машине — только админ (05.10.2026, 22.11.2026);
// возврат эквайринга — тоже.
Route::middleware('ability:isAdmin')->group(function () {
    Route::post('/work/money/service', [MoneyController::class, 'service']);
    Route::get('/work/money/cars', [MoneyController::class, 'cars']);
    Route::get('/work/money/links/existing', [MoneyController::class, 'existing']);
    Route::post('/work/money/invoices/{invoice}/manager', [MoneyController::class, 'assign']);
    Route::put('/work/money/links/{link}/car', [PayLinkController::class, 'car']);
    Route::get('/work/money/links/{link}/attach', [PayLinkController::class, 'attach']);
});
Route::middleware('ability:canManageCrm')->group(function () {
    Route::post('/work/money/acquiring/{attempt}/refund', [PayLinkController::class, 'refund']);
});
