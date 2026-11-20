<?php

use App\Http\Chat\ChatController;
use Illuminate\Support\Facades\Route;

// XCAR Chat (20.11.2026, владелец: «чат отдельным chat.xcar.ru, отдельным PWA») — те же чаты сайта и CRM своим приложением.
// Свои здесь только корень и экран чатов: какой вид — по человеку (`Chat::staffView`). Лента `/chats/{id}/messages|files|typing`,
// «Написать» по ТС и в поддержку, профиль и уведомления — общие пути web.php (`ResolveSurface::SHARED`). Сделку открывает
// лист поверх чата (`x-chat.deal-sheet`): в нём страница CRM или сайта, переходить в другое приложение не нужно.
Route::domain(config('xcar.chat_host'))->get('/', fn () => redirect('/chats'));

Route::domain(config('xcar.chat_host'))->middleware(['auth', 'wall'])->group(function () {
    Route::get('/chats', [ChatController::class, 'index']);
    Route::get('/chats/{chat}', [ChatController::class, 'show'])->whereNumber('chat');
    Route::delete('/chats/{chat}', [ChatController::class, 'destroy'])->whereNumber('chat');
    // «Приложить за менеджера» под файлами в чате сделки (`x-chat.doc-pick`) — та же дверь, что в CRM: на этом хосте путь
    // `/work/deals/…/payment-proof` — сайтовый, менеджерский.
    Route::post('/chats/deals/{deal}/payment-proof', [\App\Http\Admin\PaymentProofController::class, 'store'])->middleware('ability:canEditDeals');
});
