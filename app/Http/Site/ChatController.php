<?php

namespace App\Http\Site;

use App\Chats\Actions\MarkChatRead;
use App\Chats\Actions\OpenChat;
use App\Chats\Actions\PostMessage;
use App\Chats\Chat;
use App\Chats\File;
use App\Chats\GuestEnquiry;
use App\Offers\Offer;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Чат: одни и те же концы для витрины, CRM и гостя с обращением; право решает Chat::allows. */
class ChatController
{
    public function __construct(private GuestEnquiry $guest) {}

    /** Первое сообщение покупателя: чат заводится здесь же, ответ — лента и её адрес в заголовках. */
    public function open(Request $request, Offer $offer, OpenChat $open, PostMessage $post)
    {
        abort_unless($offer->chat_enabled && ($offer->state->isPublic() || $offer->state->acceptsInterest()) && ! $request->user()->isStaff(), 404);
        $this->validateMessage($request);
        $chat = $open($offer, $request->user());
        $this->guarded(fn () => $post($chat, $request->user(), $request->input('text'), $request->file('files', [])));
        $messages = $chat->messages()->with(['author', 'files'])->get();

        return response(view('chat.messages', ['chat' => $chat, 'messages' => $messages, 'user' => $request->user()]))
            ->header('X-Chat-Id', (string) $chat->id)->header('X-Chat-Url', "/chaty/{$chat->id}/soobshcheniya");
    }

    public function messages(Request $request, Chat $chat, MarkChatRead $read)
    {
        abort_unless($chat->allows($request->user(), $this->guest->token($request)), 404);
        $after = (int) $request->query('after', 0);
        $messages = $chat->messages()->with(['author', 'files'])->where('seq', '>', $after)->get();
        // Прочитано — только когда лента на экране; догон в закрытой шторке бейдж не гасит.
        if ($request->boolean('read', true)) {
            $read($chat, $request->user());
        }

        return view('chat.messages', ['chat' => $chat, 'messages' => $messages, 'user' => $request->user()]);
    }

    public function post(Request $request, Chat $chat, PostMessage $post)
    {
        abort_unless($chat->allows($request->user(), $this->guest->token($request)), 404);
        $this->validateMessage($request);
        $after = (int) $request->input('after', 0);
        $this->guarded(fn () => $post($chat, $request->user(), $request->input('text'), $request->file('files', [])));
        $messages = $chat->messages()->with(['author', 'files'])->where('seq', '>', $after)->get();

        return view('chat.messages', ['chat' => $chat, 'messages' => $messages, 'user' => $request->user()]);
    }

    /** Лента ходит fetch-ом и ждёт HTML; ошибка проверки должна прийти JSON-ом 422, а не редиректом со страницей. */
    private function validateMessage(Request $request): void
    {
        $this->guarded(fn () => $request->validate(['text' => ['nullable', 'string', 'max:4000'], 'files' => ['nullable', 'array', 'max:10'], 'files.*' => ['file', 'max:20480']]));
    }

    private function guarded(callable $action): mixed
    {
        try {
            return $action();
        } catch (ValidationException $e) {
            throw new HttpResponseException(response()->json(['message' => $e->validator->errors()->first()], 422));
        }
    }

    public function file(Request $request, Chat $chat, File $file)
    {
        abort_unless($chat->allows($request->user(), $this->guest->token($request)), 404);
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
