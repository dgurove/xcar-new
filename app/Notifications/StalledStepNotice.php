<?php

namespace App\Notifications;

use App\Offers\Deal;
use App\Telegram\Text;

/** Менеджеру: шаг без срока ждёт его давно — мягкое напоминание (`RemindStalled`), без «просрочено». */
final class StalledStepNotice extends Notice
{
    public function __construct(private Deal $deal, private string $ask) {}

    public function label(): string
    {
        return 'Шаг давно ждёт';
    }

    public function title(): string
    {
        return $this->ask.': '.$this->deal->offer->titleWithYear();
    }

    public function text(): ?string
    {
        return null;
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

    public function toTelegram(): ?array
    {
        return ['title' => $this->title(), 'lines' => Text::lines($this->deal->offer), 'button' => 'Открыть сделку'];
    }

    public function speaksRoute(): bool
    {
        return true;
    }
}
