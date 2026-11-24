<?php

namespace App\Media;

use App\Cars\Car;
use App\Offers\EstimateState;
use App\Offers\Offer;
use App\Users\User;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * Кому кадр без водяного знака (24.11.2026, владелец: «вотермарки — для менеджеров, пока они оценку не дадут; как только оценку
 * менеджер дал, для него знак снимается; зачем они в админке»). Знак впечён в сам файл (`StampPhoto`) и держится, пока хоть один
 * оценивает (`EstimateLock`) или стоит «Запретить шеринг»; чистая копия — на закрытом диске, раздаёт её `CleanPhotoController`.
 * - сотрудник (админ, модератор) — всегда;
 * - менеджер — когда сам по этой машине уже ответил (оценил, отказался) и сейчас не оценивает, а ручного «Запретить шеринг» нет;
 * - остальные — кадр со знаком, как есть.
 */
final class CleanFor
{
    public static function allows(?User $user, Media $media): bool
    {
        if (! $user || ! $media->getCustomProperty('watermarked', false)) {
            return false;
        }
        if ($user->isStaff()) {
            return true;
        }
        if (! $user->isManager() || $media->model_type !== (new Car)->getMorphClass()) {
            return false;
        }

        return self::answered((int) $media->model_id, (int) $user->id);
    }

    /** Ответил по машине и сейчас не оценивает; раз на запрос — каталог спрашивает по кадру. */
    private static function answered(int $carId, int $userId): bool
    {
        return once(function () use ($carId, $userId) {
            $offer = Offer::where('car_id', $carId)->first(['id', 'share_locked']);
            if (! $offer || $offer->share_locked) {
                return false;
            }
            $states = $offer->estimates()->where('user_id', $userId)->pluck('state')->map(fn ($s) => $s instanceof EstimateState ? $s : EstimateState::from($s));

            return $states->isNotEmpty() && ! $states->contains(EstimateState::Asked);
        });
    }
}
