<?php

namespace App\Chats\Actions;

use App\Chats\AuthorKind;
use App\Chats\Chat;
use App\Chats\Events\ChatMessagePosted;
use App\Chats\Message;
use App\Media\PhotoIngest;
use App\Users\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mime\MimeTypes;

/** Сообщение в чат: номер под блокировкой строки чата, файлы на закрытом диске под своим именем. */
final class PostMessage
{
    /**
     * Фото ленты — ужимаются в webp. Остальное — любым файлом как есть (25.11.2026: доверенность Word не прикладывалась, «Сюда
     * не грузит», ушла в WhatsApp; владелец: «зачем вообще ограничивать, в мессенджерах перечень гораздо шире»). Отдаёт
     * `ChatController::file`: не фото и не PDF — только скачать (`attachment`, `nosniff`), SVG — не фото (`File::isImage`).
     */
    private const PHOTOS = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/heic', 'image/heif'];

    /** Предел на файл, МБ: 40 — как у документов ТС; запрос целиком держит `post_max_size` 64M. */
    public const MAX_MB = 40;

    /** @param  list<UploadedFile>  $files */
    public function __invoke(Chat $chat, ?User $by, ?string $text, array $files = [], ?int $replyTo = null, ?int $requirementId = null): Message
    {
        $text = trim((string) $text);
        if ($text === '' && ! $files) {
            throw ValidationException::withMessages(['text' => 'Пустое сообщение']);
        }
        foreach ($files as $file) {
            if (! $file->isValid() || $file->getSize() > self::MAX_MB * 1024 * 1024) {
                throw ValidationException::withMessages(['files' => 'Файл '.$file->getClientOriginalName().' больше '.self::MAX_MB.' МБ']);
            }
        }

        $message = DB::transaction(function () use ($chat, $by, $text, $files, $replyTo, $requirementId) {
            $chat = Chat::whereKey($chat->id)->lockForUpdate()->firstOrFail();
            $kind = $chat->isCounterpart($by) ? AuthorKind::Staff : AuthorKind::Participant;
            $seq = $chat->messages_count + 1;
            // Ответ — только на сообщение этого чата; чужой номер молча отбрасывается.
            $replyTo = $replyTo && $replyTo < $seq ? $replyTo : null;
            $message = $chat->messages()->create(['seq' => $seq, 'author_id' => $by?->id, 'author_kind' => $kind, 'text' => $text ?: null, 'reply_to' => $replyTo,
                // С кнопками шага (CRM, чип «С кнопками», 10.11.2026) — только сотрудник; что просьба этого чата, проверил контроллер.
                'requirement_id' => $kind === AuthorKind::Staff ? $requirementId : null]);
            foreach ($files as $file) {
                $mime = (string) $file->getMimeType();
                // Кадр с телефона — 1600 px webp, как и везде; исходник в чате не нужен. Не прочёлся как фото — уходит файлом.
                $webp = null;
                if (in_array($mime, self::PHOTOS, true)) {
                    try {
                        $webp = app(PhotoIngest::class)->shrink($file->getRealPath());
                    } catch (\RuntimeException) {
                        $webp = null;
                    }
                }
                if ($webp) {
                    $path = 'chats/'.$chat->id.'/'.Str::uuid().'.webp';
                    Storage::disk('private')->put($path, file_get_contents($webp));
                    @unlink($webp);
                    $mime = 'image/webp';
                } else {
                    // Расширение — по имени (`guessExtension` у docx дал бы «zip»); общий тип — по расширению.
                    $ext = strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', $file->getClientOriginalExtension())) ?: 'bin';
                    if (in_array($mime, ['', 'application/zip', 'application/octet-stream'], true)) {
                        $mime = MimeTypes::getDefault()->getMimeTypes($ext)[0] ?? ($mime ?: 'application/octet-stream');
                    }
                    $path = 'chats/'.$chat->id.'/'.Str::uuid().'.'.mb_substr($ext, 0, 10);
                    Storage::disk('private')->put($path, $file->getContent());
                }
                $message->files()->create(['name' => mb_substr($file->getClientOriginalName(), 0, 255), 'mime' => $mime, 'size' => Storage::disk('private')->size($path), 'path' => $path]);
            }
            // Своё сообщение — своя сторона дочитала всё; у другой непрочитанного стало на одно больше.
            $chat->update([
                'messages_count' => $seq,
                'last_message_at' => now(),
                'unread_for_user' => $kind === AuthorKind::Participant ? 0 : $chat->unread_for_user + 1,
                'unread_for_staff' => $kind === AuthorKind::Participant ? $chat->unread_for_staff + 1 : 0,
                $kind === AuthorKind::Participant ? 'read_seq_user' : 'read_seq_staff' => $seq,
            ]);

            return $message;
        });
        // После коммита: иначе браузер придёт за «всё после N» раньше, чем сообщение видно.
        ChatMessagePosted::dispatch($message->load('chat.offer', 'chat.user', 'chat.manager', 'files'));
        // Сотрудник ответил в чате площадки — автоответ «Получили Ваше сообщение…» больше не нужен.
        if ($message->author_kind === AuthorKind::Staff && $by && ! $message->chat->manager_id) {
            app(AutoReply::class)->retract($message->chat);
        }

        return $message;
    }
}
