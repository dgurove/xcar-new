<?php

namespace App\Http\Cabinet;

use App\Chats\Chat;
use App\Chats\Message;
use Illuminate\Http\Request;

/** Чаты пользователя: по предложениям и обращение с сайта. */
class ChatController
{
    public function index(Request $request)
    {
        $chats = Chat::where('user_id', $request->user()->id)
            ->with(['offer.brand', 'offer.model', 'offer.media'])
            ->addSelect(['*', 'last_text' => Message::select('text')->whereColumn('chat_id', 'chats.id')->orderByDesc('seq')->limit(1)])
            ->orderByDesc('last_message_at')
            ->paginate(30);

        return view('cabinet.chats', ['chats' => $chats]);
    }
}
