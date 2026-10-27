<?php

namespace App\Telegram\Messages;

use App\Offers\BidState;
use App\Offers\Offer;
use App\Support\Money;
use App\Support\Plural;
use App\Support\Surface;
use Illuminate\Support\Collection;

/**
 * Приём закрылся у пачки разом (27.10.2026, владелец: 107 сообщений «Приём закрыт» за час в 17:00 — «ебаный бред»): одно
 * сообщение на момент закрытия — сколько с подтверждениями и без, ниже машины с подтверждениями и лучшей ценой; кнопка —
 * «Есть подтверждения» в CRM. Одно предложение — прежнее `BidsClosed`.
 */
final class BidsClosedPack extends Message
{
    private const MAX = 15;

    /** @param  Collection<int, Offer>  $offers */
    public function __construct(private Collection $offers) {}

    protected function title(): string
    {
        $n = $this->offers->count();

        return 'Приём закрыт: '.$n.' '.Plural::of($n, ['предложение', 'предложения', 'предложений']);
    }

    protected function lines(): array
    {
        $active = fn (Offer $o) => $o->bids->where('state', BidState::Active);
        [$with, $without] = $this->offers->partition(fn (Offer $o) => $active($o)->isNotEmpty());
        $lines = ['С подтверждениями '.$with->count().', без подтверждений '.$without->count()];
        foreach ($with->take(self::MAX) as $offer) {
            $priced = $active($offer)->whereNotNull('amount');
            $lines[] = $offer->titleWithYear().': '.$active($offer)->count().($priced->isNotEmpty() ? ', лучшая '.Money::rub($priced->max('amount')) : '');
        }
        if (($more = $with->count() - self::MAX) > 0) {
            $lines[] = 'и ещё '.$more;
        }

        return $lines;
    }

    protected function decisions(): array
    {
        return [];
    }

    protected function link(): array
    {
        return ['text' => 'Выбрать в CRM', 'url' => Surface::Crm->url('/?preset=bids')];
    }
}
