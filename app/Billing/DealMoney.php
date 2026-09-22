<?php

namespace App\Billing;

use App\Offers\CommissionState;
use App\Offers\Deal;
use App\Offers\DealState;
use App\Support\Money;

/**
 * Сделка глазами денег одной строкой: что сейчас важно (фраза), какое число
 * показать справа и каким тоном, в какой пресет она попадает. Одна логика на
 * кабинет менеджера и CRM; закупочной и «нам» тут нет.
 */
final class DealMoney
{
    public const PRESETS = ['all' => 'Все', 'pay' => 'Оплатить', 'payout' => 'Ждут выплаты', 'closed' => 'Закрытые'];

    private function __construct(
        public readonly string $phrase,
        public readonly ?float $amount,
        public readonly string $tone,      // urgent | accent | plain | muted
        public readonly string $preset,    // pay | payout | closed | open
    ) {}

    public static function of(Deal $deal): self
    {
        $invoices = $deal->relationLoaded('invoices') ? $deal->invoices : $deal->invoices()->with('claims')->get();
        $issued = $invoices->reject(fn (Invoice $i) => $i->isOwed());
        $unpaid = $issued->first(fn (Invoice $i) => $i->state === InvoiceState::Issued);
        $fee = $invoices->first(fn (Invoice $i) => $i->isAgentFee());
        $state = $deal->commissionState();

        if ($deal->state === DealState::Cancelled) {
            return new self('Сделка отменена', null, 'muted', 'closed');
        }
        if ($unpaid) {
            $left = $unpaid->remaining();
            if ($unpaid->claimed() > 0) {
                return new self('Оплата ждёт подтверждения', $left, 'muted', 'pay');
            }
            if ($unpaid->isOverdue()) {
                return new self('Просрочен на '.$unpaid->overdueDays().' дн', $left, 'urgent', 'pay');
            }
            $paid = $unpaid->paid > 0 ? 'Оплачено '.Money::rub($unpaid->paid).', остаток до ' : 'Оплатите до ';

            return new self($paid.$unpaid->due_at->translatedFormat('j M'), $left, $unpaid->light() === 'urgent' ? 'urgent' : 'plain', 'pay');
        }
        if ($issued->isEmpty()) {
            return $deal->state === DealState::Done
                ? new self('Сделка закрыта', (float) $deal->amount, 'muted', 'closed')
                : new self('Счёт ещё не выставлен', (float) $deal->amount, 'muted', 'open');
        }

        return match ($state) {
            CommissionState::Payable => new self('К выплате до '.$fee->due_at->translatedFormat('j M'), $fee->remaining(), 'accent', 'payout'),
            CommissionState::Paid => new self('Выплачено '.$fee->paid_at?->translatedFormat('j M'), (float) $deal->commission, 'muted', 'closed'),
            CommissionState::Withheld => new self('Вознаграждение удержано из счёта', (float) $deal->commission, 'muted', 'closed'),
            CommissionState::Awaiting => new self('Счёт оплачен', (float) $deal->commission, 'muted', 'closed'),
            default => new self('Счёт оплачен', (float) $deal->amount, 'muted', 'closed'),
        };
    }

    public function needsAction(): bool
    {
        return $this->preset === 'pay' && $this->tone !== 'muted';
    }

    /** Классы числа справа по тону. */
    public function amountClass(): string
    {
        return match ($this->tone) {
            'urgent' => 'font-semibold text-urgent',
            'accent' => 'font-semibold text-accent-text',
            'muted' => 'text-ink-muted',
            default => 'font-semibold',
        };
    }

    public function phraseClass(): string
    {
        return match ($this->tone) {
            'urgent' => 'text-urgent',
            'accent' => 'text-accent-text',
            default => 'text-ink-muted',
        };
    }
}
