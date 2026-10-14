<?php

namespace App\Media;

use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Документ открыт менеджерам при ТС — держателю гаража, ответственному за вывоз, менеджеру сделки (`Offer::worksWith`).
 * Без отметки документ видят только сотрудники. Ставит админ в «Документах» редактора; документ — машины (`Cars\Car`),
 * у продажи и парковки он один, копий нет.
 */
final class ForManagers
{
    public static function is(Media $media): bool
    {
        return (bool) $media->getCustomProperty('managers', false);
    }

    public static function toggle(Media $media): bool
    {
        $media->setCustomProperty('managers', $on = ! self::is($media))->save();

        return $on;
    }
}
