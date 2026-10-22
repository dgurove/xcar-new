<?php

namespace App\Users\Actions;

use App\Cars\Region;
use App\Users\User;
use Illuminate\Validation\ValidationException;

/**
 * Регионы менеджера (22.10.2026, Сахиб с Урала: «слишком много машин, которые мне не нужны»): откуда ему показывать
 * предложения. Все отмечены — вся Россия (`null`); ни одного — нельзя. Кому видна машина, пересчитывает `User::saved`
 * (`SyncViewers::all`): машина не из его регионов уходит из его волн показа, у его покупателей тоже.
 */
final class SetOfferRegions
{
    /** @param  list<int>  $ids */
    public function __invoke(User $user, array $ids): void
    {
        $all = Region::pluck('id')->all();
        $ids = array_values(array_intersect($all, array_map('intval', $ids)));
        if (! $ids) {
            throw ValidationException::withMessages(['regions' => 'Отметьте хотя бы один регион']);
        }
        sort($ids);
        $user->update(['offer_regions' => count($ids) === count($all) ? null : $ids]);
    }

    /** Подпись строки: «Вся Россия», «Уральский и Приволжский округа», «12 регионов». */
    public static function label(User $user): string
    {
        $ids = $user->offerRegions();
        if (! $ids) {
            return 'Вся Россия';
        }
        $regions = Region::whereIn('id', $ids)->get(['id', 'plate', 'name', 'short']);
        $whole = [];
        foreach (Region::DISTRICTS as $name => $plates) {
            $in = $regions->filter(fn ($r) => in_array(str_pad((string) $r->plate, 2, '0', STR_PAD_LEFT), $plates, true))->count();
            if ($in === count($plates)) {
                $whole[] = $name;
            }
        }
        $rest = $regions->reject(fn ($r) => in_array($r->district(), $whole, true));
        $tail = match (true) {
            $rest->isEmpty() => '',
            $rest->count() <= 2 => $rest->pluck('short')->implode(', '),
            default => ($whole ? 'ещё ' : '').$rest->count().' '.\App\Support\Plural::of($rest->count(), ['регион', 'региона', 'регионов']),
        };
        $head = match (count($whole)) {
            0 => '',
            1 => $whole[0].' округ',
            default => implode(', ', array_slice($whole, 0, -1)).' и '.end($whole).' округа',
        };

        return implode(', ', array_filter([$head, $tail]));
    }
}
