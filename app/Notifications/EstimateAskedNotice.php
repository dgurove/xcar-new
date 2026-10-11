<?php

namespace App\Notifications;

use App\Support\Money;

/**
 * Админ попросил менеджера оценить цену продажи (48 часов, «Нужно оценить» сверху «Предложений») или проверить свою —
 * корректировка (11.10.2026): «Согласен» или своя цена.
 */
final class EstimateAskedNotice extends EstimateNotice
{
    public function title(): string
    {
        return ($this->estimate->isCorrection() ? 'Проверьте цену ' : 'Оцените ').$this->estimate->offer->titleWithYear();
    }

    public function text(): ?string
    {
        return $this->estimate->isCorrection()
            ? Money::rub($this->estimate->base_price).', срок '.$this->due()
            : 'Срок '.$this->due();
    }

    public function toTelegram(): ?array
    {
        $correction = $this->estimate->isCorrection();

        return ['title' => $this->title(), 'lines' => $this->lines(
            $correction ? 'Цена XCar '.Money::rub($this->estimate->base_price) : null,
            'Срок '.$this->due(),
        ), 'button' => $correction ? 'Проверить' : 'Оценить'];
    }
}
