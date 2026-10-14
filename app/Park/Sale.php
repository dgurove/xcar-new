<?php

namespace App\Park;

use App\Offers\Offer;
use App\Support\Money;
use App\Users\User;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

/**
 * ТС парковки в продаже. Машина одна (`Cars\Car`, 14.10.2026): у ТС и её предложения одна запись машины — тождество,
 * фото и документы общие, зеркал и переносов файлов нет. Здесь — правило закупочной от оценочной и запись в истории ТС,
 * когда продажа кончилась.
 */
final class Sale
{
    /**
     * Продажа кончилась — предложение в архиве, снято или удаляется: в истории ТС «Снята с продажи». Машина, её кадры и
     * документы остаются у машины; парковку больше ничего не трогает.
     */
    public static function end(Offer $offer, ?User $by = null): void
    {
        $vehicle = $offer->car_id ? Vehicle::withoutGlobalScope(City::SCOPE)->where('car_id', $offer->car_id)->first() : null;
        $vehicle?->log(EventType::Unlinked, $by ?? auth()->user(), ['number' => $offer->number]);
    }

    /**
     * Файл парковки, а не продажи (снят на парковке, пришёл письмом её ящика — метка `park`, её ставит дорожка парковки,
     * `Vehicle::carMediaMark`): CRM его прячет, но не удаляет.
     */
    public static function parkOwned(Media $media): bool
    {
        return (bool) $media->getCustomProperty('park');
    }

    /**
     * Закупочная из оценочной стоимости (владелец, 04.10.2026): до 500 000 — плюс 50 000, от 500 000 до 2 000 000 — плюс
     * 10 %, от 2 000 000 — плюс 200 000; вверх до тысячи. Правило продублировано в `sale_floor_controller.js` (шторка
     * «В продажу» пересчитывает на лету) — менять вместе.
     */
    public static function floorFrom(int $value): int
    {
        $price = match (true) {
            $value < 500_000 => $value + 50_000,
            $value < 2_000_000 => $value * 1.1,
            default => $value + 200_000,
        };

        return (int) (ceil(round($price, 2) / 1000) * 1000);
    }

    /** Как получена закупочная (13.10.2026, шторка «В продажу»): `rule` — по правилу от оценочной, `value` — равна ей, `own` — своя. */
    public static function floorMode(?int $floor, ?int $value): string
    {
        return match (true) {
            ! $value => 'own',
            ! $floor, $floor === self::floorFrom($value) => 'rule',
            $floor === $value => 'value',
            default => 'own',
        };
    }

    /**
     * Закупочная словами для письма страховой: «756 000 ₽ (+10%)» — наценка к оценочной по тому же правилу, что
     * `floorFrom` (+50 000 ₽, +10%, +200 000 ₽). Вписанная рукой (не по правилу) или без оценочной — без скобок.
     */
    public static function floorLabel(?int $floor, ?int $value): string
    {
        if (! $floor) {
            return '';
        }
        $markup = $value && $floor === self::floorFrom($value) ? match (true) {
            $value < 500_000 => '+'.Money::rub(50_000),
            $value < 2_000_000 => '+10%',
            default => '+'.Money::rub(200_000),
        } : null;

        return Money::rub($floor).($markup ? " ({$markup})" : '');
    }

    /**
     * Закупочная после смены оценочной: пустая или посчитанная от прежней оценочной — заново от новой; вписанная рукой
     * остаётся. null — трогать не нужно.
     */
    public static function floorFor(?int $floor, ?int $was, ?int $value): ?int
    {
        if (! $value || $value === $was) {
            return null;
        }
        $auto = $floor === null || ($was && $floor === self::floorFrom($was));

        return $auto ? self::floorFrom($value) : null;
    }
}
