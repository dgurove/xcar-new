<?php

namespace App\Offers;

use App\Garage\CarLine;
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
            // Альфа: забирать после оплаты (10.10.2026) — ещё не время, чей бы ход вывоза ни был.
            self::unpaid($offer) => ['после оплаты', 'plain'],
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
        return ! self::unpaid($offer) && (bool) $offer->position(Track::Service)?->stage->exitsFor(Actor::Keeper, $offer->pickupDestination())->isNotEmpty();
    }

    /** Сделка Альфы ещё не оплачена — забирать рано (`Deal::mayPickUp`). */
    private static function unpaid(Offer $offer): bool
    {
        $deal = $offer->deal;

        return $deal && ! $deal->setRelation('offer', $offer)->mayPickUp();
    }

    /**
     * Вывоз строкой «Работы → Вывоза» (таблица, плитки, строки): слово, класс цвета и дни на шаге («5 дней» без переноса).
     * До «Вывоза и осмотра» — блок маршрута словом: «автомобиль у владельца», «вывоз автомобиля».
     *
     * @return array{0: string, 1: string, 2: ?string}
     */
    public static function crmLine(Offer $offer): array
    {
        [$word, $tone] = self::of($offer);
        if ($tone === 'plain') {
            $word = mb_strtolower($offer->position(Track::Service)?->stage->block?->name ?? $word);
        }
        $days = self::days($offer);

        return [$word, match ($tone) {
            'urgent' => 'text-urgent', 'open' => 'text-accent-text', default => 'text-ink-muted'
        }, $days ? CarLine::days($days) : null];
    }

    /** Сколько дней на текущем шаге вывоза. */
    public static function days(Offer $offer): ?int
    {
        $at = $offer->position(Track::Service)?->block_entered_at;

        return $at ? (int) $at->copy()->startOfDay()->diffInDays(now()->startOfDay()) + 1 : null;
    }
}
