<?php

namespace App\Notifications;

use App\Offers\Offer;
use App\Telegram\Text;

/**
 * ТС передали в гараж человеку или забрали из его гаража (`Garage\Actions\HandOverGarage`, 19.11.2026). Новому держателю —
 * важное, со ссылкой на машину в его гараже; прежнему — строкой ленты.
 */
final class GarageHandedNotice extends Notice
{
    public function __construct(private Offer $offer, private bool $toHim) {}

    public function label(): string
    {
        return $this->toHim ? 'ТС в гараж' : 'ТС из гаража';
    }

    public function title(): string
    {
        return $this->offer->titleWithYear().($this->toHim ? ': в вашем гараже' : ': передана из вашего гаража');
    }

    public function brief(?\App\Users\User $user = null): string
    {
        return $this->toHim ? 'В вашем гараже' : 'Передана из вашего гаража';
    }

    public function href(): string
    {
        return $this->toHim ? '/garage/cars/'.$this->offer->number : '/garage';
    }

    public function category(): string
    {
        return 'deals';
    }

    public function critical(): bool
    {
        return $this->toHim;
    }

    public function quiet(): bool
    {
        return ! $this->toHim;
    }

    public function icon(): string
    {
        return 'cat-car';
    }

    public function toTelegram(): ?array
    {
        return $this->toHim ? ['title' => $this->title(), 'lines' => array_values(array_filter([Text::vin($this->offer)])), 'button' => 'Открыть'] : null;
    }
}
