<?php

namespace App\Notifications;

use App\Offers\Deal;
use App\Offers\Handover;
use App\Telegram\Text;

/**
 * Менеджеру сделки, который сам забирает ТС у владельца (04.10.2026): поставщик согласовал — вот с кем говорить и куда
 * ехать. Одно сообщение вместо «Вам поручен вывоз» при принятии: до согласия контакт владельца ему не нужен.
 */
final class HandoverNotice extends Notice
{
    public function __construct(private Deal $deal, private Handover $handover) {}

    public function title(): string
    {
        return 'Можно забирать: '.$this->deal->offer->titleWithYear();
    }

    public function text(): ?string
    {
        $h = $this->handover;
        $who = trim(implode(', ', array_filter([$h->name, $h->phoneFormatted()])));

        return implode("\n", array_filter([$who ?: null, $h->address, $h->dateLabel()])) ?: 'Поставщик согласовал продажу';
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
        return ['title' => $this->title(), 'lines' => Text::lines($this->deal->offer, $this->text()), 'button' => 'Открыть'];
    }
}
