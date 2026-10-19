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
        return '🚨 Нет покупателя: '.$this->offer->titleWithYear();
    }

    protected function lines(): array
    {
        $until = $this->offer->binding_until;

        return Text::lines($this->offer, $until->isPast()
            ? 'Срок обязывающего предложения вышел '.Binding::date($until)
            : 'Обязывающее до '.Binding::date($until).', '.Binding::word($this->offer));
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
