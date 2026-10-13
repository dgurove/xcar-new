<?php

namespace App\Http\Site;

use App\Chats\Actions\MarkChatRead;
use App\Chats\Actions\OpenEnquiry;
use App\Chats\Actions\PostMessage;
use App\Chats\Chat;
use App\Chats\GuestEnquiry;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * «Написать нам» на странице контактов — чат: у вошедшего он привязан к
 * аккаунту, у гостя — к браузеру. Первое сообщение — форма, дальше лента.
 */
class EnquiryController
{
    public function show(Request $request, GuestEnquiry $guest, MarkChatRead $read)
    {
        $user = $request->user();
        if ($user?->isAdmin()) {
            return view('site.pages.kontakty', ['chat' => null, 'messages' => null, 'user' => $user, 'staff' => true]);
        }
        $chat = $user
            ? Chat::whereNull('offer_id')->where('user_id', $user->id)->latest('last_message_at')->first()
            : $guest->chat($request);
        if ($chat) {
            $read($chat, $user, $guest->token($request));
        }

        return view('site.pages.kontakty', [
            'chat' => $chat,
            'messages' => $chat?->messages()->with(['author', 'files'])->get(),
            'user' => $user,
            'staff' => false,
        ]);
    }

    /**
     * «Продолжить переписку» из письма с ответом (`SendGuestReply`): подписанная ссылка выдаёт этому браузеру обращение —
     * новый токен в cookie, прежний браузер его теряет. Вошедшему — просто страница: обращение гостя не его.
     */
    public function open(Request $request, Chat $chat, GuestEnquiry $guest)
    {
        if (! $request->user() && $chat->isEnquiry() && $chat->user_id === null) {
            $plain = Str::random(40);
            $chat->update(['guest_token' => hash('sha256', $plain)]);
            $guest->issue($chat, $plain);
        }

        return redirect('/contacts');
    }

    public function store(Request $request, GuestEnquiry $guest, OpenEnquiry $open, PostMessage $post)
    {
        // Приманка для роботов: людям поле не видно.
        if (filled($request->input('website'))) {
            return redirect('/contacts');
        }

        $user = $request->user();
        abort_if($user?->isAdmin(), 404);
        $data = $request->validate([
            'name' => [$user ? 'nullable' : 'required', 'string', 'max:100'],
            'email' => [$user ? 'nullable' : 'required', 'email', 'max:255'],
            'text' => ['required', 'string', 'max:4000'],
            'consent' => [$user ? 'nullable' : 'accepted'],
        ], [
            'name.required' => 'Как к Вам обращаться?',
            'email.required' => 'Куда прислать ответ?',
            'email.email' => 'Проверьте почту',
            'text.required' => 'Напишите, с чем к нам.',
            'consent.accepted' => 'Без согласия на обработку данных сообщение не отправить.',
        ]);

        $email = $user ? null : mb_strtolower(trim($data['email']));
        $chat = $open($user, $data['name'] ?? null, $guest->token($request), $email);
        $post($chat, $user, $data['text']);
        if ($chat->plainToken) {
            $guest->issue($chat, $chat->plainToken);
        }

        return redirect('/contacts');
    }
}
