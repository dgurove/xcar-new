<?php

namespace App\Notifications;

use App\Offers\Deal;
use App\Telegram\Text;
use App\Workflow\Requirement;

/**
 * Менеджеру сделки: в ответе страховой — телефон владельца (05.10.2026). Текста письма нет нигде (03.11.2026, владелец: письма
 * страховой менеджер не видит ни при каких условиях) — на странице сделки только телефоны (`Offers\OwnerPhones`). Ответ без
 * телефона менеджеру не сообщается (`AdvanceOnReply`). Сдвинуло письмо шаг — тут же его новый ход: отдельного «Ваш ход» нет.
 */
final class InsurerReplyNotice extends Notice
{
    public function __construct(private Deal $deal, private ?Requirement $turn = null) {}

    public function label(): string
    {
        return 'Контакты владельца';
    }

    public function title(): string
    {
        return 'Контакты владельца: '.$this->deal->offer->titleWithYear();
    }

    public function text(): ?string
    {
        return $this->turn ? 'Ваш ход: '.$this->turn->title : null;
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
        return ['title' => $this->title(), 'lines' => Text::lines($this->deal->offer, $this->turn ? 'Ваш ход: '.$this->turn->title : null), 'button' => 'Открыть сделку'];
    }
}
