<?php

namespace App\Mail\Actions;

use App\Mail\Attachment;
use App\Mail\Blobs;
use App\Mail\Message;
use App\Mail\Parts;
use App\Mail\Reading\ReadLetter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Заморозка писем закрытой цепочки или выданной ТС: распарсенные поля стираются (остаются смысл, свои слова и
 * номера — заголовок, лента и поиск по номеру работают), скачанные вложения отпускаются (файлы остаются в ящике и
 * тянутся по клику, как у истории), `frozen_at` держит письмо вне `mail:read` и `mail:rebuild`. Текст письма не трогается.
 * Размораживание — чтение заново только этих писем и импорт файлов, если ветка снова у живой ТС.
 */
final class FreezeMessages
{
    public function __construct(private ReadLetter $reader) {}

    /** @param  Collection<int, Message>|list<int>  $messages  письма или их id; тела не загружаются */
    public function freeze(Collection|array $messages): int
    {
        $ids = $messages instanceof Collection ? $messages->pluck('id')->all() : $messages;
        $ids = Message::whereIn('id', $ids)->whereNull('frozen_at')->pluck('id')->all();
        if (! $ids) {
            return 0;
        }
        $cache = Storage::disk(Parts::CACHE_DISK);
        DB::transaction(function () use ($ids, $cache) {
            foreach (Message::whereIn('id', $ids)->select(['id', 'parsed'])->get() as $m) {
                $parsed = $m->parsed ?? [];
                $m->forceFill(['parsed' => array_intersect_key($parsed, array_flip(['intent', 'own_text', 'keys'])), 'frozen_at' => now()])->saveQuietly();
            }
            $pinned = Attachment::whereIn('message_id', $ids)->whereNotNull('blob_sha')->get();
            foreach ($pinned as $a) {
                $sha = $a->blob_sha;
                $a->forceFill(['blob_sha' => null, 'pinned_at' => null])->saveQuietly();
                Blobs::release($sha);
            }
            foreach (Attachment::whereIn('message_id', $ids)->pluck('id') as $id) {
                $cache->delete("mail/{$id}");
            }
        });

        return count($ids);
    }

    /** @param  Collection<int, Message>|list<int>  $messages */
    public function thaw(Collection|array $messages): int
    {
        $ids = $messages instanceof Collection ? $messages->pluck('id')->all() : $messages;
        $n = 0;
        foreach (Message::whereIn('id', $ids)->whereNotNull('frozen_at')->with(['account', 'attachments'])->get() as $m) {
            $m->forceFill(['frozen_at' => null])->saveQuietly();
            $this->reader->apply($m);
            $n++;
        }

        return $n;
    }
}
