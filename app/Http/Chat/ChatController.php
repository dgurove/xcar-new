<?php

namespace App\Http\Chat;

use App\Chats\Actions\DeleteChat;
use App\Chats\Actions\MarkChatRead;
use App\Chats\Chat;
use App\Http\Admin\ChatController as Staff;
use App\Http\Cabinet\ChatController as Cabinet;
use App\Users\User;
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

    /** Своя переписка сотрудника (он менеджер покупателя или сам пишет площадке) — экраном сайта: там он сторона, а не читатель. */
    public function show(Request $request, Chat $chat, MarkChatRead $read)
    {
        $user = $request->user();
        $own = $chat->manager_id === $user->id || $chat->user_id === $user->id;

        // Своя личка сотрудника — экраном сотрудника (22.11.2026): у модератора сайта нет, и список слева не должен меняться.
        return Chat::staffView($user) && (! $own || $chat->isDirect()) ? app(Staff::class)->show($request, $chat, $read) : app(Cabinet::class)->show($request, $chat, $read);
    }

    /** Личка (`Chats\Direct`): сотруднику — экран CRM, остальным — сайта. */
    public function with(Request $request, ?User $user = null)
    {
        return Chat::staffView($request->user()) ? app(Staff::class)->with($request, $user) : app(Cabinet::class)->with($request, $user);
    }

    public function destroy(Request $request, Chat $chat, DeleteChat $delete)
    {
        return app(Staff::class)->destroy($request, $chat, $delete);
    }
}
