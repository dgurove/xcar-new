<?php

namespace App\Offers;

use App\Users\User;
use App\Workflow\Actor;
use App\Workflow\Track;

/**
 * Вывоз словом — для строки «Гаража» и страницы вывоза: забрать (ход ответственного), стоит у
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
            // Забирать рано, чей бы ход вывоза ни был: Альфа — после оплаты (10.10.2026), Каркаде — пока поставщик не согласовал
            // заявку (11.10.2026).
            ($hold = self::hold($offer)) !== null => [$hold, 'plain'],
            $stage->exitsFor(Actor::Keeper, $offer->pickupDestination())->isNotEmpty() => [$who ? 'забирает '.$who : 'забрать', 'urgent'],
            // Шаг продажи сам просит «Автомобиль забрал» (Совкомбанк: без оплаты, сразу) — документы не ждём.
            (bool) $offer->position(Track::Sale)?->stage->isPickupStep() => self::picking($offer, $viewer),
            default => ['готовим документы', 'plain'],
        };
    }

    private static function picking(Offer $offer, ?User $viewer): array
    {
        // Забирает держатель гаража или вывозчик, а без них — менеджер сделки: сотруднику — по имени, ему — «забрать».
        $who = $offer->keeper() ?? $offer->deal?->buyer;

        return [$who && $who->id !== $viewer?->id ? 'забирает '.$who->shortName() : 'забрать', 'urgent'];
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
        return self::hold($offer) === null && (bool) $offer->position(Track::Service)?->stage->exitsFor(Actor::Keeper, $offer->pickupDestination())->isNotEmpty();
    }

    /**
     * Почему забирать пока нельзя — для логистики (10.10.2026: «Забрал» водителя и партнёра держат те же условия, что
     * «Забрал» в CRM): у Альфы — до оплаты, у остальных — пока маршрут вывоза не дошёл до «Вывоза и осмотра» (или шаг
     * продажи сам не просит забрать, как у Совкомбанка). null — можно; ТС уже стоит у нас или у менеджера — тоже можно.
     */
    public static function blocker(Offer $offer): ?string
    {
        $stage = $offer->position(Track::Service)?->stage;
        if ($stage && in_array($stage->car_place, [CarPlace::Keeper, CarPlace::WithUs], true)) {
            return null;
        }
        if (($hold = self::hold($offer)) !== null) {
            return $hold === 'после оплаты' ? 'Забирать после оплаты' : 'Ждём согласия поставщика';
        }
        $ready = ! $stage || $stage->exitsFor(Actor::Keeper, $offer->pickupDestination())->isNotEmpty()
            || (bool) $offer->position(Track::Sale)?->stage->isPickupStep();

        return $ready ? null : 'Документы ещё не готовы';
    }

    /** Забирать рано — почему словом (`Deal::pickupHold`): Альфа не оплачена, Каркаде без согласия поставщика. */
    private static function hold(Offer $offer): ?string
    {
        return $offer->deal?->setRelation('offer', $offer)->pickupHold();
    }

    /** Сколько дней на текущем шаге вывоза. */
    public static function days(Offer $offer): ?int
    {
        $at = $offer->position(Track::Service)?->block_entered_at;

        return $at ? (int) $at->copy()->startOfDay()->diffInDays(now()->startOfDay()) + 1 : null;
    }
}
