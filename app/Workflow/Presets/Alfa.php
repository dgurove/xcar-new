<?php

namespace App\Workflow\Presets;

/** Поставщик выставляет счёт, после оплаты — развилка по месту подписания. */
final class Alfa extends Route
{
    public function blocks(): array
    {
        return $this->saleBlocks();
    }

    public function stages(): array
    {
        return $this->head()
            + ['confirmed' => $this->confirmed('invoice')]
            + $this->invoiceSegment('signing_place')
            + $this->signingSegment('closed_won', intro: 'Оплата принята. ')
            + $this->tail();
    }
}
