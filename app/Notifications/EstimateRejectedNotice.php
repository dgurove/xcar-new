<?php

namespace App\Notifications;

use App\Support\Money;

/** Админ отклонил оценку: оцените ещё раз, срок прежний. */
final class EstimateRejectedNotice extends EstimateNotice
{
    public function title(): string
    {
        return 'Оценку '.$this->estimate->offer->titleWithYear().' отклонили';
    }

    public function text(): ?string
    {
        return 'Оцените ещё раз '.$this->due();
    }

    public function toTelegram(): ?array
    {
        return ['title' => 'Оценку '.$this->estimate->offer->titleWithYear().' отклонили', 'lines' => $this->lines(
            $this->estimate->rejected_amount ? 'Ваша оценка '.Money::rub($this->estimate->rejected_amount) : null,
            'Оцените ещё раз '.$this->due(),
        ), 'button' => 'Оценить'];
    }
}
