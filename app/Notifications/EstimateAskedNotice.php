<?php

namespace App\Notifications;

/** Админ попросил менеджера оценить цену продажи: 48 часов, «Нужно оценить» сверху «Предложений». */
final class EstimateAskedNotice extends EstimateNotice
{
    public function title(): string
    {
        return 'Оцените '.$this->estimate->offer->titleWithYear();
    }

    public function text(): ?string
    {
        return 'Срок '.$this->due();
    }

    public function toTelegram(): ?array
    {
        return ['title' => 'Оцените '.$this->estimate->offer->titleWithYear(), 'lines' => $this->lines('Срок '.$this->due()), 'button' => 'Оценить'];
    }
}
