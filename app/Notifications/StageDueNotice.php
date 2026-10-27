<?php

namespace App\Notifications;

use App\Offers\Deal;
use App\Offers\Offer;
use App\Support\Surface;
use App\Telegram\Text;
use App\Workflow\Position;

/** Срок этапа подходит или вышел. Сотруднику — на оффер, менеджеру — на сделку. */
final class StageDueNotice extends Notice
{
    public function __construct(private Offer $offer, private Position $position, private bool $overdue, private ?int $dealId = null) {}

    public function title(): string
    {
        return ($this->overdue ? 'Срок вышел: ' : 'Срок подходит: ').$this->what().' — № '.$this->offer->number;
    }

    /**
     * Что сделать — просьбой к менеджеру («Свяжитесь с владельцем»), а не именем этапа маршрута («Контакты владельца
     * переданы менеджеру»): этапы — наш язык (10.10.2026). Просьбы нет — этап.
     */
    private function what(): string
    {
        $ask = $this->dealId ? \App\Workflow\Requirement::where('deal_id', $this->dealId)->where('stage_id', $this->position->stage_id)->whereNull('done_at')->latest('id')->value('title') : null;

        return $ask ?? $this->position->stage->name;
    }

    public function text(): ?string
    {
        return $this->offer->titleWithYear().', срок '.$this->position->deadline_at?->translatedFormat('j M, H:i');
    }

    public function href(): string
    {
        // Гаражная сделка ведёт в гараж — её шаги менеджер видит в карточке машины; сотруднику — редактор в CRM.
        return $this->dealId ? (Deal::find($this->dealId)?->href() ?? "/deals/{$this->dealId}") : Surface::Crm->url("/offers/{$this->offer->number}");
    }

    public function offerNumber(): ?int
    {
        return $this->offer->number;
    }

    public function category(): string
    {
        return 'deals';
    }

    /** Сотрудникам — пачкой (`Batch`, 27.10.2026): сроки у сделок выходят разом, по одному уведомлению на каждую — спам. */
    public function batchKey(): ?string
    {
        return $this->dealId === null ? ($this->overdue ? 'stage-overdue' : 'stage-due') : null;
    }

    public function batchLine(): string
    {
        return $this->what().' — '.$this->offer->titleWithYear();
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
            'lines' => Text::lines($this->offer, $this->what().($this->position->deadline_at ? ' до '.$this->position->deadline_at->translatedFormat('j M, H:i') : '')),
            'button' => 'Открыть сделку',
        ];
    }

    public function speaksRoute(): bool
    {
        return true;
    }
}
