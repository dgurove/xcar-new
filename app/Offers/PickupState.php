<?php

namespace App\Offers;

use App\Logistics\TransportState;
use App\Users\User;

/**
 * Где машина и что с вывозом — словом для строки «Гаража» и пути машины. С 17.10.2026 вывоз — задача логистики
 * (`Offer::pickup`): ищем исполнителя, созвонились (дата), везут, стоит у менеджера или у нас. Тон — как у остальных
 * состояний (`x-ui.state`).
 */
final class PickupState
{
    /** @return array{0: string, 1: string} слово и тон */
    public static function of(Offer $offer, ?User $viewer = null): array
    {
        $t = $offer->pickup();
        $who = $t?->executor;
        $mine = $viewer && $who && $who->is($viewer);
        // Забирать рано (Альфа — после оплаты, Каркаде — пока поставщик не согласовал): не «позвонить владельцу»
        // оранжевым, а почему ждём (17.10.2026, прогон: гаражная Альфы сразу после «Принять» звала звонить).
        $deal = $t?->isOpen() && ! $t->state->picked() ? $offer->deal : null;
        $hold = $deal?->isActive() ? $deal->pickupHold() : null;

        return match (true) {
            $offer->car_place === CarPlace::Keeper => self::keeper($offer, $viewer),
            $offer->car_place === CarPlace::WithUs => ['стоит у нас', 'open'],
            $offer->car_place === CarPlace::Ours => ['на парковке', 'open'],
            $offer->car_place === CarPlace::Elsewhere => ['по адресу', 'open'],
            // Между отрезками — на стоянке логиста: менеджеру это «в пути», ему ехать некуда.
            $offer->car_place === CarPlace::Depot => [$viewer?->isStaff() ? 'на стоянке логиста' : 'в пути', 'open'],
            ! $t => ['вывоз не начат', 'plain'],
            $hold !== null => [$hold, 'plain'],
            $t->state === TransportState::Moving => [$mine ? 'везёте' : 'везут', 'open'],
            $t->state === TransportState::Agreed && $t->pickup_at !== null => [($mine ? 'забрать ' : 'вывоз ').$t->pickup_at->translatedFormat('j M'), $mine ? 'urgent' : 'plain'],
            in_array($t->state, [TransportState::Assigned, TransportState::Agreed], true) => [$mine ? 'позвонить владельцу' : 'забирает '.$who?->shortName(), $mine ? 'urgent' : 'plain'],
            default => ['ищем, кто вывезет', 'plain'],
        };
    }

    /** Стоит «у менеджера»: у держателя гаража, иначе у менеджера сделки (`Offer::keeper`) — своему «у вас», другим по имени. */
    private static function keeper(Offer $offer, ?User $viewer): array
    {
        $keeper = $offer->keeper();

        return [match (true) {
            $viewer && $keeper?->id === $viewer->id => 'стоит у вас',
            $viewer && $keeper => 'стоит у '.$keeper->shortName(),
            default => 'стоит у менеджера',
        }, 'open'];
    }

    /** Сколько дней идёт вывоз. */
    public static function days(Offer $offer): ?int
    {
        $at = $offer->pickup()?->created_at;

        return $at ? (int) $at->copy()->startOfDay()->diffInDays(now()->startOfDay()) + 1 : null;
    }
}
