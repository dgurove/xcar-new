<?php

namespace App\Http\Admin;

use App\Support\ListView;
use App\Support\OfficePreview;
use App\Telegram\Actions\SendAsBot;
use App\Telegram\Bot;
use App\Telegram\Chat;
use App\Telegram\ChatMessage;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Настройки → «Бот Telegram», только админам: вся переписка бота — что он написал сам, что ему прислали,
 * какие кнопки нажали — и изредка ответ от его имени. Счётчиков и непрочитанных нет: раздел, чтобы посмотреть.
 * Экран и лента — как «Работа → Чаты» (chat_controller, тот же протокол «после N» / «до N»).
 */
class TelegramChatController
{
    public const PAGE = 50;

    public function index(Request $request)
    {
        return view('admin.telegram.index', $this->list($request) + ['current' => null]);
    }

    public function show(Request $request, Chat $chat)
    {
        $chat->load('user');
        $messages = $this->feed($chat)->reorder('id', 'desc')->limit(self::PAGE)->get()->reverse()->values();

        return view('admin.telegram.index', $this->list($request) + [
            'chat' => $chat, 'messages' => $messages, 'current' => $chat->id,
            'more' => $messages->isNotEmpty() && $chat->messages()->where('id', '<', $messages->first()->id)->exists(),
        ]);
    }

    /** Догон «всё после N» или страница старых «до N». */
    public function messages(Request $request, Chat $chat)
    {
        if ($before = (int) $request->query('before', 0)) {
            $messages = $this->feed($chat)->where('id', '<', $before)->reorder('id', 'desc')->limit(self::PAGE)->get()->reverse()->values();

            return $this->fragment($chat, $messages, ['more' => $messages->isNotEmpty() && $chat->messages()->where('id', '<', $messages->first()->id)->exists()]);
        }

        return $this->fragment($chat, $this->feed($chat)->where('id', '>', (int) $request->query('after', 0))->get(), ['after' => true]);
    }

    public function message(Chat $chat, int $seq)
    {
        return $this->fragment($chat, $this->feed($chat)->whereKey($seq)->get(), ['single' => true]);
    }

    public function post(Request $request, Chat $chat, SendAsBot $send)
    {
        $this->guarded(fn () => $request->validate(['text' => ['nullable', 'string', 'max:4000'], 'files' => ['nullable', 'array', 'max:10'], 'files.*' => ['file', 'max:20480'], 'reply_to' => ['nullable', 'integer']]));
        $after = (int) $request->input('after', 0);
        try {
            $send($chat, $request->user(), $request->input('text'), $request->file('files', []), $request->integer('reply_to') ?: null);
        } catch (Throwable $e) {
            // Отказ Telegram уже в переписке пузырём с причиной — лента его покажет; сбой сети — ошибкой в поле.
            if (! ($e instanceof RequestException && $e->response->clientError())) {
                throw new HttpResponseException(response()->json(['message' => 'Telegram не ответил, попробуйте ещё раз'], 422));
            }
        }

        return $this->fragment($chat, $this->feed($chat)->where('id', '>', $after)->get(), ['after' => true]);
    }

    public function edit(Request $request, Chat $chat, int $seq, SendAsBot $send)
    {
        $message = $this->manual($chat, $seq);
        $text = trim((string) $this->guarded(fn () => $request->validate(['text' => ['required', 'string', 'max:4000']]))['text']);
        if (! $send->edit($message, $text)) {
            throw new HttpResponseException(response()->json(['message' => 'Telegram не дал изменить'], 422));
        }

        return $this->message($chat, $seq);
    }

    public function destroy(Chat $chat, int $seq, SendAsBot $send)
    {
        $message = $this->manual($chat, $seq);
        abort_unless($message->canDelete(), 422);
        try {
            $send->delete($message);
        } catch (Throwable) {
            throw new HttpResponseException(response()->json(['message' => 'Telegram не дал удалить'], 422));
        }

        return $this->message($chat, $seq);
    }

    public function typing(Chat $chat, Bot $bot)
    {
        $bot->typing($chat->id);

        return response()->noContent();
    }

    /** Фото и файлы переписки: у Telegram по file_id, дальше с приватного диска. */
    public function file(Request $request, ChatMessage $message, Bot $bot)
    {
        abort_unless($message->file_id, 404);
        [$contents, $path] = $bot->file($message->file_id, $message->file_unique_id ?? $message->file_id) ?? abort(404);
        $name = $message->file_name ?: basename($path);
        // Шторка документов просит Word, Excel и текст HTML-фрагментом — как у файлов чата и закрытого диска.
        if ($request->boolean('preview')) {
            $tmp = tempnam(sys_get_temp_dir(), 'tg-');
            file_put_contents($tmp, $contents);
            try {
                return OfficePreview::response($tmp, $name);
            } finally {
                @unlink($tmp);
            }
        }
        $mime = $message->file_mime ?: 'application/octet-stream';
        $inline = str_starts_with($mime, 'image/') || $mime === 'application/pdf' || str_starts_with($mime, 'audio/') || str_starts_with($mime, 'video/');

        return response($contents, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => ($inline ? 'inline' : 'attachment')."; filename*=UTF-8''".rawurlencode($name),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    private function feed(Chat $chat)
    {
        return $chat->messages()->with(['author', 'replied']);
    }

    private function fragment(Chat $chat, $messages, array $with = [])
    {
        return view('admin.telegram.messages', ['chat' => $chat, 'messages' => $messages] + $with);
    }

    private function manual(Chat $chat, int $seq): ChatMessage
    {
        $message = $chat->messages()->whereKey($seq)->firstOrFail();
        abort_unless($message->isManual() && $message->message_id, 404);

        return $message;
    }

    /** Список: последние разговоры сверху, поиск по имени, @username и тексту переписки. */
    private function list(Request $request): array
    {
        $q = trim((string) $request->query('q'));
        $like = '%'.mb_strtolower(ltrim($q, '@')).'%';
        $chats = Chat::withLast()
            ->when($q !== '', fn ($c) => $c->where(fn ($w) => $w->whereRaw('lower(name) like ?', [$like])->orWhereRaw('lower(username) like ?', [$like])
                ->orWhereHas('user', fn ($u) => $u->whereRaw('lower(name) like ?', [$like]))
                ->orWhereHas('messages', fn ($m) => $m->whereRaw('lower(text) like ?', [$like]))))
            ->orderByRaw('last_message_at desc nulls last')->orderBy('id')
            ->paginate(ListView::perPage($request, ListView::PER_ROWS))->withPath('/settings/telegram')->withQueryString();

        return ['chats' => $chats, 'q' => $q];
    }

    private function guarded(callable $action): mixed
    {
        try {
            return $action();
        } catch (ValidationException $e) {
            throw new HttpResponseException(response()->json(['message' => $e->validator->errors()->first()], 422));
        }
    }
}
