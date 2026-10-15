<?php

namespace App\Notifications;

use App\Offers\Deal;
use App\Telegram\Text;
use App\Workflow\Requirement;

/**
 * Менеджеру сделки: страховая ответила (05.10.2026). Текста письма нет (15.10.2026, владелец: в нём закупочная, вендор и
 * подпись сотрудника страховой) — урезанный ответ на странице сделки. Сдвинуло письмо шаг — тут же его новый ход
 * («Подтвердите, что берёте в работу»): отдельного «Ваш ход» по этому шагу нет.
 */
final class InsurerReplyNotice extends Notice
{
    public function __construct(private Deal $deal, private bool $contact = false, private ?Requirement $turn = null) {}

    public function title(): string
    {
        // Прислали телефон — это контакты владельца, а не просто «ответила» (10.10.2026).
        return ($this->contact ? 'Контакты владельца: ' : 'Страховая ответила: ').$this->deal->offer->titleWithYear();
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
