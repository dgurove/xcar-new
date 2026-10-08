<?php

namespace App\Offers;

use App\Users\User;
use App\Workflow\Actor;
use App\Workflow\Track;

/**
 * Вывоз словом — для строки «Гаража», страницы вывоза и «Работы → Вывоза»: забрать (ход ответственного), стоит у
 * менеджера или у нас, а до того — готовим документы. Тон — как у остальных состояний (`x-ui.state`).
 */
final class PickupState
{
    /** @return array{0: string, 1: string} слово и тон */
    public static function of(Offer $offer, ?User $viewer = null): array
    {
        $position = $offer->position(Track::Service);
        $stage = $position?->stage;
        $mine = $viewer && $offer->evacuator_id === $viewer->id;
        // Смотрит не вывозчик (админ) — кто забирает, по имени: «забирает Иван М.».
        $who = $viewer && ! $mine ? $offer->evacuator?->shortName() : null;

        return match (true) {
            ! $stage => ['вывоз не начат', 'plain'],
            $stage->car_place === CarPlace::Keeper => self::keeper($offer, $viewer),
            $stage->car_place === CarPlace::WithUs => ['стоит у нас', 'open'],
            $stage->exitsFor(Actor::Keeper, $offer->pickupDestination())->isNotEmpty() => [$who ? 'забирает '.$who : 'забрать', 'urgent'],
            default => ['готовим документы', 'plain'],
        };
    }

    /** Стоит «у менеджера»: у держателя гаража, иначе у вывозчика (`Offer::keeper`) — своему «у вас», другим по имени. */
    private static function keeper(Offer $offer, ?User $viewer): array
    {
        $keeper = $offer->keeper();

        return [match (true) {
            $viewer && $keeper?->id === $viewer->id => 'стоит у вас',
            $viewer && $keeper => 'стоит у '.$keeper->shortName(),
            default => 'стоит у менеджера',
        }, 'open'];
    }

    /** Ход ответственного: «забрать» — его бейдж в табе «Гараж». */
    public static function awaits(Offer $offer): bool
    {
        return (bool) $offer->position(Track::Service)?->stage->exitsFor(Actor::Keeper, $offer->pickupDestination())->isNotEmpty();
    }

    /** Сколько дней на текущем шаге вывоза. */
    public static function days(Offer $offer): ?int
    {
        $at = $offer->position(Track::Service)?->block_entered_at;

        return $at ? (int) $at->copy()->startOfDay()->diffInDays(now()->startOfDay()) + 1 : null;
    }
}
