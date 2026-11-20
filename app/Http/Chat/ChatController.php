<?php

namespace App\Http\Chat;

use App\Chats\Actions\DeleteChat;
use App\Chats\Actions\MarkChatRead;
use App\Chats\Chat;
use App\Http\Admin\ChatController as Staff;
use App\Http\Cabinet\ChatController as Cabinet;
use Illuminate\Http\Request;

/**
 * Экран чатов XCAR Chat (20.11.2026): одно приложение на всех, а вид — привычный каждому. Сотруднику площадки — список и
 * экран «Чатов» CRM, менеджеру и покупателю — «Чаты» сайта; сами экраны и их выборки — те же контроллеры, своего здесь нет.
 */
class ChatController
{
    public function index(Request $request)
    {
        return Chat::staffView($request->user()) ? app(Staff::class)->index($request) : app(Cabinet::class)->index($request);
    }

    public function show(Request $request, Chat $chat, MarkChatRead $read)
    {
        return Chat::staffView($request->user()) ? app(Staff::class)->show($request, $chat, $read) : app(Cabinet::class)->show($request, $chat, $read);
    }

    public function destroy(Request $request, Chat $chat, DeleteChat $delete)
    {
        return app(Staff::class)->destroy($request, $chat, $delete);
    }
}
