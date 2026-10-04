<?php

namespace App\Media;

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

    /** «Показать все» / «Скрыть все»: одним значением, без лишней записи у тех, что уже такие. */
    public static function set(iterable $media, bool $hidden): void
    {
        foreach ($media as $m) {
            if (self::is($m) !== $hidden) {
                $m->setCustomProperty('hidden', $hidden)->save();
            }
        }
    }

    /** Глаз: переключить и сохранить. */
    public static function toggle(Media $media): void
    {
        $media->setCustomProperty('hidden', ! self::is($media))->save();
    }
}
