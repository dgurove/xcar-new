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
    /** Ждём «Беру в работу» — текст ответа (контакт владельца) не показываем, только что он пришёл (10.10.2026). */
    private bool $locked;

    public function __construct(private Deal $deal, private string $reply, private ?Requirement $turn = null)
    {
        $this->locked = $deal->awaitsConsent();
    }

    public function title(): string
    {
        return ($this->locked ? 'Пришли контакты владельца: ' : 'Страховая ответила: ').$this->deal->offer->titleWithYear();
    }

    public function text(): ?string
    {
        if ($this->locked) {
            return 'Возьмите сделку в работу, чтобы их увидеть';
        }

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
        $lines = $this->locked ? [$this->text()] : [$this->reply, $this->turn ? 'Ваш ход: '.$this->turn->title : null];

        return ['title' => $this->title(), 'lines' => Text::lines($this->deal->offer, ...$lines), 'button' => 'Открыть сделку'];
    }
}
