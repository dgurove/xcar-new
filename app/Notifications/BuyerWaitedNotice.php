<?php

namespace App\Notifications;

use App\Offers\Offer;
use App\Users\User;

/**
 * Менеджеру: его покупатель в «Выбранных» отмечал машину в галерее, а она вышла в продажу (12.10.2026) — покупателю она
 * не видна, пока менеджер её не откроет. Ведёт на предложение, где «Открыть…».
 */
final class BuyerWaitedNotice extends Notice
{
    public function __construct(private User $buyer, private Offer $offer) {}

    public function title(): string
    {
        return $this->buyer->shortName().' ждал '.$this->offer->titleWithYear();
    }

    public function text(): ?string
    {
        return 'В продаже, откройте покупателю';
    }

    public function href(): string
    {
        return "/offers/{$this->offer->number}";
    }

    public function offerNumber(): ?int
    {
        return $this->offer->number;
    }

    public function actor(): ?User
    {
        return $this->buyer;
    }

    public function category(): string
    {
        return 'interest';
    }
}
