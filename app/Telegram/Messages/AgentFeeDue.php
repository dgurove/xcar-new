<?php

namespace App\Telegram\Messages;

use App\Billing\Invoice;
use App\Support\Money;
use App\Support\Surface;
use App\Telegram\Text;

/** Счета по сделке оплачены — менеджеру к выплате; «Выплачено» закрывает обязательство целиком. */
final class AgentFeeDue extends Message
{
    public function __construct(private Invoice $fee) {}

    protected function title(): string
    {
        $offer = $this->fee->deal?->offer ?? $this->fee->offer;

        return 'К выплате '.$this->fee->party->name.($offer ? ': '.$offer->titleWithYear() : '');
    }

    protected function lines(): array
    {
        $f = $this->fee;

        return Text::lines($f->deal?->offer ?? $f->offer,
            'Агентское вознаграждение '.Money::rub($f->remaining()).' до '.$f->due_at->translatedFormat('j M'),
            $f->party->payoutReady() ? $f->party->bankDetails() : 'Реквизитов для выплаты нет');
    }

    protected function decisions(): array
    {
        return [['text' => 'Выплачено', 'callback_data' => 'fee:'.$this->fee->id.':paid']];
    }

    protected function link(): array
    {
        return ['text' => 'В CRM', 'url' => Surface::Crm->url('/work/money?preset=payouts')];
    }
}
