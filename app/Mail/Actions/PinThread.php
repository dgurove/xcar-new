<?php

namespace App\Mail\Actions;

use App\Mail\Attachment;
use App\Mail\Blobs;
use App\Mail\Imap;
use App\Mail\Message;
use App\Mail\Parts;
use App\Mail\Thread;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Ветка привязана к машине — её файлы должны пережить чистку ящика.
 * Забираем из ящика каждое ещё не закреплённое вложение и кладём в blobs
 * одним соединением на аккаунт. Возвращает число закреплённых. `message()` — то же для одного письма:
 * кандидат «Из писем» ещё ни к чему не привязан, а кадры для превью нужны уже сейчас.
 */
final class PinThread
{
    public function __construct(private Parts $parts) {}

    public function __invoke(Thread $thread): int
    {
        return $this->pin(Attachment::whereHas('message', fn ($q) => $q->where('thread_id', $thread->id)));
    }

    public function message(Message $message): int
    {
        return $this->pin(Attachment::where('message_id', $message->id));
    }

    private function pin(Builder $query): int
    {
        $pending = $query->whereNull('blob_sha')->where('is_inline', false)->whereNotNull('section')
            ->whereHas('message', fn ($q) => $q->whereNotNull('imap_uid')->whereNotNull('folder_id'))
            ->with(['message.folder', 'message.account'])->get()
            // История ящика: до даты «Файлы из писем с» файлы остаются в ящике, на диск не ложатся.
            ->reject(fn (Attachment $a) => $a->message->filesFrozen());
        if ($pending->isEmpty()) {
            return 0;
        }
        $pinned = 0;
        foreach ($pending->groupBy(fn (Attachment $a) => $a->message->account_id) as $accountId => $group) {
            /** @var Message $first */
            $first = $group->first()->message;
            $imap = new Imap($first->account, 120);
            try {
                foreach ($group as $attachment) {
                    // Замок ящика — на одно вложение: между ними успевает пройти клик сотрудника по другому файлу.
                    $contents = Cache::lock("imap-parts:{$accountId}", 120)->block(120, fn () => $this->parts->fetch($attachment, $imap));
                    if ($contents === null) {
                        Log::warning('Почта: вложение не закрепилось', ['attachment' => $attachment->id]);

                        continue;
                    }
                    $attachment->forceFill(['blob_sha' => Blobs::put($contents), 'pinned_at' => now(), 'size' => strlen($contents)])->save();
                    $pinned++;
                }
            } finally {
                $imap->disconnect();
            }
        }

        return $pinned;
    }
}
