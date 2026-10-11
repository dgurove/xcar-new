<?php

namespace App\Notifications;

use App\Support\Money;

/** Админ отклонил оценку: оцените ещё раз, срок прежний. */
final class EstimateRejectedNotice extends EstimateNotice
{
    public function title(): string
    {
        return ($this->estimate->isCorrection() ? 'Цену ' : 'Оценку ').$this->estimate->offer->titleWithYear().' отклонили';
    }

    public function text(): ?string
    {
        return ($this->estimate->isCorrection() ? 'Предложите снова ' : 'Оцените ещё раз ').$this->due();
    }

    public function toTelegram(): ?array
    {
        return ['title' => $this->title(), 'lines' => $this->lines(
            $this->estimate->rejected_amount ? 'Ваша '.($this->estimate->isCorrection() ? 'цена ' : 'оценка ').Money::rub($this->estimate->rejected_amount) : null,
            $this->text(),
        ), 'button' => $this->estimate->isCorrection() ? 'Проверить' : 'Оценить'];
    }
}
