<?php

namespace App\Media;

use App\Park\PhotoStage;
use App\Support\Ago;
use Illuminate\Support\Carbon;
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
    /**
     * `when` — день, в который загружено больше всего кадров блока (13.11.2026, начальник: «Фото от страховой 10.11»,
     * «Фото при приёме 12.11»); поровну — более поздний; пустой блок — null.
     *
     * @param  list<PhotoStage>  $always  блоки, что стоят и пустыми (куда добавляют: `PhotoStage::addable`)
     * @return list<array{stage: PhotoStage, photos: Collection<int, Media>, when: ?string}>
     */
    public static function of(Collection $photos, array $always = []): array
    {
        $blocks = [];
        foreach (PhotoStage::cases() as $stage) {
            $own = $photos->filter(fn (Media $m) => PhotoStage::of($m) === $stage)->values();
            if ($own->isNotEmpty() || in_array($stage, $always, true)) {
                $blocks[] = ['stage' => $stage, 'photos' => $own, 'when' => self::day($own)];
            }
        }

        return $blocks ?: [['stage' => PhotoStage::Vendor, 'photos' => collect(), 'when' => null]];
    }

    /** «10.11» — день большинства кадров (`Ago::day`, в другом году с годом). */
    public static function day(Collection $photos): ?string
    {
        $days = $photos->filter(fn (Media $m) => $m->created_at)
            ->countBy(fn (Media $m) => $m->created_at->copy()->timezone(config('app.timezone'))->toDateString());
        if ($days->isEmpty()) {
            return null;
        }
        $top = $days->max();
        $day = $days->filter(fn (int $n) => $n === $top)->keys()->sortDesc()->first();

        return Ago::day(Carbon::parse($day));
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
