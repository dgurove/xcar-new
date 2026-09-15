<?php

namespace App\Notifications;

use App\Offers\Offer;
use App\Support\Plural;
use App\Users\User;

/** Покупателю: менеджер открыл ему предложения — одно сообщение на пачку. */
final class OffersShownNotice extends Notice
{
    /** @param list<int> $offerIds */
    public function __construct(private User $manager, private array $offerIds) {}

    public function title(): string
    {
        $n = count($this->offerIds);
        if ($n === 1) {
            $offer = Offer::find($this->offerIds[0]);

            return $this->manager->shortName().' открыл вам '.($offer?->titleWithYear() ?? 'автомобиль');
        }

        return $this->manager->shortName().' открыл вам '.$n.' '.Plural::of($n, ['автомобиль', 'автомобиля', 'автомобилей']);
    }

    public function text(): ?string
    {
        return count($this->offerIds) === 1 ? 'Посмотрите и отметьте интерес, если подходит.' : 'Всё — в вашей ленте.';
    }

    public function href(): string
    {
        return count($this->offerIds) === 1 ? '/offers/'.(Offer::find($this->offerIds[0])?->number ?? '') : '/';
    }

    public function offerNumber(): ?int
    {
        return count($this->offerIds) === 1 ? Offer::find($this->offerIds[0])?->number : null;
    }
}
