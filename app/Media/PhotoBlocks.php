<?php

namespace App\Media;

use App\Park\PhotoStage;
use Illuminate\Support\Collection;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Кадры машины блоками по стадии (владелец, 05.10.2026: «два блока — с письма и с приёма, а не одной кучей»): от
 * страховой (письма, загрузка в CRM — кадр без стадии туда же), при приёме, при погрузке, при выдаче. Пустые блоки не
 * рисуются; кадров нет совсем — один пустой блок «от страховой», куда их добавляют. Порядок внутри блока — общий
 * порядок кадров машины (`order_column`), главный кадр — первый видимый в нём.
 */
final class PhotoBlocks
{
    /** @return list<array{stage: PhotoStage, photos: Collection<int, Media>}> */
    public static function of(Collection $photos): array
    {
        $blocks = [];
        foreach (PhotoStage::cases() as $stage) {
            $own = $photos->filter(fn (Media $m) => PhotoStage::of($m) === $stage)->values();
            if ($own->isNotEmpty()) {
                $blocks[] = ['stage' => $stage, 'photos' => $own];
            }
        }

        return $blocks ?: [['stage' => PhotoStage::Vendor, 'photos' => collect()]];
    }

    /**
     * Новый общий порядок, когда переставили кадры одного блока: они встают на свои же места в общей ленте, остальные не
     * двигаются. `main` — звезда: выбранный кадр (первый в блоке) встаёт первым во всей ленте и становится главным.
     *
     * @param  list<int>  $all  общий порядок кадров машины
     * @param  list<int>  $ids  новый порядок кадров блока
     * @return list<int>
     */
    public static function order(array $all, array $ids, bool $main = false): array
    {
        $moving = array_flip($ids);
        $queue = $ids;
        $full = [];
        foreach ($all as $id) {
            $full[] = isset($moving[$id]) ? array_shift($queue) : $id;
        }

        return $main && $ids ? [$ids[0], ...array_values(array_diff($full, [$ids[0]]))] : $full;
    }
}
