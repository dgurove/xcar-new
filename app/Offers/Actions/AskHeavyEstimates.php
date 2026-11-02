<?php

namespace App\Offers\Actions;

use App\Cars\Category;
use App\Http\Admin\OfferController;
use App\Offers\Offer;
use App\Users\User;
use Throwable;

/**
 * Шаг `offers:tick` (02.11.2026, владелец): грузовое и спецтехника (всё, кроме легковых и мото, `Category::isHeavy`) с
 * закупочной и без цены продажи — само на оценку своему менеджеру (`xcar.heavy_estimator`, Роман Корабельников): 48 часов
 * со знаком на фото, как у просьбы админа. Просьба `auto` — его оценка сразу публикует машину ему одному (`FirstLook`). Один
 * раз на машину: была у него просьба по ней (любая, и отказ) — больше не просим. Не оценил — висит админу «просрочено».
 */
final class AskHeavyEstimates
{
    public function __construct(private AskEstimates $ask) {}

    public function __invoke(): int
    {
        $estimator = self::estimator();
        if (! $estimator) {
            return 0;
        }
        $n = 0;
        // «Без продажной цены» и «На оценке у менеджеров» разом: условие «Без продажной» без отсечки открытых просьб.
        OfferController::scopeFor('unpriced', Offer::query()->notEmptyDraft(), admin: false)
            ->whereCar(fn ($c) => $c->whereIn('category', Category::heavy()))
            ->whereDoesntHave('estimates', fn ($e) => $e->where('user_id', $estimator->id))
            ->lazyById()->each(function (Offer $offer) use ($estimator, &$n) {
                try {
                    ($this->ask)($offer, [$estimator], null, auto: true);
                    $n++;
                } catch (Throwable $e) {
                    report($e);
                }
            });

        return $n;
    }

    public static function estimator(): ?User
    {
        $user = User::find(config('xcar.heavy_estimator'));

        return $user?->isManager() ? $user : null;
    }
}
