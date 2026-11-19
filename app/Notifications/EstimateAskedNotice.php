<?php

namespace App\Notifications;

use App\Offers\Estimate;
use App\Support\Money;

/**
 * Админ попросил менеджера оценить цену продажи (срок — выбор админа, по умолчанию двое суток, «Нужно оценить» сверху «Предложений») или проверить свою —
 * корректировка (11.10.2026): «Согласен» или своя цена.
 */
final class EstimateAskedNotice extends EstimateNotice
{
    public function label(): string
    {
        return 'Просьба оценить';
    }

    public function title(): string
    {
        return ($this->estimate->isCorrection() ? 'Проверить цену ' : 'Оценить ').$this->estimate->offer->titleWithYear();
    }

    public function text(): ?string
    {
        return $this->estimate->isCorrection()
            ? Money::rub($this->estimate->base_price).', срок '.$this->due()
            : 'Срок '.$this->due().($this->estimate->exclusive_days ? ', после оценки — '.Estimate::daysWord($this->estimate->exclusive_days).' персонально в продаже' : '');
    }

    public function toTelegram(): ?array
    {
        $correction = $this->estimate->isCorrection();

        return ['title' => $this->title(), 'lines' => $this->lines(
            $correction ? 'Цена XCar '.Money::rub($this->estimate->base_price) : null,
            'Срок '.$this->due(),
            ! $correction && $this->estimate->exclusive_days ? 'После оценки — '.Estimate::daysWord($this->estimate->exclusive_days).' персонально' : null,
        ), 'button' => 'Открыть',
            // Ответ прямо из бота (19.11.2026, владелец выбрал «кнопки в уведомлении»): «Согласен» — цена XCar, «Своя цена» /
            // «Ввести цену» — бот спрашивает число ответом (`UpdateHandler::estimate`).
            'keyboard' => [$correction
                ? [['text' => 'Согласен, '.Money::rub($this->estimate->base_price), 'callback_data' => "estimate:{$this->estimate->id}:agree"], ['text' => 'Своя цена', 'callback_data' => "estimate:{$this->estimate->id}:price"]]
                : [['text' => 'Ввести цену', 'callback_data' => "estimate:{$this->estimate->id}:price"]]]];
    }
}
