<?php

namespace App\Notifications;

use App\Offers\Deal;
use App\Park\Vehicle;
use App\Telegram\Text;

/**
 * Менеджеру сделки (18.10.2026): машину можно забирать с нашей парковки — пропуск в сделке, кто заберёт, можно сменить.
 * Вместо «Можно забирать» с контактом страхователя: на парковке он ни при чём.
 */
final class ParkPassNotice extends Notice
{
    public function __construct(private Deal $deal, private Vehicle $vehicle) {}

    public function label(): string
    {
        return 'Пропуск на парковку';
    }

    public function title(): string
    {
        return 'Можно забирать с парковки: '.$this->deal->offer->titleWithYear();
    }

    public function text(): ?string
    {
        $yard = $this->vehicle->yard;

        return implode("\n", array_filter([
            $yard ? 'Парковка «'.$yard->name.'», '.$yard->fullAddress() : null,
            'Пропуск в сделке, заберёт '.($this->vehicle->pickup_name ?? 'вы'),
        ]));
    }

    public function href(): string
    {
        return $this->deal->href();
    }

    public function offerNumber(): ?int
    {
        return $this->deal->offer->number;
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
        return ['title' => $this->title(), 'lines' => Text::lines($this->deal->offer, $this->text()), 'button' => 'Открыть пропуск'];
    }
}
