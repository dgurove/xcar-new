<?php

namespace App\Notifications;

use App\Offers\Deal;
use App\Telegram\Text;

/**
 * Менеджеру сделки (18.10.2026): парковка погасила пропуск сделки («Покупатель отказался», «Выдача снята») — новый
 * пропуск будет, когда он выберет, кто заберёт (`SetReceiver`).
 */
final class ParkPassRevokedNotice extends Notice
{
    public function __construct(private Deal $deal, private string $reason) {}

    public function title(): string
    {
        return 'Пропуск на парковку погашен: '.$this->deal->offer->titleWithYear();
    }

    public function text(): ?string
    {
        return \Illuminate\Support\Str::ucfirst($this->reason)."\nВыберите в сделке, кто заберёт, выдадим новый";
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
        return ['title' => $this->title(), 'lines' => Text::lines($this->deal->offer, $this->text()), 'button' => 'Открыть сделку'];
    }
}
