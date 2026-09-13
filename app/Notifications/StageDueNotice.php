<?php

namespace App\Notifications;

use App\Offers\Offer;
use App\Support\Surface;
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
        return $this->dealId ? "/lk/sdelki/{$this->dealId}" : Surface::Crm->url("/predlozheniya/{$this->offer->number}");
    }

    public function offerNumber(): ?int
    {
        return $this->offer->number;
    }
}
