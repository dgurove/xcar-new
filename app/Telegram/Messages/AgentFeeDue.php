<?php

namespace App\Telegram\Messages;

use App\Billing\Invoice;
use App\Support\Money;
use App\Support\Surface;

/** Счета по сделке оплачены — менеджеру к выплате; «Выплачено» закрывает обязательство целиком. */
final class AgentFeeDue extends Message
{
    public function __construct(private Invoice $fee) {}

    protected function title(): string
    {
        return 'К выплате: '.$this->fee->party->name;
    }

    protected function lines(): array
    {
        $f = $this->fee;

        return [
            'Агентское вознаграждение '.Money::rub($f->remaining()).', до '.$f->due_at->translatedFormat('j M'),
            $f->deal?->offer ? $f->deal->offer->titleWithYear().', № '.$f->deal->offer->number : null,
            $f->party->payoutReady() ? $f->party->bankDetails() : 'Реквизитов для выплаты нет',
        ];
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
