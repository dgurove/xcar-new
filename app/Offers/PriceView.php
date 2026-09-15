<?php

namespace App\Offers;

use App\Users\Role;
use App\Users\User;

/**
 * Какие цены оффера видит человек. Одна дверь для карточки, страницы, поиска,
 * подписи шеринга и фильтров — иначе формула расползается по вьюхам.
 *
 * Сотрудник: закупочная → продажи (и заявленная чипом, когда отличается).
 * Менеджер: заявленная → продажи — заявленную он считает закупочной.
 * Покупатель: только цена продажи. Посетитель, гость и галерея — ничего.
 */
final class PriceView
{
    private function __construct(
        public readonly bool $visible,
        public readonly ?int $from,
        public readonly ?int $to,
        public readonly bool $vat,
        public readonly ?int $declared,
    ) {}

    public static function for(Offer $offer, ?User $user): self
    {
        if (! $user || $offer->isGallery() || ! $user->role->canSeePrices()) {
            return new self(false, null, null, (bool) $offer->prices_include_vat, null);
        }
        $from = match (true) {
            $user->isStaff() => $offer->floor_price,
            $user->role === Role::Manager => $offer->declaredPrice(),
            default => null,
        };
        $declared = $user->isStaff() && $offer->publish_price && $offer->publish_price !== $offer->floor_price ? $offer->publish_price : null;

        return new self(true, $from ?: null, $offer->asking_price ?: null, (bool) $offer->prices_include_vat, $declared);
    }

    /** Есть что показать: цена продажи известна и человеку положено её видеть. */
    public function shown(): bool
    {
        return $this->visible && $this->to !== null;
    }

    /** Стрелка нужна, когда есть «от» и она отличается от «до». */
    public function withFrom(): bool
    {
        return $this->from !== null && $this->from !== $this->to;
    }

    public static function money(?int $value): string
    {
        return $value === null ? '' : number_format($value, 0, '', ' ');
    }
}
