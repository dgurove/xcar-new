<?php

namespace App\Offers;

use App\Support\Money;
use App\Users\Role;
use App\Users\User;

/**
 * Какие цены оффера видит человек. Одна дверь для карточки, страницы, поиска,
 * подписи шеринга и фильтров — иначе формула расползается по вьюхам.
 *
 * На xcar закупочной нет ни у кого (решение владельца 30.09.2026): сотрудник и менеджер видят заявленная → продажи
 * одной строкой «97 000 → 160 000 ₽» (подписанную «заявленную» строкой ниже владелец не принял), покупатель — только цену продажи,
 * посетитель, гость и галерея — ничего. Закупочная → продажи с заявленной чипом — только в карточке строки CRM (crm).
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

    public static function for(Offer $offer, ?User $user, bool $crm = false): self
    {
        if (! $user || $offer->isGallery() || ! $user->canSeePrices()) {
            return new self(false, null, null, (bool) $offer->prices_include_vat, null);
        }
        $staffCrm = $crm && $user->isStaff();
        $from = match (true) {
            $staffCrm => $offer->floor_price,
            $user->isAdmin(), $user->isManager() => $offer->declaredPrice(),
            default => null,
        };
        $declared = $staffCrm && $offer->declaredPrice() !== $offer->floor_price ? $offer->declaredPrice() : null;

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
        return Money::nums($value);
    }
}
