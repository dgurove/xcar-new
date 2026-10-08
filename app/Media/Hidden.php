<?php

namespace App\Media;

use Illuminate\Support\Facades\DB;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Кадр скрыт из показа: глазом (`hidden`), а без отметки — кадр VIN-таблички и документов с приёма на парковке: они для
 * дела, не для покупателя. Глаз открывает и их.
 */
final class Hidden
{
    public static function is(Media $media): bool
    {
        return (bool) $media->getCustomProperty('hidden', false);
    }

    /**
     * «Показать все» / «Скрыть все»: одним значением, без лишней записи у тех, что уже такие. Одной транзакцией: на HDD
     * прода каждая запись — свой COMMIT 160–290 мс, и 30 кадров шли 5–9 с, а кнопка всё это время молчала (08.10.2026,
     * начальник: «Скрыть все» часто не работает).
     */
    public static function set(iterable $media, bool $hidden): void
    {
        DB::transaction(function () use ($media, $hidden) {
            foreach ($media as $m) {
                if (self::is($m) !== $hidden) {
                    $m->setCustomProperty('hidden', $hidden)->save();
                }
            }
        });
    }

    /** Глаз: переключить и сохранить. */
    public static function toggle(Media $media): void
    {
        $media->setCustomProperty('hidden', ! self::is($media))->save();
    }
}
