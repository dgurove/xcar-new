<?php

namespace App\Notifications;

use App\Offers\RepriceAsk;
use App\Support\Money;

/** Админам: менеджер ответил про выросшую закупочную (28.10.2026) — согласен или отказался (сделка отменена). */
final class RepriceAnsweredNotice extends Notice
{
    public function __construct(private RepriceAsk $ask) {}

    public function label(): string
    {
        return 'Ответ про закупочную';
    }

    public function title(): string
    {
        $who = $this->ask->user?->shortName() ?? 'Менеджер';

        return ($this->ask->state === RepriceAsk::AGREED ? $who.': согласен на закупочную ' : $who.': отказался от закупочной ')
            .Money::plain($this->ask->to_price).' — № '.$this->ask->offer->number;
    }

    public function text(): ?string
    {
        if ($this->ask->state === RepriceAsk::AGREED) {
            return $this->ask->offer->titleWithYear();
        }

        return $this->ask->offer->titleWithYear().($this->ask->deal_id ? ', сделка отменена, ТС в продаже' : ', подтверждение снято');
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
        return $this->ask->state === RepriceAsk::DECLINED;
    }
}
