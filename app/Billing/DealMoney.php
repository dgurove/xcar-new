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

    /**
     * Направление сказано словом (06.10.2026, владелец: «почему мы должны Бородину 1 690 000» — он был должен нам, а
     * число стояло без слов). Менеджеру — «К оплате» / «Вам к выплате», сотруднику (`staff`) — третьим лицом:
     * «должен нам» / «должны ему». Деньги строки — `toUs` (его счета, что ещё не оплачены), `toHim` (наша выплата к
     * перечислению), `overdue`, `claimed`: итоги «Денег» и «Расчётов с менеджерами» — сумма строк, а не свой запрос.
     */
    private function __construct(
        public readonly string $phrase,
        public readonly ?float $amount,
        public readonly string $tone,      // urgent | accent | profit | plain | muted
        public readonly string $preset,    // pay | payout | closed | open
        public readonly string $caption = '', // подпись под числом
        public readonly float $toUs = 0,
        public readonly float $toHim = 0,
        public readonly float $overdue = 0,
        public readonly float $claimed = 0,
        public readonly ?CarbonInterface $due = null, // ближний срок его оплаты нам
    ) {}

    /** Счета, что платит менеджер или его покупатель: без выплат ему и без счёта вендору за вознаграждение. */
    public static function payable($invoices)
    {
        return $invoices->filter(fn (Invoice $i) => ! $i->isOwed() && $i->kind !== ChargeKind::Reward && $i->state === InvoiceState::Issued)
            ->sortBy(fn (Invoice $i) => [$i->due_at, $i->id])->values();
    }

    public static function of(Deal $deal, bool $staff = false): self
    {
        $invoices = $deal->relationLoaded('invoices') ? $deal->invoices : $deal->invoices()->with('claims')->get();
        $issued = $invoices->reject(fn (Invoice $i) => $i->isOwed());
        $open = self::payable($invoices);
        $fee = $invoices->first(fn (Invoice $i) => $i->isAgentFee() && $i->state !== InvoiceState::Void);
        $state = $deal->commissionState();
        // Гаражная сделка цены подтверждения не знает: её счёт (Каркаде, платит менеджер) — от закупочной.
        $label = $deal->isGarage() ? 'В гараж' : 'Цена подтверждения';
        $date = fn ($at) => $at?->translatedFormat('j M');

        if ($deal->state === DealState::Cancelled) {
            return new self('Сделка отменена', null, 'muted', 'closed');
        }
        if ($open->isNotEmpty()) {
            // Число — остаток всех его счетов по сделке (у гаражной ПРАЙМ их два: машина и доля), фраза — по ближнему сроку.
            $first = $open->first();
            $left = round($open->sum(fn (Invoice $i) => $i->remaining()), 2);
            $claimed = round($open->sum(fn (Invoice $i) => $i->claimed()), 2);
            $late = round($open->filter->isOverdue()->sum(fn (Invoice $i) => $i->remaining()), 2);
            $caption = $staff ? 'должен нам' : 'К оплате';
            // Выплату уже выставили, а потом пришёл ещё счёт (хранение) — строка про счёт, но и выплату называет: она в
            // итогах «должны ему», и в строках её должно быть видно.
            $toHim = $fee && $fee->state === InvoiceState::Issued ? $fee->remaining() : 0;
            $also = $toHim > 0 ? ($staff ? ', ему к выплате ' : ', вам к выплате ').Money::rub($toHim) : '';
            $money = ['toUs' => $left, 'overdue' => $late, 'claimed' => $claimed, 'toHim' => $toHim, 'due' => $first->due_at];
            if ($claimed > 0) {
                return new self(($staff ? 'Сообщил об оплате' : 'Оплата ждёт подтверждения').$also, $left, 'muted', 'pay', $caption, ...$money);
            }
            if ($late > 0) {
                return new self('Просрочен на '.$first->overdueDays().' дн'.$also, $left, 'urgent', 'pay', $caption, ...$money);
            }
            $byBuyer = $deal->isPrime() && $deal->buyer && $first->party_id !== $deal->buyer->party_id;
            $phrase = match (true) {
                $first->paidMoney() > 0 => 'Оплачено '.Money::rub($first->paidMoney()).', остаток до ',
                $byBuyer => $staff ? 'Платит покупатель, до ' : 'Покупатель оплачивает до ',
                $staff => 'Оплатит до ',
                $deal->isGarageManager() && $first->kind === ChargeKind::Selection => 'Оплатите долю до ',
                $first->kind === ChargeKind::Selection => 'Оплатите подбор до ',
                default => 'Оплатите до ',
            };

            return new self($phrase.$date($first->due_at).$also, $left, $first->light() === 'urgent' ? 'urgent' : 'plain', 'pay', $caption, ...$money);
        }
        if ($issued->isEmpty() && $deal->isActive() && $deal->invoiceGap() === 'buyer') {
            return $staff
                ? new self('Ждём покупателя', (float) $deal->base(), 'plain', 'open', mb_strtolower($label))
                : new self('Укажите покупателя', (float) $deal->base(), 'urgent', 'open', $label);
        }
        if ($issued->isEmpty()) {
            return $deal->state === DealState::Done
                ? new self('Сделка закрыта', (float) $deal->base(), 'muted', 'closed', $label)
                : new self('Счёт ещё не выставлен', (float) $deal->base(), 'muted', 'open', $label);
        }

        $mine = $staff ? 'вознаграждение' : 'Ваше вознаграждение';
        // Вознаграждение — его прибыль: оставленное себе (ДКП, страховой, удержал из счёта) — зелёным.
        if (($deal->paysSelection() || $deal->withholds()) && $deal->commission) {
            return new self($staff ? 'Оставил себе' : 'Оставили себе', (float) $deal->commission, 'profit', 'closed', $mine);
        }

        return match ($state) {
            CommissionState::Payable => $staff
                ? new self('Выплатить до '.$date($fee->due_at), $fee->remaining(), 'accent', 'payout', 'должны ему', toHim: $fee->remaining())
                : new self('К выплате до '.$date($fee->due_at), $fee->remaining(), 'accent', 'payout', 'Вам к выплате', toHim: $fee->remaining()),
            CommissionState::Paid => new self(($staff ? 'Выплатили ' : 'Выплачено ').$date($fee->paid_at), (float) $deal->commission, 'muted', 'closed', $mine),
            // Его счета оплачены, выплата ещё не выставлена (ждёт счёт вендора или очередь) — это не «закрыто».
            CommissionState::Awaiting => new self($staff ? 'Выплата после оплаты всех счетов' : 'Выплатим после оплаты всех счетов', (float) $deal->commission, 'muted', 'payout', $mine),
            default => new self('Счёт оплачен', (float) $deal->base(), 'muted', 'closed', $label),
        };
    }

    /**
     * Расчёт машины в гараже строкой «Денег»: счёт «отдать нам» или выплата менеджеру — только его; счёт его
     * покупателю менеджеру не платить. Продали в минус — в `invoice_id` лежит наша выплата (`owed`), а не его счёт.
     * Нечего показать — null.
     */
    public static function garage(GarageCar $car, User $manager, bool $staff = false): ?self
    {
        $mine = fn (?Invoice $i) => $i && $i->state !== InvoiceState::Void && $manager->party_id && $i->party_id === $manager->party_id;
        $invoice = $mine($car->invoice) ? $car->invoice : null;
        $payout = $mine($car->payoutInvoice) ? $car->payoutInvoice : ($invoice?->isOwed() ? $invoice : null);
        $invoice = $invoice?->isOwed() ? null : $invoice;
        $date = fn ($at) => $at?->translatedFormat('j M');
        if ($invoice && $invoice->state === InvoiceState::Issued) {
            $left = $invoice->remaining();
            $caption = $staff ? 'должен нам' : 'Отдать нам';
            $money = ['toUs' => $left, 'overdue' => $invoice->isOverdue() ? $left : 0, 'claimed' => $invoice->claimed(), 'due' => $invoice->due_at];

            return match (true) {
                $invoice->claimed() > 0 => new self($staff ? 'Сообщил об оплате' : 'Оплата ждёт подтверждения', $left, 'muted', 'pay', $caption, ...$money),
                $invoice->isOverdue() => new self('Просрочен на '.$invoice->overdueDays().' дн', $left, 'urgent', 'pay', $caption, ...$money),
                default => new self(($staff ? 'Оплатит до ' : 'Оплатите до ').$date($invoice->due_at), $left, $invoice->light() === 'urgent' ? 'urgent' : 'plain', 'pay', $caption, ...$money),
            };
        }
        if ($payout) {
            return $payout->state === InvoiceState::Issued
                ? new self(($staff ? 'Выплатить до ' : 'К выплате до ').$date($payout->due_at), $payout->remaining(), 'accent', 'payout', $staff ? 'должны ему' : 'Вам к выплате', toHim: $payout->remaining())
                : new self(($staff ? 'Выплатили ' : 'Выплачено ').$date($payout->paid_at), (float) $payout->total, 'muted', 'closed', $staff ? 'выплатили' : 'Вам выплачено');
        }

        return $invoice ? new self('Оплачено', (float) $invoice->total, 'muted', 'closed', $staff ? 'оплатил' : 'Отдали нам') : null;
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
                // Оставил себе — когда счёт, из которого он его вычел, оплачен; до того шаг впереди, без даты.
                CommissionState::Withheld => $issued->every(fn (Invoice $i) => $i->state !== InvoiceState::Issued)
                    ? $step('Оставили себе вознаграждение', 'done', $issued->max('paid_at'))
                    : $step('Вознаграждение', 'todo', null, 'Оставите себе при оплате'),
                CommissionState::Paid => $step('Вознаграждение выплачено', 'done', $fee?->paid_at),
                CommissionState::Payable => $step('Выплата вознаграждения', 'current', null, 'До '.$fee?->due_at->translatedFormat('j M'), 'accent'),
                default => $step('Выплата вознаграждения', 'todo'),
            };
        }

        return $steps;
    }

    public function needsAction(): bool
    {
        // «Укажите покупателя» — тоже его ход, наверх вместе с «Оплатить».
        return ($this->preset === 'pay' && $this->tone !== 'muted') || ($this->preset === 'open' && $this->tone === 'urgent');
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
