<?php

namespace App\Notifications;

use App\Offers\Offer;
use App\Telegram\Text;
use App\Workflow\Position;

/** Срок этапа подходит или вышел. Сотруднику — на оффер, менеджеру — на сделку. */
final class StageDueNotice extends Notice
{
    public function __construct(private Offer $offer, private Position $position, private bool $overdue, private ?int $dealId = null) {}

    public function title(): string
    {
        return ($this->overdue ? 'Срок вышел: ' : 'Срок подходит: ').$this->position->stage->name.' — № '.$this->offer->number;
    }

    public function text(): ?string
    {
        return $this->offer->titleWithYear().', срок '.$this->position->deadline_at?->translatedFormat('j M, H:i');
    }

    public function href(): string
    {
        return $this->dealId ? "/deals/{$this->dealId}" : "/offers/{$this->offer->number}";
    }

    public function offerNumber(): ?int
    {
        return $this->offer->number;
    }

    public function category(): string
    {
        return 'deals';
    }

    /** Покупателю сделки — всегда; сотруднику сроки этапов можно выключить. */
    public function critical(): bool
    {
        return $this->dealId !== null;
    }

    /** Менеджеру «срок вышел» только обновляет строку сделки: напоминание до срока он уже получил. */
    public function quiet(): bool
    {
        return $this->dealId !== null && $this->overdue;
    }

    /** В Telegram — только менеджеру по его сделке. */
    public function toTelegram(): ?array
    {
        return $this->dealId === null ? null : [
            'title' => ($this->overdue ? 'Срок вышел по ' : 'Срок подходит по ').$this->offer->titleWithYear(),
            'lines' => Text::lines($this->offer, $this->position->stage->name.($this->position->deadline_at ? ' до '.$this->position->deadline_at->translatedFormat('j M, H:i') : '')),
            'button' => 'Открыть сделку',
        ];
    }
}
