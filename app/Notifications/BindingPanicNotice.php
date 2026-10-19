<?php

namespace App\Notifications;

use App\Logistics\Transport;
use App\Offers\Binding;
use App\Offers\Offer;
use App\Support\Surface;

/**
 * Срок обязывающего предложения горит, покупателя нет (19.10.2026, владелец: «прям ПАНИКА!!!»): ведущим логистику (админы и
 * модераторы) — в CRM на предложение, логисту вывоза — в логистике на задачу. Со звуком и до нажатия, отключить нельзя.
 * Один раз на срок (`BindingAlarm`).
 */
final class BindingPanicNotice extends Notice
{
    public function __construct(private Offer $offer, private ?Transport $transport = null) {}

    public function title(): string
    {
        return 'Нет покупателя: '.$this->offer->titleWithYear();
    }

    public function text(): ?string
    {
        $until = $this->offer->binding_until;

        return $until->isPast() ? 'Срок обязывающего предложения вышел '.Binding::date($until) : 'Обязывающее до '.Binding::date($until).', '.Binding::word($this->offer);
    }

    public function href(): string
    {
        return $this->transport ? '/transports/'.$this->transport->id : Surface::Crm->url('/offers/'.$this->offer->number);
    }

    public function offerNumber(): ?int
    {
        return $this->offer->number;
    }

    public function category(): string
    {
        return $this->transport ? 'logistics' : 'offers';
    }

    public function critical(): bool
    {
        return true;
    }

    public function important(): bool
    {
        return true;
    }

    public function alarm(): bool
    {
        return true;
    }

    public function icon(): string
    {
        return 'siren';
    }

    public function subject(): string
    {
        return $this->transport ? '/transports/'.$this->transport->id : '/offers/'.$this->offer->number;
    }
}
