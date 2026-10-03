<?php

namespace App\Workflow\Presets;

/** Поставщик выставляет счёт, после оплаты — развилка по месту подписания. «Забираю в гараж» — гаражная ветка. */
final class Alfa extends Route
{
    public function blocks(): array
    {
        return self::withGarageBlocks($this->saleBlocks());
    }

    public function stages(): array
    {
        return $this->head(garage: true)
            + ['confirmed' => $this->confirmed('invoice')]
            + $this->invoiceSegment('signing_place')
            + $this->signingSegment('closed_won', intro: 'Оплата принята. ')
            + self::garageSegment()
            + $this->tail();
    }
}
