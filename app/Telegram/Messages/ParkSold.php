<?php

namespace App\Telegram\Messages;

use App\Billing\Accrual;
use App\Park\Vehicle;
use App\Support\Surface;

/** Страховая продала ТС: кому выдать и до какого числа хранение за счёт вендора. */
final class ParkSold extends Message
{
    public function __construct(private Vehicle $vehicle) {}

    protected function title(): string
    {
        return 'Продано';
    }

    protected function lines(): array
    {
        $v = $this->vehicle;
        $buyerFrom = Accrual::buyerFrom($v);

        return [
            $v->titleWithYear().($v->ref ? ', '.$v->ref : ''),
            $v->vendor?->name,
            $v->pickup_name || $v->pickup_phone ? 'Заберёт '.trim(($v->pickup_name ?? '').' '.($v->pickup_phone ?? '')) : null,
            $buyerFrom ? 'За счёт вендора до '.$buyerFrom->copy()->subDay()->translatedFormat('j M') : null,
        ];
    }

    protected function decisions(): array
    {
        return [];
    }

    protected function link(): array
    {
        return ['text' => 'ТС на парковке', 'url' => Surface::Park->url('/cars/'.$this->vehicle->id)];
    }
}
