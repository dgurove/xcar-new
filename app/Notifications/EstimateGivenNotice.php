<?php

namespace App\Notifications;

use App\Offers\Estimate;
use App\Support\Ago;
use App\Support\Money;
use App\Support\Surface;
use App\Users\User;

/** Менеджер оценил — админам карточка со звуком и аватаром; ведёт в окошко «Оценить» этой строки. */
final class EstimateGivenNotice extends Notice
{
    public function __construct(private Estimate $estimate) {}

    public function label(): string
    {
        return 'Менеджер оценил';
    }

    public function title(): string
    {
        $e = $this->estimate;

        return $e->user->shortName().': '.match (true) {
            $e->agreed() => 'согласен с ценой '.Money::rub($e->amount),
            $e->isCorrection() => Money::rub($e->amount).' вместо '.Money::rub($e->base_price),
            default => 'оценка '.Money::rub($e->amount),
        };
    }

    public function text(): ?string
    {
        $offer = $this->estimate->offer;

        // Эксклюзив (`FirstLook`): оценка уже цена, и машина в продаже только у него.
        return $offer->titleWithYear().($offer->first_look_until ? ' — опубликована только ему до '.Ago::at($offer->first_look_until, true, words: false) : '');
    }

    public function href(): string
    {
        $offer = $this->estimate->offer;
        $preset = match (true) {
            $offer->first_look_until !== null => 'published',
            $this->estimate->isCorrection() => 'priced',
            default => 'estimating',
        };

        return Surface::Crm->url('/offers?'.http_build_query(['vid' => 'table', 'preset' => $preset, 'peek' => $offer->number]));
    }

    public function category(): string
    {
        return 'bids';
    }

    /** Своя цена или оценка — решать админу, со звуком; «Согласен» с его ценой — тихо, строкой в ленте. */
    public function important(): bool
    {
        return ! $this->estimate->agreed();
    }

    public function quiet(): bool
    {
        return $this->estimate->agreed();
    }

    public function actor(): ?User
    {
        return $this->estimate->user;
    }
}
