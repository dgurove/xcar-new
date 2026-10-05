<?php

namespace App\Billing;

use App\Garage\Car as GarageCar;
use App\Offers\CommissionState;
use App\Offers\Deal;
use App\Offers\DealState;
use App\Support\Money;
use App\Users\User;
use Carbon\CarbonInterface;

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
        public readonly string $tone,      // urgent | accent | profit | plain | muted
        public readonly string $preset,    // pay | payout | closed | open
        public readonly string $caption = '', // подпись над числом в шапке расчёта
    ) {}

    public static function of(Deal $deal): self
    {
        $invoices = $deal->relationLoaded('invoices') ? $deal->invoices : $deal->invoices()->with('claims')->get();
        $issued = $invoices->reject(fn (Invoice $i) => $i->isOwed());
        $unpaid = $issued->first(fn (Invoice $i) => $i->state === InvoiceState::Issued);
        $fee = $invoices->first(fn (Invoice $i) => $i->isAgentFee());
        $state = $deal->commissionState();
        // Гаражная сделка цены подтверждения не знает: её счёт (Каркаде, платит менеджер) — от закупочной.
        $label = $deal->isGarage() ? 'В гараж' : 'Цена подтверждения';

        if ($deal->state === DealState::Cancelled) {
            return new self('Сделка отменена', null, 'muted', 'closed');
        }
        if ($unpaid) {
            $left = $unpaid->remaining();
            if ($unpaid->claimed() > 0) {
                return new self('Оплата ждёт подтверждения', $left, 'muted', 'pay', 'К оплате');
            }
            if ($unpaid->isOverdue()) {
                return new self('Просрочен на '.$unpaid->overdueDays().' дн', $left, 'urgent', 'pay', 'К оплате');
            }
            // По ДКП менеджер платит нам только подбор: так и говорим.
            // По ДКП и страховой менеджер платит нам только подбор; у ПРАЙМ счёт может платить его покупатель.
            $byBuyer = $deal->isPrime() && $deal->buyer && $unpaid->party_id !== $deal->buyer->party_id;
            $paid = $unpaid->paidMoney() > 0 ? 'Оплачено '.Money::rub($unpaid->paidMoney()).', остаток до '
                : ($unpaid->kind === ChargeKind::Selection ? 'Оплатите подбор до ' : ($byBuyer ? 'Покупатель оплачивает до ' : 'Оплатите до '));

            return new self($paid.$unpaid->due_at->translatedFormat('j M'), $left, $unpaid->light() === 'urgent' ? 'urgent' : 'plain', 'pay', 'К оплате');
        }
        if ($issued->isEmpty() && $deal->isActive() && $deal->invoiceGap() === 'buyer') {
            return new self('Укажите покупателя', (float) $deal->base(), 'urgent', 'open', $label);
        }
        if ($issued->isEmpty()) {
            return $deal->state === DealState::Done
                ? new self('Сделка закрыта', (float) $deal->base(), 'muted', 'closed', $label)
                : new self('Счёт ещё не выставлен', (float) $deal->base(), 'muted', 'open', $label);
        }

        // Вознаграждение — его прибыль: оставленное себе (ДКП, страховой, удержал из счёта) — зелёным, а не серым «удержано».
        if (($deal->paysSelection() || $deal->withholds()) && $deal->commission) {
            return new self('Ваше вознаграждение', (float) $deal->commission, 'profit', 'closed', 'Вознаграждение');
        }

        return match ($state) {
            CommissionState::Payable => new self('К выплате до '.$fee->due_at->translatedFormat('j M'), $fee->remaining(), 'accent', 'payout', 'Вам к выплате'),
            CommissionState::Paid => new self('Выплачено '.$fee->paid_at?->translatedFormat('j M'), (float) $deal->commission, 'muted', 'closed', 'Вознаграждение'),
            CommissionState::Withheld => new self('Вознаграждение удержано из счёта', (float) $deal->commission, 'muted', 'closed', 'Вознаграждение'),
            CommissionState::Awaiting => new self('Счёт оплачен', (float) $deal->commission, 'muted', 'closed', 'Вознаграждение'),
            default => new self('Счёт оплачен', (float) $deal->base(), 'muted', 'closed', $label),
        };
    }

    /**
     * Расчёт машины в гараже строкой «Денег» (05.10.2026: пилюля «Оплатить» считала гаражный счёт, а строки у него не
     * было — сумма над списком не сходилась со списком). Счёт «отдать нам» или выплата менеджеру — только его; счёт его
     * покупателю менеджеру не платить. Нечего показать — null.
     */
    public static function garage(GarageCar $car, User $manager): ?self
    {
        $mine = fn (?Invoice $i) => $i && $i->state !== InvoiceState::Void && $manager->party_id && $i->party_id === $manager->party_id;
        $invoice = $car->invoice;
        $payout = $car->payoutInvoice;
        if ($mine($invoice) && $invoice->state === InvoiceState::Issued) {
            $left = $invoice->remaining();

            return match (true) {
                $invoice->claimed() > 0 => new self('Оплата ждёт подтверждения', $left, 'muted', 'pay', 'Отдать нам'),
                $invoice->isOverdue() => new self('Просрочен на '.$invoice->overdueDays().' дн', $left, 'urgent', 'pay', 'Отдать нам'),
                default => new self('Оплатите до '.$invoice->due_at->translatedFormat('j M'), $left, $invoice->light() === 'urgent' ? 'urgent' : 'plain', 'pay', 'Отдать нам'),
            };
        }
        if ($mine($payout)) {
            return $payout->state === InvoiceState::Issued
                ? new self('К выплате до '.$payout->due_at->translatedFormat('j M'), $payout->remaining(), 'accent', 'payout', 'Вам к выплате')
                : new self('Выплачено '.$payout->paid_at?->translatedFormat('j M'), (float) $payout->total, 'muted', 'closed', 'Вам выплачено');
        }

        return $mine($invoice) ? new self('Оплачено', (float) $invoice->total, 'muted', 'closed', 'Отдали нам') : null;
    }

    /**
     * Путь денег сделки точками на линии (`x-money.track`, `.steps`): подтверждение → счёт → оплата → вознаграждение.
     * Пройденное — с датой, текущее — со словом, что сейчас происходит, будущее — серым. Вознаграждения нет
     * или менеджер его удерживает сам — шага выплаты нет.
     *
     * @return list<array{title: string, at: ?CarbonInterface, state: string, hint: ?string, tone: ?string}>
     */
    public static function track(Deal $deal): array
    {
        $step = fn (string $title, string $state, $at = null, ?string $hint = null, ?string $tone = null) => compact('title', 'state', 'at', 'hint', 'tone');
        $invoices = $deal->relationLoaded('invoices') ? $deal->invoices : $deal->invoices()->with(['claims', 'payments'])->get();
        $issued = $invoices->reject(fn (Invoice $i) => $i->isOwed())->sortBy('issued_at')->values();
        $fee = $invoices->first(fn (Invoice $i) => $i->isAgentFee());
        $steps = [$step('Подтверждение принято', 'done', $deal->created_at)];

        if ($deal->state === DealState::Cancelled) {
            $steps[] = $step('Сделка отменена', 'danger', $deal->closed_at ?? $deal->updated_at);

            return $steps;
        }
        if ($issued->isEmpty()) {
            $steps[] = $step('Счёт', 'current', null, 'Готовим счёт');
            $steps[] = $step('Оплата', 'todo');
        } else {
            $first = $issued->first();
            $steps[] = $step('Счёт '.$first->label().' выставлен', 'done', $first->issued_at);
            $unpaid = $issued->first(fn (Invoice $i) => $i->state === InvoiceState::Issued);
            if (! $unpaid) {
                $steps[] = $step('Счёт оплачен', 'done', $issued->max('paid_at'));
            } elseif ($unpaid->claimed() > 0) {
                $steps[] = $step('Оплата', 'current', null, 'Сообщили об оплате, ждём поступления', 'urgent');
            } elseif (($link = $unpaid->openLink()) && $link->attempts()->exists()) {
                // Ссылка есть у каждого счёта (заводится с ним) — в путь идёт, только когда плательщик её открывал.
                [$line, $tone] = $link->stateLine();
                $steps[] = $step('Оплата', 'current', null, 'По ссылке: '.$line, $tone === 'danger' ? 'danger' : 'urgent');
            } elseif ($unpaid->isOverdue()) {
                $steps[] = $step('Оплата', 'current', null, 'Просрочена на '.$unpaid->overdueDays().' дн', 'danger');
            } else {
                $steps[] = $step('Оплата', 'current', null, ($unpaid->paidMoney() > 0 ? 'Оплачено '.Money::rub($unpaid->paidMoney()).', остаток ' : 'Ждём оплату ').'до '.$unpaid->due_at->translatedFormat('j M'));
            }
        }

        $state = $deal->commissionState();
        if ($deal->commission && $state !== CommissionState::Hidden) {
            $steps[] = match ($state) {
                CommissionState::Withheld => $step('Вознаграждение удержано', 'done', $issued->first()?->issued_at),
                CommissionState::Paid => $step('Вознаграждение выплачено', 'done', $fee?->paid_at),
                CommissionState::Payable => $step('Выплата вознаграждения', 'current', null, 'До '.$fee?->due_at->translatedFormat('j M'), 'accent'),
                default => $step('Выплата вознаграждения', 'todo'),
            };
        }

        return $steps;
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
            'profit' => 'font-semibold text-open',
            'muted' => 'text-ink-muted',
            default => 'font-semibold',
        };
    }

    public function phraseClass(): string
    {
        return match ($this->tone) {
            'urgent' => 'text-urgent',
            'accent' => 'text-accent-text',
            'profit' => 'text-open',
            default => 'text-ink-muted',
        };
    }
}
