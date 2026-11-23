<?php

use App\Http\Admin\DealController;
use App\Http\Admin\MailController;
use Illuminate\Support\Facades\Route;

// XCAR Mail (20.11.2026, владелец: «так же, как чат, для почты, только админам и модераторам CRM») — почта CRM своим
// приложением. Адреса те же, что в CRM (`/work/mail`, «Из писем» `/offers/from-mail`): ссылки видов, JS и уведомлений не
// меняются. Сделку письма открывает шторка поверх почты (`x-ui.deal-sheet`): в ней страница CRM, уходить из почты не нужно.
Route::domain(config('xcar.mail_host'))->get('/', fn () => redirect('/work/mail'));

Route::domain(config('xcar.mail_host'))->middleware(['auth', 'ability:canCrmMail'])->group(function () {
    require __DIR__.'/mail-shared.php';
    // Окно писем машины («Письма» строки «Входящих») и «Отменить» черновика из писем — те же двери, что в CRM.
    Route::middleware('offer.edit')->group(function () {
        Route::get('/offers/{offer}/letters', [MailController::class, 'offerLetters']);
        Route::post('/offers/{offer}/drop', [MailController::class, 'dropDraft']);
    });
    // «Поставщик отказал» из меню письма и ответа — тому, кто ведёт сделку, как в CRM.
    Route::post('/work/deals/{deal}/declined', [DealController::class, 'declined'])->middleware('ability:canEditDeals');
});

// На хосте почты ничего, кроме почты и общих путей.
Route::domain(config('xcar.mail_host'))->group(function () {
    Route::fallback(fn () => abort(404));
});
