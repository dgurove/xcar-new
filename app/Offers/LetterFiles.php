<?php

namespace App\Offers;

use App\Mail\Attachment;
use App\Mail\Message;
use Illuminate\Support\Collection;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Вложения письма поставщика — показать менеджеру любое, в любом формате (25.11.2026, владелец: «почему показать можно только
 * „одобренный“ формат? прислали HEIC — никакой возможности; отправят файл в неизвестном формате — почему я не могу показать его
 * менеджеру как файл?»). Окно письма (`x-deal.manager-view`) перечисляет вложения самого письма, а не то, что из них сделал разбор
 * (кадры, распакованные архивы, перекодированные HEIC). Показать — `ensure`: файл как есть в документы машины (имя и байты
 * письма), дальше обычный показ (`SetManagerView` → `Disclose`).
 */
final class LetterFiles
{
    /** Вложения письма, кроме встроенных в текст (логотипы подписи). @return Collection<int, Attachment> */
    public static function of(Message $message): Collection
    {
        return $message->attachments->reject(fn (Attachment $a) => $a->is_inline)->values();
    }

    /** Уже лежит в документах машины: этим вложением (`attachment`) или теми же байтами (`sha`). */
    public static function existing(Offer $offer, Attachment $a): ?Media
    {
        $papers = $offer->getMedia('papers');

        return $papers->first(fn (Media $m) => (int) $m->getCustomProperty('attachment') === $a->id)
            ?? ($a->blob_sha ? $papers->first(fn (Media $m) => $m->getCustomProperty('sha') === $a->blob_sha) : null);
    }

    /** Документ машины для вложения: есть — он, нет — файл как есть, с исходным именем. */
    public static function ensure(Offer $offer, Attachment $a): ?Media
    {
        if ($found = self::existing($offer, $a)) {
            return $found;
        }
        $contents = $a->contents();
        if ($contents === null) {
            return null;
        }
        $sha = hash('sha256', $contents);
        if ($same = $offer->getMedia('papers')->first(fn (Media $m) => $m->getCustomProperty('sha') === $sha)) {
            return $same;
        }
        $name = trim((string) $a->filename) ?: 'файл';

        return $offer->carRecord()->addMediaFromString($contents)
            ->usingFileName(preg_replace('/[^\p{L}\p{N}._-]+/u', '-', $name) ?: 'file')
            ->usingName(pathinfo($name, PATHINFO_FILENAME))
            ->withCustomProperties(['sha' => $sha, 'letter' => $a->message_id, 'attachment' => $a->id, 'source' => 'mail'])
            ->toMediaCollection('papers');
    }
}
