<?php

namespace App\Http\Site;

use App\Chats\Actions\MarkChatRead;
use App\Chats\Actions\OpenEnquiry;
use App\Chats\Actions\PostMessage;
use App\Chats\Chat;
use App\Chats\GuestEnquiry;
use Illuminate\Http\Request;

/**
 * «Написать нам» на странице контактов — чат: у вошедшего он привязан к
 * аккаунту, у гостя — к браузеру. Первое сообщение — форма, дальше лента.
 */
class EnquiryController
{
    public function show(Request $request, GuestEnquiry $guest, MarkChatRead $read)
    {
        $user = $request->user();
        $chat = $user
            ? Chat::whereNull('offer_id')->where('user_id', $user->id)->latest('last_message_at')->first()
            : $guest->chat($request);
        if ($chat) {
            $read($chat, $user);
        }

        return view('site.pages.kontakty', [
            'chat' => $chat,
            'messages' => $chat?->messages()->with(['author', 'files'])->get(),
            'user' => $user,
        ]);
    }

    public function store(Request $request, GuestEnquiry $guest, OpenEnquiry $open, PostMessage $post)
    {
        // Приманка для роботов: людям поле не видно.
        if (filled($request->input('website'))) {
            return redirect('/kontakty');
        }

        $user = $request->user();
        $data = $request->validate([
            'name' => [$user ? 'nullable' : 'required', 'string', 'max:100'],
            'text' => ['required', 'string', 'max:4000'],
            'consent' => [$user ? 'nullable' : 'accepted'],
        ], [
            'name.required' => 'Как к Вам обращаться?',
            'text.required' => 'Напишите, с чем к нам.',
            'consent.accepted' => 'Без согласия на обработку данных сообщение не отправить.',
        ]);

        $chat = $open($user, $data['name'] ?? null, $guest->token($request));
        $post($chat, $user, $data['text']);
        if ($chat->plainToken) {
            $guest->issue($chat, $chat->plainToken);
        }

        return redirect('/kontakty');
    }
}
