<?php

namespace App\Notifications;

use App\Billing\Invoice;
use App\Billing\Payment;
use App\Support\Money;
use App\Support\Surface;

/**
 * Деньги по сделке: менеджеру — счёт выставлен, оплата принята или не поступила, выплачено;
 * сотрудникам — менеджер сообщил об оплате, вознаграждение к выплате. Менеджеру всегда, сотруднику — категория «Деньги».
 */
final class MoneyNotice extends Notice
{
    public function __construct(private string $title, private ?string $text, private string $path, private ?int $offerNumber = null, private bool $toStaff = false) {}

    public static function invoiceIssued(Invoice $i): self
    {
        return new self('Счёт '.$i->label().' на '.Money::rub($i->remaining()).', оплатить до '.$i->due_at->translatedFormat('j M'), $i->deal?->offer?->titleWithYear(), '/account/money/invoices/'.$i->id, $i->deal?->offer?->number);
    }

    public static function paymentConfirmed(Payment $p): self
    {
        $i = $p->invoice;

        return new self('Оплата '.Money::rub($p->amount).' по счёту '.$i->label().' принята', $i->remaining() > 0 ? 'Остаток '.Money::rub($i->remaining()) : 'Счёт оплачен', '/account/money/invoices/'.$i->id, $i->deal?->offer?->number);
    }

    public static function paymentRejected(Payment $p): self
    {
        $i = $p->invoice;

        return new self('Оплата '.Money::rub($p->amount).' по счёту '.$i->label().' не поступила', $p->reject_reason ?: 'Проверьте платёж и сообщите снова', '/account/money/invoices/'.$i->id, $i->deal?->offer?->number);
    }

    public static function payout(Payment $p): self
    {
        $i = $p->invoice;

        return new self('Выплачено '.Money::rub($p->amount).($i->remaining() > 0 ? ', осталось '.Money::rub($i->remaining()) : ''), $i->deal?->offer?->titleWithYear(), '/account/money/deals/'.$i->deal_id, $i->deal?->offer?->number);
    }

    public static function claimed(Payment $p): self
    {
        $i = $p->invoice;

        return new self(($i->deal?->buyer?->shortName() ?? $i->party->name).' сообщил об оплате '.Money::rub($p->amount).' по счёту '.$i->label(), $i->deal?->offer?->titleWithYear(), '/work/money', $i->deal?->offer?->number, true);
    }

    public static function feeDue(Invoice $fee): self
    {
        return new self('К выплате '.Money::rub($fee->total).' — '.$fee->party->name, $fee->deal?->offer?->titleWithYear().', до '.$fee->due_at->translatedFormat('j M'), '/work/money?preset=payouts', $fee->deal?->offer?->number, true);
    }

    public function title(): string
    {
        return $this->title;
    }

    public function text(): ?string
    {
        return $this->text ?: null;
    }

    public function href(): string
    {
        return $this->toStaff ? Surface::Crm->url($this->path) : $this->path;
    }

    public function offerNumber(): ?int
    {
        return $this->offerNumber;
    }

    public function category(): string
    {
        return $this->toStaff ? 'money' : 'deals';
    }

    public function critical(): bool
    {
        return ! $this->toStaff;
    }
}
