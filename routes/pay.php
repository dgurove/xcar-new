<?php

use App\Billing\PayView;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// XCAR Pay (22.11.2026, начальник: «такое же отдельное приложение для оплат на pay.xcar.ru») — «Оплаты» своим приложением, вид
// по человеку (`Billing\PayView`): сотруднику (админ, модератор) — «Оплаты» CRM, менеджеру — его «Оплаты» с сайта. Адреса те же,
// что в CRM (`/work/money…`, кусок `routes/money-shared.php`) и на сайте (`/account/money…` — общие пути `account`), ссылки видов и
// уведомлений не меняются. Сделку открывает шторка поверх оплат (`x-ui.deal-sheet`): в ней страница CRM или сайта.
// Корень — к «Оплатам» человека; `?car=` / `?deal=` (ссылки уведомлений) едут с ним: шторка сделки откроется там.
Route::domain(config('xcar.pay_host'))->middleware('auth')->get('/', fn (Request $request) => redirect(PayView::home().(($qs = http_build_query($request->only('car', 'deal'))) ? '?'.$qs : '')));

Route::domain(config('xcar.pay_host'))->middleware(['auth', 'ability:canEditDeals'])->group(function () {
    require __DIR__.'/money-shared.php';
});

// На хосте оплат ничего, кроме оплат и общих путей.
Route::domain(config('xcar.pay_host'))->group(function () {
    Route::fallback(fn () => abort(404));
});
