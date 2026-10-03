<?php

namespace App\Telegram\Messages;

use App\Offers\BidKind;
use App\Offers\BidState;
use App\Offers\Offer;
use App\Support\Money;
use App\Support\Plural;
use App\Support\Surface;
use Illuminate\Support\Collection;

/**
 * Слот закрывается через час (владелец 03.10.2026: одним сообщением на слот и за час до конца, а не «Приём закрыт» по
 * каждому в 21:00): по машине — сколько подтверждений и лучшая цена, кнопка — список в CRM, где выбирать.
 */
final class SlotClosing extends Message
{
    private const MAX = 40;

    /** @param  Collection<int, Offer>  $offers */
    public function __construct(private Collection $offers) {}

    protected function title(): string
    {
        $n = $this->offers->count();

        return 'Через час закрывается приём: '.$n.' '.Plural::of($n, ['предложение', 'предложения', 'предложений']);
    }

    protected function lines(): array
    {
        $lines = $this->offers->take(self::MAX)->map(function (Offer $offer) {
            $bids = $offer->bids->where('state', BidState::Active);
            [$garage, $priced] = $bids->partition(fn ($b) => $b->kind === BidKind::Garage);
            $summary = match (true) {
                $bids->isEmpty() => 'подтверждений нет',
                $priced->isEmpty() => 'в гараж '.$garage->count(),
                default => 'подтверждений '.$priced->count().', лучшая '.Money::rub($priced->max('amount')).($garage->isNotEmpty() ? ', в гараж '.$garage->count() : ''),
            };

            return $offer->titleWithYear().': '.$summary;
        })->all();
        if (($more = $this->offers->count() - self::MAX) > 0) {
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
        return ['text' => 'Выбрать в CRM', 'url' => Surface::Crm->url('/?preset=open&sort=closing')];
    }
}
