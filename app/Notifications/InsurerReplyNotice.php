<?php

namespace App\Notifications;

use App\Offers\Deal;
use App\Telegram\Text;
use App\Workflow\Requirement;

/**
 * Менеджеру сделки: страховая ответила (05.10.2026) — текст её письма до подписи, как есть, без разбора на поля. Сдвинуло
 * письмо шаг — тут же его новый ход («Подтвердите покупку»): отдельного «Ваш ход» по этому шагу нет.
 */
final class InsurerReplyNotice extends Notice
{
    public function __construct(private Deal $deal, private string $reply, private ?Requirement $turn = null) {}

    public function title(): string
    {
        return 'Страховая ответила: '.$this->deal->offer->titleWithYear();
    }

    public function text(): ?string
    {
        return implode("\n", array_filter([$this->reply, $this->turn ? 'Ваш ход: '.$this->turn->title : null])) ?: null;
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
        return ['title' => $this->title(), 'lines' => Text::lines($this->deal->offer, $this->reply, $this->turn ? 'Ваш ход: '.$this->turn->title : null), 'button' => 'Открыть сделку'];
    }
}
