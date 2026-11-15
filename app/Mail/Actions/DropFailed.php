<?php

namespace App\Mail\Actions;

use App\Mail\Attachment;
use App\Mail\Blobs;
use App\Mail\Direction;
use App\Mail\Message;
use App\Mail\SendState;
use App\Mail\Thread;
use App\Mail\Threads;
use App\Users\User;
use Illuminate\Support\Facades\DB;

/**
 * Неудачное письмо уступает место повторной отправке из того же редактора (15.11.2026, начальник «постоянно переживает,
 * отправлено ли письмо»: редактор теперь ждёт настоящей отправки, «не ушло» — причина над кнопкой, поправил и отправил
 * снова; без этого в ветке оставалось бы «Не ушло» рядом с ушедшим). Только своё, только `failed` и только не
 * побывавшее в ящике. Файлы вложений не трогаются: новое письмо приложило те же файлы outbox (`Composer::attach`), а
 * `Attachment::deleted` стёр бы их с диска — строки удаляются запросом, общие blob отпускаются руками.
 * Отдаёт ветку письма — новое ляжет в неё, если своей не назвали.
 */
final class DropFailed
{
    public function __invoke(?int $id, User $by): ?Thread
    {
        $message = $id ? Message::with('thread')->whereKey($id)->where('created_by', $by->id)->where('direction', Direction::Out->value)
            ->where('send_state', SendState::Failed->value)->whereNull('imap_uid')->first() : null;
        if (! $message) {
            return null;
        }
        $thread = $message->thread;
        DB::transaction(function () use ($message) {
            $attachments = Attachment::where('message_id', $message->id);
            foreach ((clone $attachments)->whereNotNull('blob_sha')->pluck('blob_sha') as $sha) {
                Blobs::release($sha);
            }
            $attachments->delete();
            $message->addresses()->delete();
            $message->delete();
        });
        if ($thread) {
            app(Threads::class)->refresh($thread);
        }

        return $thread;
    }
}
