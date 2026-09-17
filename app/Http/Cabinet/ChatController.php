<?php

namespace App\Http\Cabinet;

use App\Chats\Actions\MarkChatRead;
use App\Chats\Chat;
use App\Chats\Message;
use Illuminate\Http\Request;

/** Чаты пользователя: свои по предложениям и обращение с сайта, а у менеджера — ещё чаты его покупателей, где он вторая сторона. */
class ChatController
{
    public function index(Request $request)
    {
        $me = $request->user()->id;
        $chats = Chat::where(fn ($w) => $w->where('user_id', $me)->orWhere('manager_id', $me))
            ->with(['offer.brand', 'offer.model', 'offer.media', 'user'])
            ->addSelect(['*', 'last_text' => Message::select('text')->whereColumn('chat_id', 'chats.id')->orderByDesc('seq')->limit(1)])
            ->orderByDesc('last_message_at')
            ->paginate(30);

        return view('cabinet.chats', ['chats' => $chats]);
    }

    /** Чат покупателя — экран менеджера: строка ТС, покупатель с телефоном, лента. */
    public function show(Request $request, Chat $chat, MarkChatRead $read)
    {
        abort_unless($chat->isBuyerChat() && $chat->isCounterpart($request->user()), 404);
        $chat->load(['offer.brand', 'offer.model', 'offer.media', 'user']);
        $read($chat, $request->user());

        return view('cabinet.chat', ['chat' => $chat, 'messages' => $chat->messages()->with(['author', 'files'])->get(), 'user' => $request->user()]);
    }
}
