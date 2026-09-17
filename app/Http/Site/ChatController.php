<?php

namespace App\Http\Site;

use App\Chats\Actions\DeleteMessage;
use App\Chats\Actions\EditMessage;
use App\Chats\Actions\MarkChatRead;
use App\Chats\Actions\OpenChat;
use App\Chats\Actions\OpenEnquiry;
use App\Chats\Actions\PostMessage;
use App\Chats\Chat;
use App\Chats\File;
use App\Chats\GuestEnquiry;
use App\Chats\Message;
use App\Chats\Presence;
use App\Live\Publisher;
use App\Live\Topics;
use App\Offers\Offer;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Чат: одни и те же концы для витрины, CRM и гостя с обращением; читать — Chat::allows, писать — Chat::canPost. */
class ChatController
{
    /** Сколько последних сообщений отдаётся при открытии; старше — подгрузка «раньше». */
    public const PAGE = 50;

    public function __construct(private GuestEnquiry $guest) {}

    /** Первое сообщение покупателя: чат заводится здесь же, ответ — лента и её адрес в заголовках. */
    public function open(Request $request, Offer $offer, OpenChat $open, PostMessage $post)
    {
        abort_unless($offer->chat_enabled && ($offer->state->isPublic() || $offer->state->acceptsInterest()) && $request->user()->canChat(), 404);
        $this->validateMessage($request);
        $chat = $open($offer, $request->user());
        $this->guarded(fn () => $post($chat, $request->user(), $request->input('text'), $request->file('files', []), $request->integer('reply_to') ?: null));
        $messages = $chat->messages()->with(['author', 'files'])->get();

        return response(view('chat.messages', ['chat' => $chat, 'messages' => $messages, 'user' => $request->user()]))
            ->header('X-Chat-Id', (string) $chat->id)->header('X-Chat-Url', "/chats/{$chat->id}/messages");
    }

    /** Первое сообщение администрации из кабинета: обращение заводится здесь же, как чат по ТС в open(). */
    public function support(Request $request, OpenEnquiry $open, PostMessage $post)
    {
        $user = $request->user();
        abort_if($user->isStaff(), 404);
        $this->validateMessage($request);
        $chat = $open($user, null, null);
        $this->guarded(fn () => $post($chat, $user, $request->input('text'), $request->file('files', []), $request->integer('reply_to') ?: null));
        $messages = $chat->messages()->with(['author', 'files'])->get();

        return response(view('chat.messages', ['chat' => $chat, 'messages' => $messages, 'user' => $user]))
            ->header('X-Chat-Id', (string) $chat->id)->header('X-Chat-Url', "/chats/{$chat->id}/messages");
    }

    /** Догон «всё после N» или страница старых «до N» (before). */
    public function messages(Request $request, Chat $chat, MarkChatRead $read)
    {
        $token = $this->guest->token($request);
        abort_unless($chat->allows($request->user(), $token), 404);
        if ($before = (int) $request->query('before', 0)) {
            $messages = $chat->messages()->with(['author', 'files'])->where('seq', '<', $before)->reorder('seq', 'desc')->limit(self::PAGE)->get()->reverse()->values();

            return view('chat.messages', ['chat' => $chat, 'messages' => $messages, 'user' => $request->user(), 'more' => $messages->isNotEmpty() && $messages->first()->seq > 1]);
        }
        $after = (int) $request->query('after', 0);
        $messages = $chat->messages()->with(['author', 'files'])->where('seq', '>', $after)->get();
        // Прочитано — только когда лента на экране; догон в закрытой шторке бейдж не гасит.
        if ($request->boolean('read', true)) {
            $read($chat, $request->user(), $token);
            Presence::touch($chat, $request->user());
        }

        return view('chat.messages', ['chat' => $chat, 'messages' => $messages, 'user' => $request->user(), 'after' => $after]);
    }

    /** Один пузырь — после правки или удаления. */
    public function message(Request $request, Chat $chat, int $seq)
    {
        abort_unless($chat->allows($request->user(), $this->guest->token($request)), 404);
        $message = $chat->messages()->with(['author', 'files'])->where('seq', $seq)->firstOrFail();

        return view('chat.messages', ['chat' => $chat, 'messages' => collect([$message]), 'user' => $request->user(), 'single' => true]);
    }

    public function post(Request $request, Chat $chat, PostMessage $post)
    {
        abort_unless($chat->canPost($request->user(), $this->guest->token($request)), 404);
        $this->validateMessage($request);
        $after = (int) $request->input('after', 0);
        $this->guarded(fn () => $post($chat, $request->user(), $request->input('text'), $request->file('files', []), $request->integer('reply_to') ?: null));
        $messages = $chat->messages()->with(['author', 'files'])->where('seq', '>', $after)->get();

        return view('chat.messages', ['chat' => $chat, 'messages' => $messages, 'user' => $request->user(), 'after' => $after]);
    }

    public function edit(Request $request, Chat $chat, int $seq, EditMessage $edit)
    {
        abort_unless($request->user() && $chat->canPost($request->user()), 404);
        $message = $this->own($chat, $seq);
        $this->guarded(fn () => $request->validate(['text' => ['nullable', 'string', 'max:4000']]));
        $this->guarded(fn () => $edit($message, $request->user(), $request->input('text')));

        return $this->message($request, $chat, $seq);
    }

    public function destroy(Request $request, Chat $chat, int $seq, DeleteMessage $delete)
    {
        abort_unless($request->user() && $chat->canPost($request->user()), 404);
        $delete($this->own($chat, $seq), $request->user());

        return $this->message($request, $chat, $seq);
    }

    /** «Печатает…» — только в хаб другой стороне, в базе следа нет. */
    public function typing(Request $request, Chat $chat, Publisher $publish)
    {
        $user = $request->user();
        abort_unless($chat->canPost($user, $this->guest->token($request)), 404);
        $topic = $chat->isCounterpart($user)
            ? ($chat->user_id ? Topics::user($chat->user_id) : Topics::chat($chat->id))
            : ($chat->manager_id ? Topics::user($chat->manager_id) : Topics::STAFF);
        $publish($topic, 'chat-typing', ['chat' => $chat->id, 'user' => $user?->id]);

        return response()->noContent();
    }

    private function own(Chat $chat, int $seq): Message
    {
        return $chat->messages()->with('files')->where('seq', $seq)->firstOrFail();
    }

    /** Лента ходит fetch-ом и ждёт HTML; ошибка проверки должна прийти JSON-ом 422, а не редиректом со страницей. */
    private function validateMessage(Request $request): void
    {
        $this->guarded(fn () => $request->validate(['text' => ['nullable', 'string', 'max:4000'], 'files' => ['nullable', 'array', 'max:10'], 'files.*' => ['file', 'max:20480'], 'reply_to' => ['nullable', 'integer']]));
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
