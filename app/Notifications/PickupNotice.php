<?php

namespace App\Notifications;

use App\Offers\Destination;
use App\Offers\Offer;
use App\Telegram\Text;

/**
 * Вывоз менеджеру (04.10.2026): поручили забрать ТС (к себе или к нам) и «ваш ход» — пора забирать. Ведёт на страницу
 * вывоза в «Гараже»; это не гараж — продавать ТС не обязательно ему.
 */
final class PickupNotice extends Notice
{
    public function __construct(private Offer $offer, private bool $turn = false) {}

    public function title(): string
    {
        return $this->turn ? 'Пора забирать: '.$this->offer->titleWithYear() : 'Вам поручен вывоз: '.$this->offer->titleWithYear();
    }

    public function text(): ?string
    {
        return $this->offer->pickupDestination() === Destination::Keeper ? 'Забрать к себе' : 'Забрать и привезти к нам';
    }

    public function href(): string
    {
        return '/garage/pickups/'.$this->offer->number;
    }

    public function offerNumber(): ?int
    {
        return $this->offer->number;
    }

    public function category(): string
    {
        return 'deals';
    }

    public function critical(): bool
    {
        return true;
    }

    public function toTelegram(): ?array
    {
        return ['title' => $this->title(), 'lines' => Text::lines($this->offer, $this->text()), 'button' => 'Открыть'];
    }
}
