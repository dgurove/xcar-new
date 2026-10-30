<?php

namespace App\Notifications;

use App\Offers\Offer;

/**
 * Письмо поставщику не дошло (18.10.2026, владелец: «доставка и недоставка в шаге»): не ушло с нашей стороны или почтовый
 * сервер вернул отказ. Тому, кто писал, со звуком до нажатия — сделка стоит, пока письма нет.
 */
final class SupplierLetterNotice extends Notice
{
    /** @param  'failed'|'bounced'  $what */
    public function __construct(private Offer $offer, private string $what) {}

    public function label(): string
    {
        return 'Письмо поставщику не дошло';
    }

    public function title(): string
    {
        return ($this->what === 'bounced' ? 'Письмо поставщику не доставлено: ' : 'Письмо поставщику не ушло: ').$this->offer->titleWithYear();
    }

    public function text(): ?string
    {
        return $this->offer->vendor?->name;
    }

    /** Сделка CRM (`/work/deals/{id}` ведёт в редактор, на шаг): адрес CRM, а не страница витрины (`Notice::leadsToCrm`). */
    public function href(): string
    {
        return $this->offer->deal ? '/work/deals/'.$this->offer->deal->id : '/work/deals';
    }

    public function offerNumber(): ?int
    {
        return $this->offer->number;
    }

    public function category(): string
    {
        return 'deals';
    }

    public function important(): bool
    {
        return true;
    }

    public function subject(): string
    {
        return $this->href();
    }
}
