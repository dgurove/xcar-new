<?php

namespace App\Notifications;

use App\Offers\Offer;

/**
 * Менеджеру, который называл цену за ТС в закупке: она перешла в предложения и вышла в продажу. Идёт вместо
 * «Нового предложения», не вдобавок. Цен из закупки в тексте нет: для предложения они ничего не значат.
 */
final class PurchaseCarOnSaleNotice extends Notice
{
    public function __construct(private Offer $offer) {}

    public function title(): string
    {
        return 'ТС из закупки в продаже: '.$this->offer->titleWithYear();
    }

    public function text(): ?string
    {
        return $this->offer->asking_price ? number_format($this->offer->asking_price, 0, '', ' ').' ₽, приём подтверждений до '.$this->offer->bids_close_at?->translatedFormat('j M, H:i') : null;
    }

    public function href(): string
    {
        return "/offers/{$this->offer->number}";
    }

    public function offerNumber(): ?int
    {
        return $this->offer->number;
    }

    public function category(): string
    {
        return 'offers';
    }
}
