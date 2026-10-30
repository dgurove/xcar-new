<?php

namespace App\Notifications;

use App\Offers\Deal;
use App\Telegram\Text;

/** Менеджеру: продавец, ТС и покупатель внесены — договор (ДКП собственника или ПРАЙМ) можно скачать и распечатать. */
final class ContractReadyNotice extends Notice
{
    public function __construct(private Deal $deal) {}

    public function label(): string
    {
        return 'ДКП готов';
    }

    public function title(): string
    {
        return 'ДКП готов: '.$this->deal->offer->titleWithYear();
    }

    public function text(): ?string
    {
        return $this->deal->isDkp() ? 'Скачайте, распечатайте в трёх экземплярах и подпишите с собственником' : 'Скачайте и распечатайте в трёх экземплярах';
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
