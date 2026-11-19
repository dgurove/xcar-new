<?php

namespace App\Notifications;

use App\Offers\Deal;
use App\Offers\Offer;
use App\Support\Ago;
use App\Support\Surface;
use App\Telegram\Text;
use App\Workflow\Position;
use App\Workflow\Requirement;

/** Срок этапа подходит или вышел. Сотруднику — на оффер, менеджеру — на сделку. */
final class StageDueNotice extends Notice
{
    public function __construct(private Offer $offer, private Position $position, private bool $overdue, private ?int $dealId = null) {}

    public function label(): string
    {
        return $this->overdue ? 'Срок вышел' : 'Срок подходит';
    }

    public function kind(): string
    {
        return parent::kind().($this->overdue ? ':overdue' : ':due');
    }

    /**
     * Менеджеру — само дело, без «Срок подходит» и даты (18.11.2026, владелец: «убери сроки из уведомлений и сообщений, меня
     * всегда с них кринжевало; просто „Оплатить счёт“, всё»). Сотруднику — как было.
     */
    public function title(): string
    {
        return $this->dealId ? $this->what().', № '.$this->offer->number
            : ($this->overdue ? 'Срок вышел: ' : 'Срок подходит: ').$this->what().', № '.$this->offer->number;
    }

    /**
     * Что сделать — просьбой к менеджеру («Свяжитесь с владельцем»), а не именем этапа маршрута («Контакты владельца
     * переданы менеджеру»): этапы — наш язык (10.10.2026). Просьбы нет — этап.
     */
    private function what(): string
    {
        $ask = $this->dealId ? Requirement::where('deal_id', $this->dealId)->where('stage_id', $this->position->stage_id)->whereNull('done_at')->latest('id')->value('title') : null;

        return $ask ?? ($this->dealId ? $this->position->stage->managerTitle() : $this->position->stage->name);
    }

    public function text(): ?string
    {
        return $this->dealId ? $this->offer->titleWithYear() : $this->offer->titleWithYear().', срок '.Ago::day($this->position->deadline_at, true);
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
        return $this->what().', '.$this->offer->titleWithYear();
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
            'title' => $this->what().': '.$this->offer->titleWithYear(),
            'lines' => Text::lines($this->offer),
            'button' => 'Открыть сделку',
        ];
    }

    public function speaksRoute(): bool
    {
        return true;
    }
}
