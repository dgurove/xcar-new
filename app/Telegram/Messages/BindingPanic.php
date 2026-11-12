<?php

namespace App\Telegram\Messages;

use App\Offers\Binding;
use App\Offers\Offer;
use App\Support\Surface;
use App\Telegram\Text;

/** Срок обязывающего предложения горит или вышел, покупателя нет (19.10.2026, владелец: «прям ПАНИКА!!!»). */
final class BindingPanic extends Message
{
    public function __construct(private Offer $offer) {}

    protected function title(): string
    {
        return (Binding::of($this->offer) === 'deal' ? '🚨 Поставщику не заплачено: ' : '🚨 Нет покупателя: ').$this->offer->titleWithYear();
    }

    protected function lines(): array
    {
        $until = $this->offer->binding_until;

        return Text::lines($this->offer, match (true) {
            $until->isPast() => 'Срок обязывающего сгорел '.Binding::date($until),
            $until->lte(Binding::burnt()) => 'Срок обязывающего сгорел: до '.Binding::date($until).', '.Binding::word($this->offer),
            default => 'Горит срок обязывающего: до '.Binding::date($until).', '.Binding::word($this->offer),
        });
    }

    protected function decisions(): array
    {
        return [];
    }

    protected function link(): array
    {
        return ['text' => 'Предложение в CRM', 'url' => Surface::Crm->url('/offers/'.$this->offer->number)];
    }
}
