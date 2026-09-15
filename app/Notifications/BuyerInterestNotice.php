<?php

namespace App\Notifications;

use App\Offers\Interest;

/** Менеджеру: его покупатель проявил интерес. Ведёт на страницу оффера на сайте. */
final class BuyerInterestNotice extends Notice
{
    public function __construct(private Interest $interest) {}

    public function title(): string
    {
        return $this->interest->user->name.' — интерес к '.$this->interest->offer->titleWithYear();
    }

    public function text(): ?string
    {
        return $this->interest->comment ?: 'Свяжитесь с покупателем.';
    }

    public function href(): string
    {
        return "/offers/{$this->interest->offer->number}";
    }

    public function offerNumber(): ?int
    {
        return $this->interest->offer->number;
    }
}
