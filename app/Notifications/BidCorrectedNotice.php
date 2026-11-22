<?php

namespace App\Notifications;

use App\Offers\Actions\ReviseBid;
use App\Offers\Bid;
use App\Offers\BidKind;
use App\Support\Money;
use App\Users\User;

/**
 * Менеджеру: админ изменил его подтверждение (`ReviseBid`) — поправил опечатку в цене (02.11.2026) или перевёл «Для
 * покупателя» ↔ «В гараж» (22.11.2026): «Подтверждение: 940 000 вместо 9 400 000», «Подтверждение теперь в гараж»,
 * «Подтверждение теперь 1 150 000 для покупателя».
 */
final class BidCorrectedNotice extends Notice
{
    public function __construct(private Bid $bid, private ?int $was, private ?BidKind $wasKind, private User $by) {}

    public function label(): string
    {
        return $this->kindChanged() ? 'Подтверждение изменено' : 'Цена подтверждения поправлена';
    }

    public function title(): string
    {
        return match (true) {
            ! $this->kindChanged() => 'Подтверждение: '.Money::plain((int) $this->bid->amount).' вместо '.Money::plain((int) $this->was),
            $this->bid->kind === BidKind::Garage => 'Подтверждение теперь в гараж',
            default => 'Подтверждение теперь '.Money::plain((int) $this->bid->amount).' для покупателя',
        };
    }

    public function text(): ?string
    {
        return $this->bid->offer->titleWithYear().', № '.$this->bid->offer->number.', '.($this->kindChanged() ? 'было '.ReviseBid::word($this->wasKind, $this->was).', изменил XCAR' : 'поправил XCAR');
    }

    public function href(): string
    {
        return '/offers/'.$this->bid->offer->number;
    }

    public function offerNumber(): ?int
    {
        return $this->bid->offer->number;
    }

    public function category(): string
    {
        return 'bids';
    }

    public function important(): bool
    {
        return true;
    }

    private function kindChanged(): bool
    {
        return $this->wasKind !== null && $this->wasKind !== $this->bid->kind;
    }
}
