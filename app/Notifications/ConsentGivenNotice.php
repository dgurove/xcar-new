<?php

namespace App\Notifications;

use App\Offers\Deal;

/**
 * Менеджер взял сделку в работу — теперь ответить поставщику «актуально» (27.10.2026, владелец: у Альфы сначала согласие
 * менеджера, потом письмо; после письма отказаться нельзя). Сотрудникам громко и в Telegram, ссылка — на сделку, где шаг
 * с письмом поставщику лаймовой «Написать поставщику».
 */
final class ConsentGivenNotice extends Notice
{
    public function __construct(private Deal $deal) {}

    public function label(): string
    {
        return 'Менеджер берёт сделку';
    }

    public function title(): string
    {
        return ($this->deal->buyer?->name ?? 'Менеджер').' берёт '.$this->deal->offer->titleWithYear().' — ответьте поставщику';
    }

    public function text(): ?string
    {
        return 'Подтвердите покупку письмом';
    }

    public function href(): string
    {
        return '/work/deals/'.$this->deal->id;
    }

    public function offerNumber(): ?int
    {
        return $this->deal->offer->number;
    }

    public function category(): string
    {
        return 'deals';
    }

    public function subject(): string
    {
        return '/work/deals/'.$this->deal->id;
    }

    public function critical(): bool
    {
        return true;
    }

    public function important(): bool
    {
        return true;
    }

    public function toTelegram(): ?array
    {
        return ['title' => $this->title(), 'lines' => [$this->text()], 'button' => 'Открыть сделку'];
    }
}
