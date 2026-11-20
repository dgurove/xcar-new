<?php

use App\Http\Admin\MailController;
use Illuminate\Support\Facades\Route;

// Почта CRM одним куском на два хоста (20.11.2026): crm.xcar.ru и XCAR Mail (mail.xcar.ru). Подключают `routes/crm.php` и
// `routes/mail.php` внутри своей группы `domain` + `auth`; новый маршрут почты ставится здесь, и он есть в обоих приложениях.
// Из писем: та же почта с вшитой выборкой «надо завести» — раньше `/offers/{offer}`, иначе from-mail примут за номер.
Route::middleware('ability:canCrmMail')->group(function () {
    Route::get('/offers/from-mail', [MailController::class, 'fromMail']);
    Route::post('/offers/from-mail/{candidate}/create', [MailController::class, 'promote']);
    Route::post('/offers/from-mail/{candidate}/decline', [MailController::class, 'decline']);
    Route::get('/offers/from-mail/{candidate}/letters', [MailController::class, 'candidateLetters']);
});
Route::prefix('work/mail')->middleware('ability:canCrmMail')->group(function () {
    Route::get('/', [MailController::class, 'index']);
    Route::get('/new', [MailController::class, 'compose']);
    // Шторка «Вендор письма» — по первому требованию (`data-lazy-sheets`): со списком всех вендоров она ехала на каждую страницу.
    Route::view('/vendor-sheet', 'admin.mail.vendor-sheet');
    Route::post('/', [MailController::class, 'send']);
    Route::post('/file', [MailController::class, 'file']);
    Route::get('/addresses', [MailController::class, 'addresses']);
    Route::post('/sync', [MailController::class, 'sync']);
    Route::get('/attachments/{attachment}', [MailController::class, 'attachment']);
    Route::post('/messages/{message}/flag', [MailController::class, 'flag']);
    // «Входящие»: прочитано по письму (17.11.2026).
    Route::post('/messages/{message}/seen', [MailController::class, 'seen']);
    Route::post('/messages/{message}/parse', [MailController::class, 'reparse']);
    Route::post('/messages/{message}/retry', [MailController::class, 'resend']);
    Route::get('/messages/{message}/sent', [MailController::class, 'sent']);
    Route::get('/messages/{message}/state', [MailController::class, 'state']);
    Route::get('/{thread}', [MailController::class, 'show']);
    Route::get('/{thread}/window', [MailController::class, 'window']);
    Route::get('/{thread}/reply/{message}', [MailController::class, 'reply']);
    Route::post('/{thread}/unread', [MailController::class, 'unread']);
    Route::post('/{thread}/answered', [MailController::class, 'answered']);
    Route::post('/{thread}/archive', [MailController::class, 'archive']);
    Route::post('/case/{kind}/{id}/archive', [MailController::class, 'archiveCase'])->whereIn('kind', ['o', 'c', 't'])->whereNumber('id');
    Route::post('/{thread}/candidate', [MailController::class, 'candidate']);
    Route::post('/{thread}/link', [MailController::class, 'link']);
    Route::post('/{thread}/vendor', [MailController::class, 'vendor']);
});
