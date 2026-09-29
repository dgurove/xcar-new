<?php

namespace App\Offers;

use App\Support\Money;
use App\Users\Role;
use App\Users\User;

/**
 * Какие цены оффера видит человек. Одна дверь для карточки, страницы, поиска,
 * подписи шеринга и фильтров — иначе формула расползается по вьюхам.
 *
 * На xcar закупочной нет ни у кого (решение владельца 30.09.2026): сотрудник видит заявленная → продажи,
 * менеджер — продажи и подписанную «Заявленную» (её он считает закупочной), покупатель — только цену продажи,
 * посетитель, гость и галерея — ничего. Закупочная → продажи с заявленной чипом — только в окошке строки CRM (crm).
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
        if (! $user || $offer->isGallery() || ! $user->role->canSeePrices()) {
            return new self(false, null, null, (bool) $offer->prices_include_vat, null);
        }
        // Менеджеру «от» нет: его закупочная — заявленная, и она идёт подписанной строкой, а не безымянной стрелкой.
        $staffCrm = $crm && $user->isStaff();
        $from = $user->isStaff() ? ($staffCrm ? $offer->floor_price : $offer->declaredPrice()) : null;
        $declared = match (true) {
            $staffCrm => $offer->declaredPrice() !== $offer->floor_price ? $offer->declaredPrice() : null,
            $user->role === Role::Manager => $offer->declaredPrice(),
            default => null,
        };

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
