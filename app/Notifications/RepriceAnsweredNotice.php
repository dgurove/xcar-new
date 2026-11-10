<?php

namespace App\Notifications;

use App\Offers\RepriceAsk;
use App\Support\Money;

/**
 * Админам: менеджер ответил про новую закупочную (28.10.2026). С 10.11.2026 ответ — его цена: по сделке «предлагает N» —
 * решать нам (важное), по подтверждению — его новая цена, отказ — сделка отменена или подтверждение снято.
 */
final class RepriceAnsweredNotice extends Notice
{
    public function __construct(private RepriceAsk $ask) {}

    public function label(): string
    {
        return 'Ответ про закупочную';
    }

    public function title(): string
    {
        $ask = $this->ask;
        $who = $ask->user?->shortName() ?? 'Менеджер';
        $n = ' — № '.$ask->offer->number;

        return match ($ask->state) {
            RepriceAsk::OFFERED => $who.': предлагает '.Money::plain((int) $ask->offered_price).$n,
            RepriceAsk::AGREED => $who.': '.($ask->counter_price ? 'согласен на ' : 'новая цена ').Money::plain((int) ($ask->bid_id ? $ask->bid?->amount : $ask->deal?->amount)).$n,
            default => $who.': отказался от закупочной '.Money::plain($ask->to_price).$n,
        };
    }

    public function text(): ?string
    {
        return match ($this->ask->state) {
            RepriceAsk::OFFERED => $this->ask->offer->titleWithYear().': '.($this->ask->counter_price ? 'от контрпредложения отказался, ' : '').'принять, контрпредложение или отказ',
            RepriceAsk::AGREED => $this->ask->offer->titleWithYear(),
            default => $this->ask->offer->titleWithYear().($this->ask->deal_id ? ', сделка отменена, ТС в продаже' : ', подтверждение снято'),
        };
    }

    public function href(): string
    {
        return \App\Support\Surface::Crm->url('/offers/'.$this->ask->offer->number);
    }

    public function offerNumber(): ?int
    {
        return $this->ask->offer->number;
    }

    public function category(): string
    {
        return $this->ask->deal_id ? 'deals' : 'bids';
    }

    public function important(): bool
    {
        return in_array($this->ask->state, [RepriceAsk::DECLINED, RepriceAsk::OFFERED], true);
    }
}
