<?php

namespace App\Notifications;

use App\Offers\Deal;
use App\Telegram\Text;

/**
 * Менеджеру сделки ПРАЙМ на этапе оплаты (05.10.2026): укажите покупателя — счёт ПРАЙМ и ДКП соберутся сами. Раньше в
 * этом месте сотрудникам приходило «Выставите счёт», а менеджер ждал счёт.
 */
final class BuyerNeededNotice extends Notice
{
    /** $passport — покупатель указан, но у физлица нет паспорта со сканом (11.10.2026). */
    public function __construct(private Deal $deal, private bool $passport = false) {}

    public function title(): string
    {
        return ($this->passport ? 'Приложите паспорт покупателя: ' : 'Укажите покупателя: ').$this->deal->offer->titleWithYear();
    }

    public function text(): ?string
    {
        return $this->passport ? 'Счёт и ДКП соберутся по скану паспорта' : 'Счёт и ДКП соберутся по его данным';
    }

    public function href(): string
    {
        return $this->deal->href().'#dkp-buyer';
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

    public function subject(): string
    {
        return $this->deal->href();
    }

    public function toTelegram(): ?array
    {
        return ['title' => $this->title(), 'lines' => Text::lines($this->deal->offer, $this->text()), 'button' => 'Открыть сделку'];
    }
}
