<?php

namespace App\Media;

use App\Park\PhotoSlot;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Кадр скрыт из показа: глазом (`hidden`), а без отметки — кадр VIN-таблички и документов с приёма на парковке: они для
 * дела, не для покупателя. Глаз открывает и их.
 */
final class Hidden
{
    public static function is(Media $media): bool
    {
        return (bool) $media->getCustomProperty('hidden', in_array($media->getCustomProperty('slot'), [PhotoSlot::VinPlate->value, PhotoSlot::Papers->value], true));
    }

    /** Глаз: переключить и сохранить. */
    public static function toggle(Media $media): void
    {
        $media->setCustomProperty('hidden', ! self::is($media))->save();
    }
}
