<?php

namespace App\Http\Site;

use App\Chats\Actions\MarkChatRead;
use App\Chats\Actions\OpenChat;
use App\Chats\Actions\PostMessage;
use App\Chats\Chat;
use App\Chats\File;
use App\Offers\Offer;
use Illuminate\Http\Request;

/** Чат по офферу: одни и те же концы для витрины и админки, право решает Chat::allows. */
class ChatController
{
    public function open(Request $request, Offer $offer, OpenChat $open)
    {
        abort_unless($offer->chat_enabled && ($offer->state->isPublic() || $offer->state->acceptsInterest()), 404);
        $open($offer, $request->user());

        return redirect("/offers/{$offer->number}?chat=1");
    }

    public function messages(Request $request, Chat $chat, MarkChatRead $read)
    {
        abort_unless($chat->allows($request->user()), 404);
        $after = (int) $request->query('after', 0);
        $messages = $chat->messages()->with(['author', 'files'])->where('seq', '>', $after)->get();
        $read($chat, $request->user());

        return view('chat.messages', ['messages' => $messages, 'user' => $request->user()]);
    }

    public function post(Request $request, Chat $chat, PostMessage $post)
    {
        abort_unless($chat->allows($request->user()), 404);
        $request->validate(['text' => ['nullable', 'string', 'max:4000'], 'files' => ['nullable', 'array', 'max:10'], 'files.*' => ['file', 'max:20480']]);
        $after = (int) $request->input('after', 0);
        $post($chat, $request->user(), $request->input('text'), $request->file('files', []));
        $messages = $chat->messages()->with(['author', 'files'])->where('seq', '>', $after)->get();

        return view('chat.messages', ['messages' => $messages, 'user' => $request->user()]);
    }

    public function file(Request $request, Chat $chat, File $file)
    {
        abort_unless($chat->allows($request->user()), 404);
        $file->load('message');
        abort_unless($file->message->chat_id === $chat->id, 404);
        $contents = $file->contents();
        abort_if($contents === null, 404);
        $inline = $file->isImage() || $file->mime === 'application/pdf';

        return response($contents, 200, [
            'Content-Type' => $file->mime,
            'Content-Disposition' => ($inline ? 'inline' : 'attachment')."; filename*=UTF-8''".rawurlencode($file->name),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }
}
