<?php

namespace App\Notifications;

use App\Offers\Disclosure;
use App\Telegram\Text;
use App\Workflow\Requirement;

/**
 * Менеджеру сделки — то, что админ решил показать из писем страховой (03.11.2026, владелец: «только тогда менеджеру приходит
 * уведомление и показывается ТОЛЬКО то, что выбрано»; `Offers\Actions\Disclose`). Строки — выбранные пункты как есть,
 * документы — числом; сдвинул админ шаг той же галкой — его новый ход здесь же, отдельного «Ваш ход» нет.
 */
final class DealInfoNotice extends Notice
{
    public function __construct(private Disclosure $disclosure, private ?Requirement $turn = null) {}

    public function label(): string
    {
        return 'Информация по сделке';
    }

    public function title(): string
    {
        return 'Информация по сделке: '.$this->disclosure->deal->offer->titleWithYear();
    }

    public function text(): ?string
    {
        return implode("\n", $this->lines()) ?: null;
    }

    public function href(): string
    {
        return $this->disclosure->deal->href();
    }

    public function offerNumber(): ?int
    {
        return $this->disclosure->deal->offer->number;
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
        return ['title' => $this->title(), 'lines' => Text::lines($this->disclosure->deal->offer, ...$this->lines()), 'button' => 'Открыть сделку'];
    }

    /** @return list<string> */
    private function lines(): array
    {
        $items = collect($this->disclosure->items);
        $files = $items->where('kind', 'file')->count();

        return array_values(array_filter([
            ...$items->where('kind', '!=', 'file')->pluck('value')->all(),
            $files ? 'Документы: '.$files : null,
            $this->turn ? 'Ваш ход: '.$this->turn->title : null,
        ]));
    }
}
