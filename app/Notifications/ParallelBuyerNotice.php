<?php

namespace App\Notifications;

use App\Offers\Deal;

/**
 * Менеджер параллельной сделки прислал реквизиты покупателя (07.11.2026, владелец: «когда он их предоставит, мы должны их
 * видеть — и, может, переключимся на него»): сотрудникам громко и в Telegram, ссылка — на блок «Параллельно» сделки в CRM.
 * Страховой ничего не уходит: решает админ (`TakeParallel`).
 */
final class ParallelBuyerNotice extends Notice
{
    public function __construct(private Deal $deal) {}

    public function label(): string
    {
        return 'Реквизиты параллельно';
    }

    public function title(): string
    {
        return ($this->deal->buyer?->name ?? 'Менеджер').' прислал реквизиты (параллельно) — '.$this->deal->offer->titleWithYear();
    }

    public function text(): ?string
    {
        $who = $this->deal->contract?->buyer;

        return 'Покупатель: '.($who?->party?->name ?: $who?->name ?? '—').'. Взять в работу — в сделке, блок «Параллельно»';
    }

    public function href(): string
    {
        return '/offers/'.$this->deal->offer->number.'#parallel';
    }

    public function offerNumber(): ?int
    {
        return $this->deal->offer->number;
    }

    public function category(): string
    {
        return 'deals';
    }

    public function subject(): string
    {
        return '/offers/'.$this->deal->offer->number.'#parallel-'.$this->deal->id;
    }

    public function important(): bool
    {
        return true;
    }

    public function toTelegram(): ?array
    {
        return ['title' => $this->title(), 'lines' => [$this->text()], 'button' => 'Открыть сделку'];
    }
}
