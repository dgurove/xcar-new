<?php

namespace App\Notifications;

use App\Offers\Deal;

/**
 * Покупателю (19.10.2026): менеджер выбрал его покупателем по договору, а паспорта со сканом нет — внести их самому в
 * кабинете («Данные для договора»), а не пересылать фото менеджеру. Без цены и вендора: только какая машина.
 */
final class BuyerContractDataNotice extends Notice
{
    public function __construct(private Deal $deal) {}

    public function label(): string
    {
        return 'Данные для договора';
    }

    public function title(): string
    {
        return 'Нужны данные для договора';
    }

    public function text(): ?string
    {
        return $this->deal->offer->titleWithYear().': паспорт и скан двух страниц';
    }

    public function href(): string
    {
        return '/account/contract';
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
        return ['title' => $this->title(), 'lines' => [$this->text()], 'button' => 'Внести данные'];
    }
}
