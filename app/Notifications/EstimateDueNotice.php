<?php

namespace App\Notifications;

/** До срока оценки шесть часов, а оценки нет — одно напоминание. */
final class EstimateDueNotice extends EstimateNotice
{
    public function title(): string
    {
        return 'Срок оценки подходит: '.$this->estimate->offer->titleWithYear();
    }

    public function text(): ?string
    {
        return 'Оцените '.$this->due();
    }

    public function toTelegram(): ?array
    {
        return ['title' => 'Оцените '.$this->estimate->offer->titleWithYear().' '.$this->due(), 'lines' => $this->lines(), 'button' => 'Оценить'];
    }
}
